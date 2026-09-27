<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Entities\CharacterEntity;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\TaskModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\Progression\EarlyProgressionService;
use App\Services\Player\VehicleActivationService;

/**
 * W2.N2-02 (ADR-190) — шаг на соседнюю клетку: одно ядро для бота (`move_dir_*`) и веба
 * (`/play`, `op=step`). Перенос 1:1 из `MoveCharacterToDirectionAction::handle()` до отправки:
 *
 * - блокировки: переезд базы, эксклюзивная задача (прежний запрос handler'а);
 * - край мира 0..999 и доступные направления;
 * - цена шага (`world.move.*`, транспорт — только `tired_factor`, ранний множитель новичка);
 * - записи: статы (атомарный relative-UPDATE), клетка и биом, дебафф, раскрытие 3×3.
 *
 * Исход — `{ok, code, message, from, to, cost, events[]}`. `message` — прежний текст отказа бота
 * (для `relocation`/`busy` — Markdown). `events[]` — `{type, text, buttons[]}` после шага:
 * рана ({@see self::EVENT_DEBUFF}, отдельное сообщение) и «хвост» клетки ({@see self::TAIL_TYPES}:
 * караван, поселение, узел, незнакомец, объект, дроны, карго-дрон, склад) — у бота это кнопки
 * под картой, у веба — флеш под картой. Ни один метод сервиса не шлёт в Telegram.
 *
 * Хуки, которым нужен чат (обнаружение игроков, вышки, онбординг-подсказки, находка, cold-open,
 * атмосфера), зовёт {@see afterStep()} — бот сразу после экрана шага, как раньше; веб — под
 * захватом `WebDelivery`, и их сообщения становятся событиями под картой.
 *
 * Прирост за шаг — GameSettings `world.move.stat_per_step` / `world.move.xp_per_step` (дефолты
 * 0.02 / 0.03 = прежние hardcoded-числа), × ранний множитель новичка.
 *
 * @phpstan-type Button array{text: string, callback_data: string}
 * @phpstan-type Event array{type: string, text: string, buttons: list<Button>}
 * @phpstan-type Point array{x: int, y: int, cell: int}
 * @phpstan-type Outcome array{ok: bool, code: string, message: ?string, from: ?Point, to: ?Point, cost: ?array{health: float, tired: float}, events: list<Event>, node_sighted: bool, character: ?CharacterEntity, target: ?array<string, mixed>}
 */
class MoveService
{
    public const MAP_MIN = 0;
    public const MAP_MAX = 999;

    /** @var array<string, array{int, int}> dx,dy по 8 направлениям (y растёт на юг). */
    public const DIRECTIONS = [
        'north'     => [0, -1],
        'south'     => [0, 1],
        'west'      => [-1, 0],
        'east'      => [1, 0],
        'northwest' => [-1, -1],
        'northeast' => [1, -1],
        'southwest' => [-1, 1],
        'southeast' => [1, 1],
    ];

    /** @var array<string, string> */
    public const DIRECTION_RU = [
        'north'     => 'север',
        'south'     => 'юг',
        'west'      => 'запад',
        'east'      => 'восток',
        'northwest' => 'северо-запад',
        'northeast' => 'северо-восток',
        'southwest' => 'юго-запад',
        'southeast' => 'юго-восток',
    ];

    // Коды исхода.
    public const MOVED        = 'moved';
    public const BAD_DIR      = 'bad_dir';
    public const NO_CHARACTER = 'no_character';
    public const RELOCATION   = 'relocation';
    public const BUSY         = 'busy';
    public const NO_CELL      = 'no_cell';
    public const EDGE         = 'edge';
    public const EXHAUSTED    = 'exhausted';

    /** Коды, чей текст отказа — Markdown (как слал прежний handler). */
    public const MARKDOWN_CODES = [self::RELOCATION, self::BUSY];

    // События шага.
    public const EVENT_DEBUFF = 'debuff';

    /** Типы «хвоста» клетки — у бота это кнопки под картой, в порядке прежнего handler'а. */
    public const TAIL_TYPES = ['caravan', 'settlement', 'node', 'npc', 'strategic', 'drones', 'cargo', 'storage'];

    /** Дефолты прироста за шаг — прежние hardcoded-числа; значение — GameSettings. */
    public const STAT_PER_STEP_DEFAULT = 0.02;
    public const XP_PER_STEP_DEFAULT   = 0.03;

    private CharacterModel $characters;

    private MapModel $map;

    private GameSettingsService $settings;

