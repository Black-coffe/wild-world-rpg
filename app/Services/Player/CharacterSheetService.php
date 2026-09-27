<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Services\Onboarding\PolarStarService;
use App\Services\Player\Progression\LevelProgressService;
use App\Services\Player\Progression\LevelUnlockService;
use App\Services\PVE\TributeService;
use App\Services\Quest\DailyTaskService;
use App\Services\Tasks\ActiveTasksService;
use App\Services\Telegram\BotMenuService;
use App\Services\World\MarchService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use DateTime;

/**
 * W2.N1-01 (ADR-190) — модель экрана персонажа: одно ядро, два рендерера.
 *
 * Отдаёт данные карточки «Я» и HUD без `Request`, `chat_id` и разметки клиента. Карточку бота
 * рисует {@see CharacterService::showCharacterInfo()}, нативный экран «Я» и HUD сайта —
 * `site/_play/native_me` и `site/_play/hud`. Оба клиента читают одни и те же поля.
 *
 * W2.N2-03: HUD сайта ({@see hud()}) несёт и идущий Поход — {@see MarchService::status()}.
 *
 * Сырые значения статов (`level`, `experience`, `health`…) хранятся строкой ровно так, как их
 * печатала карточка бота до рефакторинга: так бот остаётся байт-в-байт прежним. Строки чужих
 * сервисов (полярная звезда, лестница уровня, раны, серия) — готовые фразы в legacy-Markdown
 * Telegram, веб переводит их в HTML тем же `TelegramMarkupRenderer`, что и мост.
 *
 * @phpstan-import-type Status from MarchService as MarchStatus
 * @phpstan-type Hud array{health:string, tired:string, gold:int, level:int, experience:string, level_percent:?int, next_level:?int, cell:?array{x:int, y:int}, biome:?string, task:?array{name:string, ends_at:?int}, tasks_more:int, march?:?MarchStatus}
 * @phpstan-type Action array{id:string, label:string, callback?:string, url?:string}
 * @phpstan-type Sheet array{
 *     id:int, name:string, faction:?string, cell:?array{x:int, y:int}, biome:string,
 *     explored:int, resource_kinds:int, time_in_game:string,
 *     level:string, experience:string, agility:string, intellect:string, strength:string,
 *     health:string, tired:string, trading_karma:string, gold:int,
 *     polar_line:?string, ladder_line:?string, unlock_line:?string, debuffs:list<string>,
 *     streak_line:?string, milestone_line:?string, title:?string,
 *     armor:?string, weapon:?string, specialization:?string, drone:?array{minutes:int, bonus:int},
 *     personal_actions:list<Action>, tail_actions:list<Action>, hud:Hud
 * }
 */
class CharacterSheetService
{
    /** Фракция «без фракции» — выбор ещё не сделан (N4, ADR-039). */
    private const NO_FACTION_ID = 5;

    /** Уровень, с которого открывается выбор фракции (N4, ADR-039). */
    private const FACTION_LEVEL = 10;

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    /** @param BaseConnection<object, object>|null $db */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Полная модель по id персонажа; null — персонажа нет.
     *
     * @return Sheet|null
     */
    public function forCharacter(int $characterId): ?array
    {
        $row = (new CharacterModel())->find($characterId);

        return is_array($row) || $row instanceof CharacterEntity ? $this->fromRow($row) : null;
    }

