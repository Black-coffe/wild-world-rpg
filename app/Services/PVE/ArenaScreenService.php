<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\BattleLogModel;
use App\Models\BiomeModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use App\Models\FactionModel;
use App\Models\MapModel;
use App\Models\OutfitModel;
use App\Models\TelegramUserModel;
use App\Models\WeaponModel;
use App\Services\Telegram\Request;
use App\Services\Telegram\TelegramBridge;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseResult;
use Closure;
use Config\Database;
use Config\Services;

/**
 * w2-n7-combat-02 (ADR-190) — нейтральное ядро арены дуэлей (ADR-071/072/073/124): ростер, вызов,
 * рейтинг PvP и тумблер «открыт к дуэлям». Бот (`ArenaAction`, `DuelAction`, `PvpLadderAction`,
 * `SettingsAction`) и веб (`/play?view=arena|ladder`) рисуют одно и то же из этих моделей.
 *
 * Вызов (`challenge()`) — бывшее тело `DuelAction::handle()` без транспорта: те же гейты и тексты
 * отказов, уравнивание, неизменный `simulateFight`, тай-брейк, очки рейтинга. Новое:
 *  - дуэль пишется в журнал боёв строкой `battle_logs.battle_type='DUEL'` (обоим видна в «📜 Мои бои»),
 *    `log_data` — `PvpBattleLogBuilder` плюс пометка `duel: true` («дуэль, без потерь») и исход тай-брейка;
 *  - анти-спам кулдаун `pvp.attack_cooldown_sec` проверяется и берётся под именованной блокировкой
 *    MySQL на вызывающего: два одновременных вызова (двойной тап, веб + бот) проводят одну дуэль —
 *    второй ждёт первого и получает «подожди N сек.» без боя и без записи;
 *  - защитник получает итог с кнопкой «📜 Разбор боя». Отправка идёт через `Request`, поэтому
 *    веб-игрок без Telegram получает её во входящие (`WebDelivery`).
 *
 * Здоровье, опыт и ресурсы бойцов дуэль по-прежнему не трогает.
 *
 * @phpstan-type Fighter array{id: int, name: string, level: int, pts: int}
 * @phpstan-type LadderRow array{name: string, points: int, duel_wins: int, pvp_wins: int}
 * @phpstan-type Arena array{enabled: bool, lock: string, self_open: bool, roster: list<Fighter>, ladder_enabled: bool}
 * @phpstan-type Ladder array{enabled: bool, lock: string, faction_id: int|null, faction_name: string, my_faction: int, rows: list<LadderRow>, my: array{rank: int, points: int, duel_wins: int, pvp_wins: int}|null}
 * @phpstan-type Duel array{ok: true, battle_id: int|null, attacker_name: string, defender_name: string, winner_id: int, winner_name: string, reason: string, rounds: int, text: string}
 * @phpstan-type Refusal array{ok: false, code: string, message: string}
 */
final class ArenaScreenService
{
    public const ROSTER_LIMIT = 12;

    /**
     * Сколько секунд второй одновременный вызов ждёт первого, прежде чем отказать «дуэль уже идёт».
     * Инфраструктурный таймаут блокировки (бой считается доли секунды), не баланс.
     */
    public const LOCK_WAIT_SEC = 3;

    public const CODE_DISABLED  = 'disabled';
    public const CODE_NO_TARGET = 'no_target';
    public const CODE_SELF      = 'self';
    public const CODE_NOT_OPEN  = 'not_open';
    public const CODE_TOO_FAR   = 'too_far';
    public const CODE_COOLDOWN  = 'cooldown';
    public const CODE_BUSY      = 'busy';
    public const CODE_NO_PLACE  = 'no_place';

    /** Замки выключенных разделов — объяснение для экрана, а не пустое «недоступно». */
    public const LOCK_ARENA  = 'Арена сейчас закрыта: дуэли выключены администрацией. Журнал боёв работает как обычно.';
    public const LOCK_LADDER = 'Рейтинг PvP временно недоступен: раздел отключён администрацией.';