    public function __construct(
        ?CharacterModel $characters = null,
        ?MapModel $map = null,
        ?GameSettingsService $settings = null
    ) {
        $this->characters = $characters ?? new CharacterModel();
        $this->map        = $map ?? new MapModel();
        $this->settings   = $settings ?? new GameSettingsService();
    }

    public static function isDirection(string $dir): bool
    {
        return isset(self::DIRECTIONS[$dir]);
    }

    /**
     * Шаг персонажа на соседнюю клетку.
     *
     * @return Outcome
     */
    public function step(int $characterId, string $dir): array
    {
        if (! self::isDirection($dir)) {
            return self::refuse(self::BAD_DIR, "Неизвестное направление: {$dir}.");
        }

        $character = $this->characters->find($characterId);
        if (! $character instanceof CharacterEntity || ! $character['cell_number']) {
            return self::refuse(self::NO_CHARACTER, 'Персонаж не найден или нет cell_number.');
        }
        $charId = self::int($character['id']);

        if ($this->relocationActive($charId)) {
            return self::refuse(self::RELOCATION, "Сейчас идёт *Планируемый переезд базы*.\nПока эта задача активна, это действие недоступно!");
        }
        $busy = $this->blockingTaskName($charId, self::int($character['telegram_user_id'] ?? 0));
        if ($busy !== null) {
            return self::refuse(self::BUSY, "🚫 Невозможно переместиться!\n"
                . "У вас идёт задача: {$busy}\n"
                . 'Сначала дождитесь окончания.');
        }

        $currentCell = $this->map->where('cell_number', $character['cell_number'])->first();
        if (! is_array($currentCell)) {
            return self::refuse(self::NO_CELL, 'Текущая локация не найдена!');
        }

        [$dx, $dy] = self::DIRECTIONS[$dir];
        $curX      = self::int($currentCell['coordinate_x'] ?? 0);
        $curY      = self::int($currentCell['coordinate_y'] ?? 0);
        $newX      = $curX + $dx;
        $newY      = $curY + $dy;
        $from      = ['x' => $curX, 'y' => $curY, 'cell' => self::int($currentCell['cell_number'] ?? 0)];

        // Край мира (E2): персонаж остаётся на месте, ход не тратится.
        $targetCell  = $this->map->where('coordinate_x', $newX)->where('coordinate_y', $newY)->first();
        $outOfBounds = $newX < self::MAP_MIN || $newX > self::MAP_MAX || $newY < self::MAP_MIN || $newY > self::MAP_MAX;
        if ($outOfBounds || ! is_array($targetCell)) {
            $available = self::describeAvailableDirections($curX, $curY);
            $outcome   = self::refuse(self::EDGE, '🧭 Там край острова — дальше на ' . self::DIRECTION_RU[$dir] . " пути нет.\n"
                . ($available !== '' ? "Можно пойти: {$available}" : 'Двигайся вглубь острова.'));
            $outcome['from'] = $from;

            return $outcome;
        }
        /** @var array<string, mixed> $targetCell */

        // Цена шага (ADR-138 ранний множитель, transport-05 tired_factor, `world.move.*`).
        $early     = new EarlyProgressionService();
        $charLevel = self::float($character['level'] ?? 1);
        $biome     = (new BiomeModel())->find($targetCell['biome_id']);
        $cost      = self::computeStepCost(
            [
                'health_cost_base'        => self::float($this->settings->get('world.move.health_cost_base', 0.1)),
                'tired_cost_base'         => self::float($this->settings->get('world.move.tired_cost_base', 3.35)),
                'danger_health_surcharge' => self::float($this->settings->get('world.move.danger_health_surcharge', 1.15)),
            ],
            $biome !== null && self::float($biome['danger_level'] ?? 0) >= 8,
            $this->vehicleProfile($charId),
            $early->moveCostFactor($charLevel)
        );

        $futureHealth = self::float($character['health']) - $cost['health'];
        $futureTired  = self::float($character['tired']) - $cost['tired'];
        if ($futureHealth < 0 || $futureTired < 0) {
            $outcome = self::refuse(self::EXHAUSTED, "Недостаточно ресурсов на перемещение!\n"
                . "Здоровье после перехода: {$futureHealth}\n"
                . "Усталость после перехода: {$futureTired}");
            $outcome['from'] = $from;
            $outcome['cost'] = $cost;

            return $outcome;
        }

        // Записи: статы атомарно от свежих значений (класс lost-update), позиция — отдельно.
        $earlyMult = $early->gainMultiplier($charLevel);
        (new \App\Services\Player\CharacterStatsService())->adjust($charId, [
            'health'     => -$cost['health'],
            'tired'      => -$cost['tired'],
            'strength'   => $this->statPerStep() * $earlyMult,
            'experience' => $this->xpPerStep() * $earlyMult,
        ]);
        $this->characters->update($charId, [
            'cell_number' => self::int($targetCell['cell_number'] ?? 0),
            'biome_id'    => self::int($targetCell['biome_id'] ?? 0),
        ]);

        // Рана по биому (killswitch `debuff.enabled` внутри).
        $gotDebuff = (new \App\Services\Player\DebuffSourceService())->rollOnMove($charId, $biome);

        // Туман войны (ADR-019 §1): раскрыть 3×3 вокруг новой позиции.
        $toX = self::int($targetCell['coordinate_x'] ?? 0);
        $toY = self::int($targetCell['coordinate_y'] ?? 0);
        (new ExploredCellsModel())->revealAround(
            $charId,
            self::int($character['telegram_user_id'] ?? 0),
            $toX,
            $toY,
            isset($character['level']) ? self::int($character['level']) : null
        );

        $toCell      = self::int($targetCell['cell_number'] ?? 0);
        [$tail, $nodeSighted] = $this->cellEvents($charId, $toCell);
        $events      = $tail;
        if ($gotDebuff !== null) {
            $debuff = self::debuffEvent($gotDebuff);
            if ($debuff !== null) {
                $events[] = $debuff;
            }
        }

        return [
            'ok'           => true,
            'code'         => self::MOVED,
            'message'      => null,
            'from'         => $from,
            'to'           => ['x' => $toX, 'y' => $toY, 'cell' => $toCell],
            'cost'         => $cost,
            'events'       => $events,
            'node_sighted' => $nodeSighted,
            'character'    => $character,
            'target'       => $targetCell,
        ];
    }