    /**
     * Полная модель из уже прочитанной строки персонажа (карточка бота получает строку готовой).
     *
     * @param array<int|string, mixed>|CharacterEntity $row
     *
     * @return Sheet
     */
    public function fromRow(array|CharacterEntity $row): array
    {
        $charId = self::int($row['id'] ?? 0);
        [$cell, $biomeName] = $this->location($row['cell_number'] ?? null);

        $charFaction = $this->first('SELECT faction_id, joined_at FROM character_factions WHERE character_id = ? ORDER BY id LIMIT 1', [$charId]);
        $faction     = null;
        if ($charFaction !== null) {
            $factionRow = $this->first('SELECT name FROM factions WHERE id = ? LIMIT 1', [self::int($charFaction['faction_id'] ?? 0)]);
            $faction    = self::nonEmpty($factionRow['name'] ?? null);
        }

        // Лестница уровня (gated progression.ladder.enabled): строка + что откроет следующий уровень.
        $ladderLine = (new LevelProgressService())->cardLine(self::statRow($row));
        $unlockLine = null;
        if ($ladderLine !== null) {
            $nextLevel  = LevelProgressService::levelForSum(LevelProgressService::statSum(self::statRow($row))) + 1;
            $unlockLine = (new LevelUnlockService())->summaryFor($nextLevel);
        }

        $debuffService = new DebuffService();
        $debuffs       = [];
        foreach ($debuffService->active($charId) as $debuffRow) {
            $line = $debuffService->describe($debuffRow);
            if ($line !== '') {
                $debuffs[] = $line;
            }
        }

        $titleSvc = new TitleService();
        $title    = $titleSvc->enabled() ? $titleSvc->activeTitleLabel($charId) : null;

        $specSvc        = new SpecializationService();
        $specialization = null;
        if ($specSvc->isEnabled()) {
            $specRaw        = $row['specialization'] ?? null;
            $specialization = $specSvc->labelFor(is_string($specRaw) ? $specRaw : null);
        }

        $level = self::int($row['level'] ?? 0);

        return [
            'id'               => $charId,
            'name'             => self::sanitizeName(self::str($row['name'] ?? '')),
            'faction'          => $faction,
            'cell'             => $cell,
            'biome'            => $biomeName ?? '???',
            'explored'         => $this->count('SELECT COUNT(*) AS n FROM explored_cells WHERE character_id = ?', $charId),
            'resource_kinds'   => $this->count('SELECT COUNT(*) AS n FROM character_resources WHERE id_characters = ?', $charId),
            'time_in_game'     => self::timeInGame($row['created_at'] ?? null),
            'level'            => self::str($row['level'] ?? ''),
            'experience'       => self::str($row['experience'] ?? ''),
            'agility'          => self::str($row['agility'] ?? ''),
            'intellect'        => self::str($row['intellect'] ?? ''),
            'strength'         => self::str($row['strength'] ?? ''),
            'health'           => self::str($row['health'] ?? ''),
            'tired'            => self::str($row['tired'] ?? ''),
            'trading_karma'    => self::str($row['trading_karma'] ?? ''),
            'gold'             => self::int($row['gold'] ?? 0),
            'polar_line'       => (new PolarStarService())->line($charId),
            'ladder_line'      => $ladderLine,
            'unlock_line'      => $unlockLine,
            'debuffs'          => $debuffs,
            'streak_line'      => (new LoginStreakService())->streakLine(self::statRow($row)),
            'milestone_line'   => (new StreakMilestoneService())->cardProgressLine($charId, self::int($row['login_streak'] ?? 0)),
            'title'            => $title,
            'armor'            => $this->equippedArmor($charId),
            'weapon'           => $this->equippedWeapon($charId),
            'specialization'   => $specialization,
            'drone'            => $this->drone($row['combat_drone_active_until'] ?? null),
            'personal_actions' => self::personalActions(),
            'tail_actions'     => $this->tailActions($charId, $level, $charFaction),
            'hud'              => $this->hudFromRow($row, $cell, $biomeName),
        ];
    }

    /**
     * Только HUD — дешёвый срез для каждого ответа `/play` (действие, экран, опрос входящих).
     *
     * @return Hud|null
     */
    public function hud(int $characterId): ?array
    {
        $row = $this->first(
            'SELECT id, health, tired, gold, level, experience, strength, agility, intellect, cell_number FROM characters WHERE id = ? LIMIT 1',
            [$characterId]
        );
        if ($row === null) {
            return null;
        }
        [$cell, $biomeName] = $this->location($row['cell_number'] ?? null);

        $hud          = $this->hudFromRow($row, $cell, $biomeName);
        $hud['march'] = (new MarchService())->status($characterId);

        return $hud;
    }