    private DuelService $duels;
    private PvpLadderService $ladder;
    private CacheInterface $cache;
    private int $lockWaitSec;

    /** @var Closure(array<string,mixed>, array<string,mixed>, int): (array{result: array<string,mixed>, biome: string|null}|null) */
    private Closure $fight;

    /**
     * @param (Closure(array<string,mixed>, array<string,mixed>, int): (array{result: array<string,mixed>, biome: string|null}|null))|null $fight
     *        бой по уравненным бойцам и клетке вызывающего; по умолчанию — неизменный `simulateFight`
     */
    public function __construct(
        ?DuelService $duels = null,
        ?PvpLadderService $ladder = null,
        ?CacheInterface $cache = null,
        ?Closure $fight = null,
        int $lockWaitSec = self::LOCK_WAIT_SEC
    ) {
        $this->duels       = $duels ?? new DuelService();
        $this->ladder      = $ladder ?? new PvpLadderService();
        $this->cache       = $cache ?? Services::cache();
        $this->fight       = $fight ?? Closure::fromCallable([$this, 'realFight']);
        $this->lockWaitSec = max(0, $lockWaitSec);
    }

    public function duelsEnabled(): bool
    {
        return $this->duels->enabled();
    }

    public function ladderEnabled(): bool
    {
        return $this->ladder->enabled();
    }

    /**
     * Модель арены. Выключенные дуэли — замок с объяснением и пустой ростер.
     *
     * @param array<string,mixed>|object $character
     *
     * @return Arena
     */
    public function arena(array|object $character): array
    {
        $enabled = $this->duels->enabled();

        return [
            'enabled'        => $enabled,
            'lock'           => $enabled ? '' : self::LOCK_ARENA,
            'self_open'      => self::duelsOpenFlag($character) === 1,
            'roster'         => $enabled ? $this->roster(self::idOf($character)) : [],
            'ladder_enabled' => $this->ladder->enabled(),
        ];
    }

    /**
     * Бойцы, открытые к дуэлям (кроме себя): по убыванию очков рейтинга, затем уровня.
     *
     * @return list<Fighter>
     */
    public function roster(int $selfId): array
    {
        $q = Database::connect()->table('characters c')
            ->select('c.id, c.name, c.level, COALESCE(l.points, 0) AS pts')
            ->join('pvp_ladder l', 'l.character_id = c.id', 'left')
            ->where('c.duels_open', 1)
            ->where('c.id !=', $selfId)
            ->orderBy('pts', 'DESC')
            ->orderBy('c.level', 'DESC')
            ->limit(self::ROSTER_LIMIT)
            ->get();
        if ($q === false) {
            return [];
        }
        $out = [];
        foreach ($q->getResultArray() as $r) {
            $id = is_numeric($r['id'] ?? null) ? (int) $r['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id'    => $id,
                'name'  => is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : ('№' . $id),
                'level' => is_numeric($r['level'] ?? null) ? (int) $r['level'] : 1,
                'pts'   => is_numeric($r['pts'] ?? null) ? (int) $r['pts'] : 0,
            ];
        }

        return $out;
    }