    /**
     * Хуки после шага, которым нужен чат: обнаружение игроков, вышки, подсказки «первая база» и
     * «первый узел», находка первого хода, приманка cold-open, атмосфера новичка. Порядок и
     * аргументы — как у прежнего handler'а (персонаж — строка ДО шага).
     *
     * @param Outcome $outcome успешный исход {@see step()}
     * @param bool    $coldOpen приманка cold-open (прежний handler звал её только после правки карты)
     */
    public function afterStep(array $outcome, int $chatId, bool $coldOpen = true): void
    {
        $character = $outcome['character'];
        $target    = $outcome['target'];
        if (! $outcome['ok'] || $character === null || $target === null || $outcome['to'] === null) {
            return;
        }
        $charId = self::int($character['id']);

        (new \App\Services\Player\PlayerDetectionService())->detectNearbyPlayers($charId);
        // pvp-detection-clarity-04 — вышка на обычном шаге; переход не падает.
        try {
            (new \App\Services\PVE\TowerAlertService())->notifyTowersNear($charId, $outcome['to']['x'], $outcome['to']['y']);
        } catch (\Throwable $e) {
            log_message('error', '[MoveService] towerAlerts: ' . $e->getMessage());
        }
        // ADR-103 Часть B Слой 1 — «первая база»; WB13 (ADR-137) — первый живой узел.
        $hints = new \App\Services\Onboarding\OnboardingHintService();
        $hints->maybeSendFirstBaseTip($character, $chatId);
        if ($outcome['node_sighted']) {
            $hints->maybeSendFirstBossSightingHint($character, $chatId);
        }
        // ADR-104 Ф3b — «момент удачи» на первый ход новичка.
        (new \App\Services\Onboarding\LuckyFindService())->maybeGrantFirstMove($character, $chatId);
        // S4 (ADR-139) — приманка cold-open на новой клетке.
        if ($coldOpen) {
            (new \App\Services\Onboarding\ColdOpenSignalService())->tryReachBait($character, $target, $chatId);
        }
        // S9 (ADR-147) — ранняя атмосфера новичка.
        (new \App\Services\Onboarding\NewbieAtmosphereService())->maybeSendAtmosphere($character, $chatId);
    }

    /**
     * transport-05 — чистая стоимость одиночного шага: из профиля транспорта только `tired_factor`,
     * здоровье — база + danger-надбавка. `tired_cost_base × tired_factor × earlyFactor` одним выражением.
     *
     * @param array{health_cost_base: float, tired_cost_base: float, danger_health_surcharge: float} $settings
     * @param array<string, mixed>                                                                    $vehicleProfile
     *
     * @return array{health: float, tired: float}
     */
    public static function computeStepCost(array $settings, bool $dangerBiome, array $vehicleProfile, float $earlyFactor): array
    {
        $pace = new MarchPaceService();

        $healthCost = $pace->healthCostPerCell($settings['health_cost_base'], $vehicleProfile);
        $tiredCost  = $pace->tiredCostPerCell($settings['tired_cost_base'], $vehicleProfile) * $earlyFactor;

        if ($dangerBiome) {
            $healthCost += $settings['danger_health_surcharge'];
        }

        return ['health' => $healthCost, 'tired' => $tiredCost];
    }