    /**
     * @param array<int|string, mixed>|CharacterEntity $row
     * @param array{x:int, y:int}|null                 $cell
     *
     * @return Hud
     */
    private function hudFromRow(array|CharacterEntity $row, ?array $cell, ?string $biomeName): array
    {
        $ladder  = new LevelProgressService();
        $percent = null;
        $next    = null;
        if ($ladder->isEnabled()) {
            $sum     = LevelProgressService::statSum(self::statRow($row));
            $percent = LevelProgressService::percentToNext($sum);
            $next    = LevelProgressService::levelForSum($sum) + 1;
        }

        return self::buildHud(
            [
                'health'     => self::str($row['health'] ?? ''),
                'tired'      => self::str($row['tired'] ?? ''),
                'gold'       => self::int($row['gold'] ?? 0),
                'level'      => self::int($row['level'] ?? 0),
                'experience' => self::str($row['experience'] ?? ''),
            ],
            $percent,
            $next,
            $cell,
            $biomeName,
            $this->activeTasks(self::int($row['id'] ?? 0))
        );
    }

    /**
     * Активные задачи для HUD: только имя и срок (тот же отбор `in_work`, что у
     * {@see ActiveTasksService::getActiveTasksWithDetails()}).
     *
     * @return list<array<string, mixed>>
     */
    private function activeTasks(int $characterId): array
    {
        $res = $this->db->query(
            'SELECT t.name_rus, t.name, ct.end_time FROM character_tasks ct LEFT JOIN tasks t ON t.id = ct.task_id'
            . " WHERE ct.character_id = ? AND ct.status = 'in_work' ORDER BY ct.id",
            [$characterId]
        );

        return $res instanceof ResultInterface ? array_values($res->getResultArray()) : [];
    }

    /**
     * Сборка HUD из готовых данных — чистая функция (тестируется без БД).
     *
     * @param array{health:string, tired:string, gold:int, level:int, experience:string} $stats
     * @param array{x:int, y:int}|null $cell
     * @param array<mixed>             $activeTasks строки {@see ActiveTasksService::getActiveTasksWithDetails()}
     *
     * @return Hud
     */
    public static function buildHud(array $stats, ?int $percent, ?int $nextLevel, ?array $cell, ?string $biome, array $activeTasks): array
    {
        $task  = null;
        $count = 0;
        foreach ($activeTasks as $t) {
            if (! is_array($t)) {
                continue;
            }
            $count++;
            $name   = self::nonEmpty($t['name_rus'] ?? null) ?? self::nonEmpty($t['name'] ?? null) ?? 'Задача';
            $end    = is_string($t['end_time'] ?? null) && $t['end_time'] !== '' ? strtotime($t['end_time']) : false;
            $endsAt = $end === false ? null : $end;
            // Показываем ту, что кончится раньше всех; задачи без срока — после всех со сроком.
            if ($task === null
                || ($endsAt !== null && ($task['ends_at'] === null || $endsAt < $task['ends_at']))) {
                $task = ['name' => $name, 'ends_at' => $endsAt];
            }
        }

        return $stats + [
            'level_percent' => $percent,
            'next_level'    => $nextLevel,
            'cell'          => $cell,
            'biome'         => $biome,
            'task'          => $task,
            'tasks_more'    => max(0, $count - 1),
        ];
    }