    /**
     * Вызов на дуэль: с арены (`$isArena`, без смежности) или в поле (`duel_<id>`, соседняя клетка).
     *
     * @param array<string,mixed>|object $attacker
     *
     * @return Duel|Refusal
     */
    public function challenge(array|object $attacker, int $defenderId, bool $isArena): array
    {
        if (! $this->duels->enabled()) {
            return self::refuse(self::CODE_DISABLED, 'Дуэли сейчас недоступны.');
        }
        if ($defenderId <= 0) {
            return self::refuse(self::CODE_NO_TARGET, 'Не указан соперник.');
        }
        $defender = (new CharacterModel())->find($defenderId);
        if (! $defender) {
            return self::refuse(self::CODE_NO_TARGET, 'Соперник не найден.');
        }
        $attackerId = self::idOf($attacker);
        if ($attackerId === $defenderId) {
            return self::refuse(self::CODE_SELF, 'Нельзя вызвать на дуэль самого себя.');
        }
        if (self::duelsOpenFlag($defender) !== 1) {
            return self::refuse(self::CODE_NOT_OPEN, 'Этот игрок не открыт для дуэлей. Открыться можно в ⚙️ Настройках.');
        }
        $attackerArr = self::toArray($attacker);
        $defenderArr = self::toArray($defender);
        if (! $isArena && ! $this->cellsClose($attackerArr, $defenderArr)) {
            return self::refuse(self::CODE_TOO_FAR, 'Соперник слишком далеко — дуэль только в одной/соседней клетке.');
        }

        // Кулдаун и бой — под блокировкой вызывающего: проверка и взятие кулдауна атомарны между
        // параллельными вызовами. Второй ждёт первого и упирается в уже взятый кулдаун.
        $db   = Database::connect();
        $lock = 'ww-duel-' . $db->getDatabase() . '-' . $attackerId;
        $got  = $db->query('SELECT GET_LOCK(?, ?) AS l', [$lock, $this->lockWaitSec]);
        $row  = $got instanceof BaseResult ? $got->getRowArray() : null;
        if (! is_array($row) || ! is_numeric($row['l'] ?? null) || (int) $row['l'] !== 1) {
            return self::refuse(self::CODE_BUSY, 'Подожди — твоя дуэль уже идёт.');
        }

        try {
            $cooldownSec = $this->duels->cooldownSec();
            $cacheKey    = 'pvp_duel_cd_' . $attackerId;
            $last        = $this->cache->get($cacheKey);
            if (is_int($last) && time() - $last < $cooldownSec) {
                return self::refuse(self::CODE_COOLDOWN, 'Подожди ' . ($cooldownSec - (time() - $last)) . ' сек. перед следующей дуэлью.');
            }
            $this->cache->save($cacheKey, time(), $cooldownSec);

            // Уравнивание обоих (билд остаётся) → неизменный simulateFight, null defense (спорт).
            // Площадка одна — клетка вызывающего у обоих: дистанция между реальными клетками урон не режет.
            $cell                     = is_numeric($attackerArr['cell_number'] ?? null) ? (int) $attackerArr['cell_number'] : 0;
            [$eqAttacker, $eqDefender] = $this->duels->prepare($attackerArr, $defenderArr);
            $fought                   = ($this->fight)($eqAttacker, $eqDefender, $cell);
            if ($fought === null) {
                return self::refuse(self::CODE_NO_PLACE, 'Не найдена локация.');
            }
            $result = $fought['result'];

            // ADR-073: тай-брейк после боя (fence-safe), ничьей нет.
            $resolution = $this->duels->resolveDuel($result, $attackerArr, $defenderArr);
            $winnerId   = $resolution['winnerId'];
            $loserId    = $resolution['loserId'];
            // ADR-072: очки рейтинга после боя (при выключенном рейтинге — no-op внутри).
            if ($winnerId > 0 && $loserId > 0) {
                $this->ladder->recordDuel($winnerId, $loserId, false);
            }

            $aName      = self::nameOf($attackerArr, $attackerId);
            $dName      = self::nameOf($defenderArr, $defenderId);
            $winnerName = $winnerId === $attackerId ? $aName : $dName;
            $rounds     = is_numeric($result['rounds'] ?? null) ? (int) $result['rounds'] : 0;
            $battleId   = $this->writeLog($eqAttacker, $eqDefender, $result, $fought['biome'], $resolution);
            $text       = self::resultText($rounds, $resolution['reason'], $winnerName, $aName, $dName);

            $this->notifyDefender($defenderArr, $text, $battleId);

            return [
                'ok'            => true,
                'battle_id'     => $battleId,
                'attacker_name' => $aName,
                'defender_name' => $dName,
                'winner_id'     => $winnerId,
                'winner_name'   => $winnerName,
                'reason'        => $resolution['reason'],
                'rounds'        => $rounds,
                'text'          => $text,
            ];
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Рейтинг PvP: глобальный или фракции, плюс моя позиция (по глобальному рейтингу).
     *
     * @return Ladder
     */
    public function ladder(int $characterId, ?int $factionId): array
    {
        $enabled = $this->ladder->enabled();
        $rows    = [];
        $my      = null;
        if ($enabled) {
            $topN = $this->ladder->broadcastTopN();
            $raw  = $factionId !== null ? $this->ladder->topByFaction($factionId, $topN) : $this->ladder->topGlobal($topN);
            foreach ($raw as $r) {
                $rows[] = [
                    'name'      => is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : ('№' . (is_numeric($r['character_id'] ?? null) ? (int) $r['character_id'] : '?')),
                    'points'    => is_numeric($r['points'] ?? null) ? (int) $r['points'] : 0,
                    'duel_wins' => is_numeric($r['duel_wins'] ?? null) ? (int) $r['duel_wins'] : 0,
                    'pvp_wins'  => is_numeric($r['pvp_wins'] ?? null) ? (int) $r['pvp_wins'] : 0,
                ];
            }

            $myRow = $characterId > 0 ? $this->ladder->rowOf($characterId) : null;
            if (is_array($myRow) && is_numeric($myRow['points'] ?? null) && (int) $myRow['points'] > 0) {
                $my = [
                    'rank'      => $this->ladder->rankOf($characterId),
                    'points'    => (int) $myRow['points'],
                    'duel_wins' => is_numeric($myRow['duel_wins'] ?? null) ? (int) $myRow['duel_wins'] : 0,
                    'pvp_wins'  => is_numeric($myRow['pvp_wins'] ?? null) ? (int) $myRow['pvp_wins'] : 0,
                ];
            }
        }

        return [
            'enabled'      => $enabled,
            'lock'         => $enabled ? '' : self::LOCK_LADDER,
            'faction_id'   => $factionId,
            'faction_name' => $enabled && $factionId !== null ? self::factionName($factionId) : '',
            'my_faction'   => $enabled && $characterId > 0 ? (new CharacterFactionModel())->getFactionId($characterId) : 0,
            'rows'         => $rows,
            'my'           => $my,
        ];
    }

    /**
     * Тумблер «открыт к дуэлям» — одна запись для бота (⚙️ Настройки, арена) и веба.
     *
     * @return bool менялось ли значение
     */
    public function setDuelsOpen(int $characterId, bool $open): bool
    {
        if ($characterId <= 0) {
            return false;
        }
        $db = Database::connect();
        $db->table('characters')->where('id', $characterId)->update(['duels_open' => $open ? 1 : 0]);

        return $db->affectedRows() > 0;
    }

    /**
     * Итог дуэли (HTML) — одинаковый у вызвавшего и у защитника; веб рисует из полей модели сам.
     * W18.5 (ADR-073): победитель всегда назван, с причиной.
     */
    public static function resultText(int $rounds, string $reason, string $winnerName, string $aName, string $dName): string
    {
        $w    = self::esc($winnerName);
        $head = "🤺 <b>Дуэль (равный бой)</b>\n"
            . self::esc($aName) . ' ⚔️ ' . self::esc($dName) . "\n"
            . "🔁 Обменов ударами: {$rounds}\n\n";
        $body = match ($reason) {
            'knockout'  => "🏆 Победитель: <b>{$w}</b> (нокаут)",
            'hp'        => "🏆 Победа по очкам: <b>{$w}</b>\n<i>Осталось больше здоровья.</i>",
            'build'     => "🏆 Победа по очкам: <b>{$w}</b>\n<i>Крепче снаряжение.</i>",
            'seniority' => "🏆 Победа по очкам: <b>{$w}</b>\n<i>Больше времени в Пустоши.</i>",
            default     => "🏆 Победитель: <b>{$w}</b>",
        };

        return $head . $body . "\n\n<i>Спортивный поединок: ни здоровья, ни опыта не потеряно. На равных статах решают билд и удача.</i>";
    }

    /**
     * Причина победы словами (веб показывает её строкой под победителем).
     */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'knockout'  => 'нокаут',
            'hp'        => 'по очкам — осталось больше здоровья',
            'build'     => 'по очкам — крепче снаряжение',
            'seniority' => 'по очкам — больше времени в Пустоши',
            default     => '',
        };
    }