    /**
     * E2 — направления (из 8), по которым из клетки есть ход в пределах карты. Карта плотная:
     * in-bounds = «клетка существует».
     *
     * @return list<string> ключи в порядке {@see DIRECTIONS}
     */
    public static function availableDirections(int $curX, int $curY): array
    {
        $out = [];
        foreach (self::DIRECTIONS as $dir => [$dx, $dy]) {
            $nx = $curX + $dx;
            $ny = $curY + $dy;
            if ($nx >= self::MAP_MIN && $nx <= self::MAP_MAX && $ny >= self::MAP_MIN && $ny <= self::MAP_MAX) {
                $out[] = $dir;
            }
        }

        return $out;
    }

    /** Прирост силы за шаг (до раннего множителя). */
    public function statPerStep(): float
    {
        return self::float($this->settings->get('world.move.stat_per_step', self::STAT_PER_STEP_DEFAULT));
    }

    /** Прирост опыта за шаг (до раннего множителя). */
    public function xpPerStep(): float
    {
        return self::float($this->settings->get('world.move.xp_per_step', self::XP_PER_STEP_DEFAULT));
    }

    /**
     * Сообщение о полученной ране: что случилось, чем грозит и чем лечится (Markdown, media-off).
     *
     * @return Event|null
     */
    public static function debuffEvent(string $debuffKey): ?array
    {
        $meta = \Config\Debuffs::get($debuffKey);
        if ($meta === null) {
            return null;
        }

        $cureNames = [];
        foreach ($meta['cured_by'] as $itemEng) {
            $item        = (new \App\Models\CraftedItemsModel())->where('name_eng', $itemEng)->first();
            $cureNames[] = is_array($item) && is_string($item['name_rus'] ?? null) ? $item['name_rus'] : $itemEng;
        }

        return [
            'type'    => self::EVENT_DEBUFF,
            'text'    => "{$meta['emoji']} *{$meta['name']}!*\n\n"
                . "{$meta['what']}\n\n"
                . '🩺 *Чем снять:* ' . implode(' или ', $cureNames) . ".\n"
                . 'Идти с этим можно, но лучше не тянуть — само оно быстро не пройдёт.',
            'buttons' => [
                ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                ['text' => '⚕️ Скрафтить', 'callback_data' => 'medicinesCraft1'],
            ],
        ];
    }

    /**
     * «Хвост» новой клетки — те же гейты и порядок, что у кнопок прежнего handler'а.
     * Шов для тестов (чужие фичи тянут свои таблицы).
     *
     * @return array{0: list<Event>, 1: bool} события и «живой узел на клетке» (для подсказки WB13)
     */
    protected function cellEvents(int $characterId, int $cell): array
    {
        $events      = [];
        $nodeSighted = false;

        // V25 (ADR-057) — активный караван на новой клетке.
        if ((new \App\Services\Player\CaravanService())->enabled()
            && ! empty((new \App\Models\CaravanModel())->findActiveOnCell($cell))) {
            $events[] = self::event('caravan', 'Здесь стоит караван.', '🚚 Караван', 'caravanLook');
        }

        // ADR-101 — поселение (подавляет узел и незнакомца), иначе WB12 узел, иначе ADR-089 нейтрал.
        $settlePolicy = (new \App\Services\Settlement\SettlementZoneService())->policyAt($cell);
        if ($settlePolicy !== null) {
            $sName    = is_string($settlePolicy['settlement']['name_ru'] ?? null) ? $settlePolicy['settlement']['name_ru'] : 'Поселение';
            $events[] = self::event('settlement', "Здесь поселение «{$sName}».", '🏚 ' . $sName, 'settleHub');
        } else {
            $nodePoint = (new \App\Services\PVE\BossEncounterService())->pointAtCell($cell);
            if ($nodePoint !== null) {
                $events[]    = self::event('node', 'Здесь узел.', '☠ Узел', 'nodeAct_look_0');
                $nodeSighted = ($nodePoint['status'] ?? '') === 'alive';
            } else {
                $npc = new \App\Services\NPC\NpcInteractionService();
                if ($npc->enabled() && $npc->passiveSpawnOnCell($cell) !== null) {
                    $events[] = self::event('npc', 'Рядом незнакомец.', '👤 Незнакомец', 'npcEncounter');
                }
            }
        }

        // ADR-129 — активный strategic-объект: одиночный шаг discovery не запускает, нужна кнопка.
        $stratName = (new StrategicObjectService())->nameOnCell($cell);
        if ($stratName !== null) {
            $events[] = self::event('strategic', "Здесь объект: {$stratName}.", '🔍 Обыскать: ' . $stratName, 'strategicSearch');
        }

        // W2 (ADR-058) дрон-разведчик; W3b (ADR-060) карго-дрон всегда виден (active или lock) + склад.
        $drones = new \App\Services\Player\DroneService();
        if ($drones->isEnabled() && $this->ownsItem($characterId, 'DroneScout')) {
            $events[] = self::event('drones', 'Дрон-разведчик под рукой.', '🚁 Дроны', 'droneScoutList');
        }
        if ($drones->cargoIsEnabled()) {
            $events[] = $this->ownsItem($characterId, 'DroneCargo')
                ? self::event('cargo', 'Карго-дрон под рукой.', '🚚 Карго-дрон', 'cargoDroneList')
                : self::event('cargo', 'Карго-дрона пока нет.', '🔒 Карго-дрон', 'cargoDroneLocked');

            $hasStorage = (new \App\Models\BaseStorageModel())->where('character_id', $characterId)->countAllResults();
            if ($hasStorage > 0) {
                $events[] = self::event('storage', 'На складе базы есть вещи.', '📦 Склад базы', 'baseStorageList');
            }
        }

        return [$events, $nodeSighted];
    }