    /**
     * Число для экрана: `100.00` → `100`, `87.50` → `87.5`. Бот печатает сырое значение, веб — это.
     */
    public static function displayNumber(string $raw): string
    {
        if (! is_numeric($raw)) {
            return $raw;
        }
        $formatted = number_format((float) $raw, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /** @return list<Action> */
    private static function personalActions(): array
    {
        return [
            ['id' => 'actions',   'label' => '🧑‍🌾 Действия 🛠️', 'callback' => 'characterActions'],
            ['id' => 'inventory', 'label' => '🎒 Инвентарь',      'callback' => 'inventory'],
            ['id' => 'gear',      'label' => '⚔️ Экип',           'callback' => 'equipMenu'],
            ['id' => 'pharmacy',  'label' => '💊 Аптечка',        'callback' => 'pharmacy'],
            ['id' => 'insurance', 'label' => '🧍 Страховка',      'callback' => 'PersonalInsurance'],
        ];
    }

    /**
     * Хвост карточки в порядке частоты: транспорт → фракция → задания дня → хабы → подать →
     * справочник → реферал → профиль. Каждая кнопка — только когда её фича доступна.
     *
     * @param array<string, mixed>|null $charFaction
     *
     * @return list<Action>
     */
    private function tailActions(int $charId, int $level, ?array $charFaction): array
    {
        // transport-10 (ADR-174): вход виден всегда, экран сам объясняет витрину.
        $tail = [['id' => 'vehicle', 'label' => '🚚 Мой транспорт', 'callback' => 'vehicleScreen']];

        $hasChosenFaction = $charFaction !== null
            && self::int($charFaction['faction_id'] ?? 0) !== self::NO_FACTION_ID
            && ! empty($charFaction['joined_at']);
        if ($level >= self::FACTION_LEVEL && ! $hasChosenFaction) {
            $tail[] = ['id' => 'faction', 'label' => '⚑ Выбрать фракцию', 'callback' => 'chooseFaction_info'];
        } elseif ($level < self::FACTION_LEVEL && ! $hasChosenFaction) {
            // E7: lock-кнопка до L10 — цель видна заранее (UX-DISCOVERABILITY).
            $tail[] = ['id' => 'faction_locked', 'label' => '🔒 ⚑ Фракция (с lvl 10)', 'callback' => 'chooseFactionLocked'];
        }

        if ((new DailyTaskService())->enabled()) {
            $tail[] = ['id' => 'daily', 'label' => '🗓 Задания дня', 'callback' => 'dailyTasks'];
        }
        if (ProfileHubService::progressButtons() !== []) {
            $tail[] = ['id' => 'progress_hub', 'label' => ProfileHubService::HUB_PROGRESS_LABEL, 'callback' => 'progressHub'];
        }
        if (ProfileHubService::developmentButtons($charId, $level) !== []) {
            $tail[] = ['id' => 'development_hub', 'label' => ProfileHubService::HUB_DEVELOPMENT_LABEL, 'callback' => 'developmentHub'];
        }

        // ADR-135: подать — только у того, у кого она реально есть (live-vs-dormant honesty).
        $tributeSvc = new TributeService();
        if ($tributeSvc->enabled() && $tributeSvc->hasAnyTributeRelation($charId)) {
            $tail[] = ['id' => 'tribute', 'label' => '⚖️ Трофейная подать', 'callback' => 'tributeStatus'];
        }

        // ADR-127: точка спасения — всегда.
        $tail[] = ['id' => 'guide', 'label' => '📖 Путь новичка', 'callback' => 'guide'];

        // ADR-150 ФИНАЛ: реферал живёт в «⚙️ Ещё», на карточке — только в legacy-сетке.
        if (! BotMenuService::finalGridEnabled() && (new ReferralService())->enabled()) {
            $tail[] = ['id' => 'referral', 'label' => '👥 Позови выжившего', 'callback' => 'referral'];
        }

        // E30: публичный профиль наружу (flat ADR-062).
        if ($charId > 0) {
            $tail[] = ['id' => 'profile', 'label' => '🔗 Мой профиль', 'url' => base_url('profile/' . $charId)];
        }

        return $tail;
    }

    /**
     * @return array{0: array{x:int, y:int}|null, 1: string|null} координаты клетки и имя биома
     */
    private function location(mixed $cellNumber): array
    {
        if (! is_numeric($cellNumber)) {
            return [null, null];
        }
        $cell = $this->first('SELECT coordinate_x, coordinate_y, biome_id FROM map WHERE cell_number = ? LIMIT 1', [(int) $cellNumber]);
        if ($cell === null) {
            return [null, null];
        }
        $biome = $this->first('SELECT name FROM biomes WHERE id = ? LIMIT 1', [self::int($cell['biome_id'] ?? 0)]);

        return [
            ['x' => self::int($cell['coordinate_x'] ?? 0), 'y' => self::int($cell['coordinate_y'] ?? 0)],
            is_string($biome['name'] ?? null) ? $biome['name'] : null,
        ];
    }

    /** @return array{minutes:int, bonus:int}|null активный боевой дрон (W5, ADR-064) */
    private function drone(mixed $activeUntil): ?array
    {
        $droneSvc = new DroneService();
        if (! $droneSvc->combatIsEnabled()) {
            return null;
        }
        $ts = is_string($activeUntil) && $activeUntil !== '' ? strtotime($activeUntil) : 0;
        if ($ts === false || $ts <= time()) {
            return null;
        }

        return [
            'minutes' => max(1, (int) ceil(($ts - time()) / 60)),
            'bonus'   => $droneSvc->combatInitiativeBonusPercent(),
        ];
    }

    private function equippedWeapon(int $characterId): ?string
    {
        $row = $this->first(
            'SELECT w.name FROM characters_weapons cw LEFT JOIN weapons w ON w.id = cw.weapon_id'
            . ' WHERE cw.character_id = ? AND cw.equipped = 1 ORDER BY cw.id LIMIT 1',
            [$characterId]
        );

        return is_string($row['name'] ?? null) ? $row['name'] : null;
    }

    /** Все надетые предметы брони через запятую. */
    private function equippedArmor(int $characterId): ?string
    {
        $res = $this->db->query(
            'SELECT o.name FROM characters_outfits co JOIN outfits o ON o.id = co.outfit_id'
            . ' WHERE co.character_id = ? AND co.equipped = 1 ORDER BY co.id',
            [$characterId]
        );
        $names = [];
        foreach ($res instanceof ResultInterface ? $res->getResultArray() : [] as $r) {
            if (is_string($r['name'] ?? null)) {
                $names[] = $r['name'];
            }
        }

        return $names !== [] ? implode(', ', $names) : null;
    }

    /**
     * @param list<int|string> $binds
     *
     * @return array<string, mixed>|null
     */
    private function first(string $sql, array $binds): ?array
    {
        $res = $this->db->query($sql, $binds);
        $row = $res instanceof ResultInterface ? $res->getRowArray() : null;

        return is_array($row) ? $row : null;
    }

    private function count(string $sql, int $characterId): int
    {
        return self::int($this->first($sql, [$characterId])['n'] ?? 0);
    }

    /**
     * Строка персонажа для сервисов, принимающих `array<string, mixed>|CharacterEntity`.
     *
     * @param array<int|string, mixed>|CharacterEntity $row
     *
     * @return array<string, mixed>|CharacterEntity
     */
    private static function statRow(array|CharacterEntity $row): array|CharacterEntity
    {
        if ($row instanceof CharacterEntity) {
            return $row;
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function timeInGame(mixed $createdAt): string
    {
        // v0.51.121: CI4 Entity отдаёт created_at объектом Time.
        $createdAtStr = $createdAt instanceof \DateTimeInterface
            ? $createdAt->format('Y-m-d H:i:s')
            : (is_scalar($createdAt) ? (string) $createdAt : '1970-01-01');

        return (new DateTime($createdAtStr))->diff(new DateTime())->format('%m мес. %d дн. %h чс.');
    }

    private static function sanitizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Zа-яА-ЯёЁґҐєЄїЇ0-9 ]/u', '', str_replace(['_', '-'], ' ', $name)) ?? '';
    }

    private static function nonEmpty(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Строка так, как её печатала интерполяция `"{$row['x']}"` в карточке бота. */
    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