    /**
     * W17 (ADR-071) — `duels_open` (0/1), 0 — дефолт (закрыт).
     */
    public static function duelsOpenFlag(mixed $character): int
    {
        $raw = 0;
        if ($character instanceof \ArrayAccess || is_array($character)) {
            $raw = $character['duels_open'] ?? 0;
        }

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * @param array<string,mixed> $eqAttacker
     * @param array<string,mixed> $eqDefender
     * @param array<string,mixed> $result
     * @param array{winnerId: int, loserId: int, reason: string} $resolution
     */
    private function writeLog(array $eqAttacker, array $eqDefender, array $result, ?string $biome, array $resolution): ?int
    {
        try {
            $log = (new PvpBattleLogBuilder())->build($eqAttacker, $eqDefender, $result, $biome);
            // Дуэль — спорт без места: координаты бойцов (локация — приватные данные) в журнал не пишем,
            // для разбора по раундам они не нужны. Поправка владельца 2026-10-08, docs/defects/record-exposed-beyond-participants.md.
            $chars = is_array($log['characters'] ?? null) ? $log['characters'] : [];
            foreach (['attacker', 'defender'] as $side) {
                if (is_array($chars[$side] ?? null)) {
                    $block = $chars[$side];
                    unset($block['coords']);
                    $chars[$side] = $block;
                }
            }
            $log['characters'] = $chars;
            // Исход дуэли решает тай-брейк, а не движок: пишем его, плюс пометку «дуэль, без потерь».
            $log['duel']    = true;
            $log['outcome'] = [
                'type'     => is_string($result['type'] ?? null) ? $result['type'] : null,
                'winnerId' => $resolution['winnerId'],
                'loserId'  => $resolution['loserId'],
                'reason'   => $resolution['reason'],
            ];
            $now = date('Y-m-d H:i:s');
            $id  = (new BattleLogModel())->insert([
                'battle_type' => BattleJournalService::TYPE_DUEL,
                'player1_id'  => self::idOf($eqAttacker),
                'player2_id'  => self::idOf($eqDefender),
                'winner_id'   => $resolution['winnerId'] > 0 ? $resolution['winnerId'] : null,
                'created_at'  => $now,
                'finished_at' => $now,
                'log_data'    => json_encode($log, JSON_UNESCAPED_UNICODE),
            ]);

            return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        } catch (\Throwable $e) {
            // Журнал — не повод ронять уже проведённую дуэль (очки уже начислены).
            log_message('error', '[Arena] duel log write failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string,mixed> $defender
     */
    private function notifyDefender(array $defender, string $text, ?int $battleId): void
    {
        try {
            $tgUserId = $defender['telegram_user_id'] ?? null;
            if (! is_numeric($tgUserId)) {
                return;
            }
            $tg   = (new TelegramUserModel())->find((int) $tgUserId);
            $chat = is_array($tg) ? ($tg['telegram_id'] ?? null) : null;
            if (! is_numeric($chat)) {
                return;
            }
            $payload = [
                'chat_id'    => (int) $chat,
                'text'       => "Тебя вызвали на дуэль!\n\n" . $text,
                'parse_mode' => 'HTML',
            ];
            $keyboard = PveNotificationSender::keyboard($battleId);
            if ($keyboard !== null) {
                $payload['reply_markup'] = (string) json_encode($keyboard);
            }
            // Вызов из веба (`/play`) идёт без Telegram-объекта: без моста отправка молча не уходит.
            if (! TelegramBridge::ensure()) {
                log_message('error', '[Arena] notifyDefender: Telegram bridge unavailable, defender not notified');

                return;
            }
            $response = Request::sendMessage($payload);
            if (! $response->isOk()) {
                log_message('warning', '[Arena] notifyDefender not ok: ' . $response->getDescription());
            }
        } catch (\Throwable $e) {
            log_message('error', '[Arena] notifyDefender failed: ' . $e->getMessage());
        }
    }

    /**
     * Бой по умолчанию: биом клетки вызывающего → неизменный `simulateFight` без защиты базы (спорт),
     * оружие — через {@see DuelEquipmentRepository} (базовое для безоружных и слабых, перевес с весом).
     *
     * @param array<string,mixed> $eqAttacker
     * @param array<string,mixed> $eqDefender
     *
     * @return array{result: array<string,mixed>, biome: string|null}|null
     */
    private function realFight(array $eqAttacker, array $eqDefender, int $cell): ?array
    {
        $mapRow = (new MapModel())->where('cell_number', $cell)->first();
        if (! $mapRow) {
            return null;
        }
        $rawBiomeId = is_array($mapRow) ? ($mapRow['biome_id'] ?? null) : ($mapRow->biome_id ?? null);
        $biome      = (new BiomeModel())->find(is_numeric($rawBiomeId) ? (int) $rawBiomeId : 0);
        if (! $biome) {
            return null;
        }
        // Базовое оружие и вес перевеса — в ядре дуэли (DuelEquipmentRepository); движок неизменен.
        $result = $this->duels->simulate($eqAttacker, $eqDefender, $biome, $this->equipmentRepo());
        $name   = $biome['name'] ?? null;

        return ['result' => $result, 'biome' => is_string($name) && $name !== '' ? $name : null];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function cellsClose(array $a, array $b): bool
    {
        $cellA = is_numeric($a['cell_number'] ?? null) ? (int) $a['cell_number'] : 0;
        $cellB = is_numeric($b['cell_number'] ?? null) ? (int) $b['cell_number'] : 0;
        if ($cellA === $cellB) {
            return true;
        }
        $repo = $this->equipmentRepo();
        $mapA = $repo->getMapCell($cellA);
        $mapB = $repo->getMapCell($cellB);
        if (! $mapA || ! $mapB) {
            return false;
        }

        return abs($mapA['coordinate_x'] - $mapB['coordinate_x']) <= 1 && abs($mapA['coordinate_y'] - $mapB['coordinate_y']) <= 1;
    }

    private function equipmentRepo(): PvpEquipmentRepository
    {
        return new PvpEquipmentRepository(
            new CharactersWeaponsModel(),
            new WeaponModel(),
            new CharactersOutfitsModel(),
            new OutfitModel(),
            new MapModel(),
            new CharacterFactionModel(),
            new FactionModel()
        );
    }

    private static function factionName(int $factionId): string
    {
        if ($factionId <= 0) {
            return 'Нейтральные';
        }
        $row = (new FactionModel())->find($factionId);
        if (is_array($row) && is_string($row['name'] ?? null) && $row['name'] !== '') {
            return $row['name'];
        }
        if (is_object($row) && isset($row->name) && is_string($row->name) && $row->name !== '') {
            return $row->name;
        }

        return 'Фракция #' . $factionId;
    }

    /**
     * @return Refusal
     */
    private static function refuse(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    private static function idOf(mixed $c): int
    {
        $raw = ($c instanceof \ArrayAccess || is_array($c)) ? ($c['id'] ?? null) : null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * @param array<string,mixed> $c
     */
    private static function nameOf(array $c, int $id): string
    {
        return is_string($c['name'] ?? null) && $c['name'] !== '' ? $c['name'] : ('№' . $id);
    }

    /**
     * @return array<string,mixed>
     */
    private static function toArray(mixed $c): array
    {
        $raw = [];
        if (is_array($c)) {
            $raw = $c;
        } elseif ($c instanceof \CodeIgniter\Entity\Entity) {
            $raw = $c->toRawArray();
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