    /**
     * transport-05 — профиль активного транспорта; нет машины / изношена → нейтраль.
     * Шов для тестов.
     *
     * @return array<string, mixed>
     */
    protected function vehicleProfile(int $characterId): array
    {
        $effects = new VehicleEffectsService();
        $active  = (new VehicleActivationService())->resolveActive($characterId);

        if ($active === null || $active['charges'] <= 0) {
            return $effects->neutralProfile();
        }

        return $effects->profileFor(VehicleEffectsService::keyForItemNameEng($active['key']), VehicleEffectsService::TERRAIN_EXPLORED);
    }

    /** Идёт переезд базы (`BaseRelocation` в работе). */
    protected function relocationActive(int $characterId): bool
    {
        foreach ((new \App\Services\Tasks\ActiveTasksService())->getActiveTasksWithDetails($characterId) as $task) {
            if (is_array($task) && ($task['name'] ?? null) === 'BaseRelocation') {
                return true;
            }
        }

        return false;
    }

    /** Имя эксклюзивной задачи в работе (прежний запрос handler'а); null — свободен. */
    protected function blockingTaskName(int $characterId, int $telegramUserId): ?string
    {
        $rows  = (new CharacterTaskModel())
            ->where('character_id', $characterId)
            ->where('telegram_user_id', $telegramUserId)
            ->where('status', 'in_work')
            ->findAll();
        $tasks = new TaskModel();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $task = $tasks->find(self::int($row['task_id'] ?? 0));
            if (is_array($task) && self::int($task['parallel_execution_allowed'] ?? 1) === 0) {
                $name = $task['name_rus'] ?? $task['name'] ?? '';

                return is_scalar($name) ? (string) $name : '';
            }
        }

        return null;
    }

    private function ownsItem(int $characterId, string $nameEng): bool
    {
        $row = (new \App\Models\CraftedItemsModel())->where('name_eng', $nameEng)->first();
        $id  = is_array($row) && is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        if ($id <= 0) {
            return false;
        }

        return is_array((new \App\Models\CraftedItemsLogModel())
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $id)
            ->where('quantity >', 0)
            ->first());
    }

    private static function describeAvailableDirections(int $curX, int $curY): string
    {
        return implode(', ', array_map(
            static fn (string $dir): string => self::DIRECTION_RU[$dir],
            self::availableDirections($curX, $curY)
        ));
    }

    /** @return Event */
    private static function event(string $type, string $text, string $label, string $callback): array
    {
        return ['type' => $type, 'text' => $text, 'buttons' => [['text' => $label, 'callback_data' => $callback]]];
    }

    /** @return Outcome */
    private static function refuse(string $code, string $message): array
    {
        return [
            'ok' => false, 'code' => $code, 'message' => $message, 'from' => null, 'to' => null, 'cost' => null,
            'events' => [], 'node_sighted' => false, 'character' => null, 'target' => null,
        ];
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function float(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }
}
