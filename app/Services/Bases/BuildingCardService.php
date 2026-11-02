<?php

declare(strict_types=1);

namespace App\Services\Bases;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Services\Buildings\ProductionEffectService;
use App\Services\Farming\FarmingService;
use App\Services\PVE\DefenseStructureService;
use App\Services\Tasks\ActiveTasksService;

/**
 * W2.N4b (ADR-190): карточка «моей» постройки — одно ядро для бота и `/play`.
 *
 * Раньше карточку собирали 14 копий `Camp/Buildings/*Handler.php`. Их различия живут здесь таблицей
 * {@see self::CARDS}: картинка, шаблон текста, собственные кнопки здания и — чтобы бот остался
 * байт-в-байт прежним (решение владельца 2026-11-02) — расхождения старых копий между собой:
 * формула «осталось использований», подпись `collective`, тексты отказов и порядок проверок.
 * Модель экрана не знает ни `chat_id`, ни Markdown: бот собирает из неё прежний текст
 * ({@see \App\Controllers\Telegram\Commands\Actions\Camp\BuildingHandlerAction}), веб — свою разметку.
 *
 * Переезд базы и выбор базы (`_b<id>`) проверяются здесь, для обоих клиентов и для всех 14 зданий.
 *
 * @phpstan-type Action array{kind: string, text: string, data: string}
 * @phpstan-type Card array{
 *   state: string, refusal: string, text: string, key: string, template: string,
 *   building_id: int, char_building_id: int, name: string, emoji: string, image: string,
 *   level: int, amount: int, tax: int, built_at: string, disappears_at: string,
 *   uses_used: int, uses_left: int|null, uses_limit: int|null, usage: string, usage_label: string,
 *   description: string, hp_cur: int|null, hp_max: int|null, effect: string|null,
 *   stack_on: bool, stackable: bool, farming: array{used: int, max: int}|null,
 *   rows: list<list<Action>>
 * }
 */
final class BuildingCardService
{
    public const STATE_OK      = 'ok';
    public const STATE_REFUSED = 'refused';

    /** Отказ без карточки: `relocation` — Markdown-текст переезда; `plain` — простой текст; `unknown` — здания нет в таблице. */
    public const REFUSAL_RELOCATION = 'relocation';
    public const REFUSAL_PLAIN      = 'plain';
    public const REFUSAL_UNKNOWN    = 'unknown';

    public const TEXT_UNKNOWN = "🏚 Не удалось открыть это строение.\n\n"
        . 'Оно есть на базе, но его экран пока не готов — мы уже знаем об этом. '
        . 'Вернись на базу и попробуй другое сооружение.';

    private const NO_USER = 'Пользователь не найден в базе данных или персонаж не определён.';

    /**
     * Таблица карточек. `template`: generic | tower | defensive | leanto.
     * `left`: формула «осталось» старой копии — `cb` (есть счётчик у постройки → лимит − счётчик),
     * `both` (лимит truthy и счётчик есть), `clamp` (оба не null, не меньше 0).
     * `collective`: подписывала ли копия `collective` как «коллективная» (иначе — «общая»).
     * `info_first`: копия искала здание в справочнике ДО строки игрока (Арсенал, Вышка, оборона, Навес).
     *
     * @var array<string, array{template:string, image:string, emoji:string, left:string, collective:bool, info_first:bool, no_user:string, no_info:string, missing:string, extra:list<array{text:string,data:string}>}>
     */
    public const CARDS = [
        'HandPump' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/hand_pump.jpg', 'emoji' => '🏗️', 'left' => 'cb', 'collective' => false, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о постройке не найдена.', 'missing' => 'У вас нет данной постройки.', 'extra' => []],
        'RoboticsWorkshop' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/Robotics-Workshop.jpg', 'emoji' => '🏗️', 'left' => 'cb', 'collective' => false, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о постройке не найдена.', 'missing' => 'У вас нет данной постройки.',
            'extra' => [['text' => '🤖 Роботы', 'data' => 'AllRobots']]],
        'BlastFurnace' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/blast_furnace.png', 'emoji' => '🏗️', 'left' => 'both', 'collective' => false, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о Доменной печи не найдена.', 'missing' => 'У вас нет Доменной печи на базе.', 'extra' => []],
        'TeleportationCenter' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/teleport_center.png', 'emoji' => '🏗️', 'left' => 'both', 'collective' => false, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о Центре телепортации не найдена.', 'missing' => 'У вас нет Центра телепортации на базе.',
            'extra' => [['text' => '📡 Маяки', 'data' => 'teleportBeacon']]],
        'Arsenal' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/arsenal.png', 'emoji' => '🏗️', 'left' => 'both', 'collective' => false, 'info_first' => true,
            'no_user' => self::NO_USER, 'no_info' => 'Здание "Арсенал" не найдено в таблице `buildings`.', 'missing' => 'У вас нет здания "Арсенал" на базе.', 'extra' => []],
        'CommunicationTower' => ['template' => 'tower', 'image' => 'uploads/telegram/camp/communication_tower.png', 'emoji' => '📡', 'left' => 'both', 'collective' => false, 'info_first' => true,
            'no_user' => 'Ошибка: не найден пользователь или персонаж.', 'no_info' => 'Здание "Вышка связи" не найдено в таблице buildings.', 'missing' => 'У вас нет здания "Вышка связи" на базе.', 'extra' => []],
        'Greenhouse' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/Greenhouse_craft.png', 'emoji' => '🏗️', 'left' => 'clamp', 'collective' => true, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о Теплице не найдена.', 'missing' => 'У вас нет Теплицы на базе.', 'extra' => []],
        'Gym' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/Gym.png', 'emoji' => '🏗️', 'left' => 'clamp', 'collective' => true, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о Спортзале не найдена.', 'missing' => 'У вас нет Спортзала на базе.', 'extra' => []],
        'Laboratory' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/laboratory.jpg', 'emoji' => '🏗️', 'left' => 'clamp', 'collective' => true, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о Лаборатории не найдена.', 'missing' => 'У вас нет Лаборатории на базе.', 'extra' => []],
        'SolarStation' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/solar_power_station.jpg', 'emoji' => '🏗️', 'left' => 'clamp', 'collective' => true, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о Солнечной станции не найдена.', 'missing' => 'У вас нет Солнечной станции на базе.', 'extra' => []],
        'Warehouse' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/Warehouse.png', 'emoji' => '🏗️', 'left' => 'clamp', 'collective' => true, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о Складе не найдена.', 'missing' => 'У вас нет Склада на базе.', 'extra' => []],
        'Workshop' => ['template' => 'generic', 'image' => 'uploads/telegram/camp/WorkShop.png', 'emoji' => '🏗️', 'left' => 'clamp', 'collective' => true, 'info_first' => false,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о Мастерской не найдена.', 'missing' => 'У вас нет Мастерской на базе.', 'extra' => []],
        'WoodenWall' => ['template' => 'defensive', 'image' => 'uploads/telegram/camp/wooden_wall.jpg', 'emoji' => '🪵', 'left' => 'cb', 'collective' => false, 'info_first' => true,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о постройке не найдена.', 'missing' => 'У вас нет такой постройки на базе.', 'extra' => []],
        'BarbedFence' => ['template' => 'defensive', 'image' => 'uploads/telegram/camp/barbed_fence.jpg', 'emoji' => '🌵', 'left' => 'cb', 'collective' => false, 'info_first' => true,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о постройке не найдена.', 'missing' => 'У вас нет такой постройки на базе.', 'extra' => []],
        'WatchTower' => ['template' => 'defensive', 'image' => 'uploads/telegram/camp/watch_tower.jpg', 'emoji' => '🗼', 'left' => 'cb', 'collective' => false, 'info_first' => true,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о постройке не найдена.', 'missing' => 'У вас нет такой постройки на базе.', 'extra' => []],
        'LeanTo' => ['template' => 'leanto', 'image' => 'uploads/telegram/camp/lean_to.jpg', 'emoji' => '⛺', 'left' => 'cb', 'collective' => false, 'info_first' => true,
            'no_user' => self::NO_USER, 'no_info' => 'Информация о постройке не найдена.', 'missing' => 'У вас нет такой постройки на базе.',
            'extra' => [['text' => '🏗 Развитие базы', 'data' => 'baseDevelopment'], ['text' => '📖 Путь новичка', 'data' => 'guide_base']]],
    ];

    public function __construct(
        private readonly BuildingModel $buildings = new BuildingModel(),
        private readonly CharacterBuildingModel $charBuildings = new CharacterBuildingModel(),
        private readonly ActiveTasksService $tasks = new ActiveTasksService(),
        private readonly BaseScopeResolver $scope = new BaseScopeResolver(),
        private readonly DefenseStructureService $defense = new DefenseStructureService(),
    ) {
    }

    public static function has(string $key): bool
    {
        return isset(self::CARDS[$key]);
    }

    /** Отказ «нет персонажа» — свой текст у каждой старой копии. */
    public static function noUserText(string $key): string
    {
        return self::CARDS[$key]['no_user'] ?? self::NO_USER;
    }

    /**
     * Модель карточки постройки `$key` на базе персонажа.
     *
     * @param int|null $baseId номер базы из кнопки (`_b<id>`) — ядро перепроверяет его; null — база, где игрок стоит
     *
     * @return Card
     */
    public function card(int $characterId, int $currentCell, string $key, ?int $baseId): array
    {
        $cfg = self::CARDS[$key] ?? null;
        if ($cfg === null) {
            return self::refused(self::REFUSAL_UNKNOWN, self::TEXT_UNKNOWN, $key);
        }
        if ($this->tasks->hasActiveRelocation($characterId)) {
            return self::refused(self::REFUSAL_RELOCATION, ActiveTasksService::TEXT_RELOCATION, $key);
        }

        $info = $this->buildings->where('name_en', $key)->first();
        $info = is_array($info) ? $info : null;
        if ($info === null && $cfg['info_first']) {
            return self::refused(self::REFUSAL_PLAIN, $cfg['no_info'], $key);
        }
        $buildingId = $info !== null ? self::int($info['id'] ?? null) : 0;

        $scope = $baseId !== null
            ? $this->scope->resolveForBase($characterId, $currentCell, $baseId)
            : $this->scope->resolve($characterId, $currentCell);
        $cell = $scope['cell'];
        if ($cell === null) {
            return self::refused(self::REFUSAL_PLAIN, (string) $scope['text'], $key);
        }

        $q = $this->charBuildings->where('character_id', $characterId)->where('building_id', $buildingId)->where('map_cell_id', $cell);
        if ($cfg['template'] === 'defensive' || $cfg['template'] === 'leanto') {
            // Самая повреждённая инстанция — чтобы «🔧 Ремонт» был про неё (как в старой карточке обороны).
            $q->orderBy('hp', 'ASC');
        }
        $cb = $q->first();
        if (! is_array($cb)) {
            return self::refused(self::REFUSAL_PLAIN, $cfg['missing'], $key);
        }
        if ($info === null) {
            return self::refused(self::REFUSAL_PLAIN, $cfg['no_info'], $key);
        }

        return $this->build($key, $cfg, $info, $cb, $characterId, $baseId);
    }

    /**
     * @param array{template:string, image:string, emoji:string, left:string, collective:bool, info_first:bool, no_user:string, no_info:string, missing:string, extra:list<array{text:string,data:string}>} $cfg
     * @param array<int|string,mixed> $info
     * @param array<int|string,mixed> $cb
     *
     * @return Card
     */
    private function build(string $key, array $cfg, array $info, array $cb, int $characterId, ?int $baseId): array
    {
        $template   = $cfg['template'];
        $buildingId = self::int($info['id'] ?? null);
        $level      = self::int($cb['level'] ?? null);
        $amount     = max(1, self::int($cb['amount'] ?? null, 1));
        $cbUses     = is_numeric($cb['usage_count'] ?? null) ? (int) $cb['usage_count'] : null;
        $limit      = is_numeric($info['usage_count'] ?? null) ? (int) $info['usage_count'] : null;
        $usage      = is_string($cb['usage'] ?? null) ? $cb['usage'] : '';

        $left = match ($cfg['left']) {
            'cb'    => $cbUses !== null ? (int) $limit - $cbUses : null,
            'both'  => ($limit !== null && $limit !== 0 && $cbUses !== null) ? $limit - $cbUses : null,
            default => ($limit !== null && $cbUses !== null) ? max(0, $limit - $cbUses) : null,
        };
        $usageLabel = $usage === 'personal' ? 'персональная'
            : (($cfg['collective'] && $usage === 'collective') ? 'коллективная' : 'общая');

        $upgradeData = 'upgrade_building_' . $buildingId;
        if ($baseId !== null) {
            $upgradeData = BaseCallbackSuffix::append($upgradeData, $baseId);
        }
        $upgrade = ['kind' => 'upgrade', 'text' => '🆙 Поднять уровень', 'data' => $upgradeData];
        $base    = ['kind' => 'base', 'text' => '🏠 База', 'data' => 'Base'];
        $bridge  = static fn (array $b): array => ['kind' => 'bridge', 'text' => $b['text'], 'data' => $b['data']];

        $hpCur = null;
        $hpMax = null;
        $effect = null;
        $stackOn = false;
        $stackable = false;
        $farming = null;
        $level = $template === 'defensive' || $template === 'leanto' ? max(1, self::int($cb['level'] ?? null, 1)) : $level;

        switch ($template) {
            case 'defensive':
                $hpMax     = $this->defense->maxHpFor(self::int($info['hp'] ?? null), $level);
                $hpCur     = max(0, self::int($cb['hp'] ?? null));
                $stackOn   = $this->defense->isStackEnabled();
                $stackable = $key === 'WoodenWall' || $key === 'BarbedFence'; // вышка не стакает
                $effect    = $this->defenseEffect($key, $level, $amount, $stackOn);
                $top       = [$upgrade];
                $cbId      = self::int($cb['id'] ?? null);
                if ($hpCur < $hpMax && $cbId > 0) {
                    $top[] = ['kind' => 'bridge', 'text' => '🔧 Ремонт', 'data' => 'repairBuilding_' . $cbId];
                }
                $rows = [$top, [$base]];
                break;
            case 'leanto':
                $hpMax = self::int($info['hp'] ?? null);
                $hpCur = max(0, self::int($cb['hp'] ?? null));
                $rows  = [[$bridge($cfg['extra'][0])], [$base, $bridge($cfg['extra'][1])]];
                break;
            default:
                $effect = (new ProductionEffectService())->lineFor($key, $level);
                $rows   = [];
                if ($key === 'Greenhouse') {
                    $farm = new FarmingService();
                    if ($farm->isEnabled()) {
                        $farming = ['used' => $this->activePlantings($characterId), 'max' => $farm->maxConcurrentPlantings()];
                        $rows[]  = [['kind' => 'bridge', 'text' => '🌱 Грядки', 'data' => 'plantSeedMenu']];
                    }
                }
                foreach ($cfg['extra'] as $extra) {
                    $rows[] = [$bridge($extra)];
                }
                $rows[] = [$upgrade, $base];
        }

        return [
            'state'            => self::STATE_OK,
            'refusal'          => '',
            'text'             => '',
            'key'              => $key,
            'template'         => $template,
            'building_id'      => $buildingId,
            'char_building_id' => self::int($cb['id'] ?? null),
            'name'             => is_string($info['name_ru'] ?? null) ? $info['name_ru'] : match ($template) { 'leanto' => 'Навес', 'defensive' => $key, default => '' },
            'emoji'            => $cfg['emoji'],
            'image'            => $cfg['image'],
            'level'            => $level,
            'amount'           => $amount,
            'tax'              => self::int($cb['tax'] ?? null),
            'built_at'         => is_string($cb['built_at'] ?? null) ? $cb['built_at'] : '',
            'disappears_at'    => is_string($cb['disappearance_date'] ?? null) ? $cb['disappearance_date'] : '',
            'uses_used'        => $cbUses ?? 0,
            'uses_left'        => $left,
            'uses_limit'       => $limit !== null && $limit > 0 ? $limit : null,
            'usage'            => $usage,
            'usage_label'      => $usageLabel,
            'description'      => is_string($info['description'] ?? null) ? $info['description'] : '',
            'hp_cur'           => $hpCur,
            'hp_max'           => $hpMax,
            'effect'           => $effect,
            'stack_on'         => $stackOn,
            'stackable'        => $stackable,
            'farming'          => $farming,
            'rows'             => $rows,
        ];
    }

    /** Активные посадки теплицы (`plantCrop` в работе) — тот же счёт, что у меню грядок. */
    private function activePlantings(int $characterId): int
    {
        $task = (new TaskModel())->where('name', 'plantCrop')->first();
        if (! is_array($task) || ! is_numeric($task['id'] ?? null)) {
            return 0;
        }

        return self::int((new CharacterTaskModel())->where('character_id', $characterId)
            ->where('task_id', (int) $task['id'])->where('status', 'in_work')->countAllResults());
    }

    /**
     * Текущий боевой эффект обороны с учётом уровня (ADR-041) и стака по количеству (ADR-102 Ф3,
     * только при включённом killswitch; вышка не стакает). Перенесено из DefensiveBuildingHandler как есть.
     */
    private function defenseEffect(string $key, int $level, int $amount, bool $stackOn): string
    {
        $factor = ($stackOn && $amount > 1) ? $this->defense->defenseStackFactor($amount) : 1.0;
        switch ($key) {
            case 'WoodenWall':
                $p = $this->defense->scaledInt('defense.wall.damage_reduction_percent', 15, $level);
                if ($factor > 1.0) {
                    $cap     = $this->defense->totalReductionCapPercent();
                    $stacked = min((int) round($p * $factor), $cap);

                    return "−{$stacked}% получаемого урона у базы (×{$amount}, до cap {$cap}%)";
                }

                return "−{$p}% получаемого урона в PvP у базы (суммарно до cap)";
            case 'BarbedFence':
                $d = $this->defense->scaledInt('defense.fence.attacker_damage_per_round', 3, $level);
                if ($factor > 1.0) {
                    $stacked = (int) round($d * $factor);

                    return "контрурон атакующему {$stacked} hp/раунд (×{$amount})";
                }

                return "контрурон атакующему {$d} hp/раунд";
            case 'WatchTower':
                $i = $this->defense->scaledInt('defense.tower.defender_initiative_bonus_percent', 8, $level);

                return "+{$i}% инициативы защитнику + предупреждение о приближении чужаков";
            default:
                return '—';
        }
    }

    /** @return Card */
    private static function refused(string $kind, string $text, string $key): array
    {
        return [
            'state' => self::STATE_REFUSED, 'refusal' => $kind, 'text' => $text, 'key' => $key, 'template' => '',
            'building_id' => 0, 'char_building_id' => 0, 'name' => '', 'emoji' => '', 'image' => '',
            'level' => 0, 'amount' => 0, 'tax' => 0, 'built_at' => '', 'disappears_at' => '',
            'uses_used' => 0, 'uses_left' => null, 'uses_limit' => null, 'usage' => '', 'usage_label' => '',
            'description' => '', 'hp_cur' => null, 'hp_max' => null, 'effect' => null,
            'stack_on' => false, 'stackable' => false, 'farming' => null, 'rows' => [],
        ];
    }

    private static function int(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }
}
