<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\NpcSpawnModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Logging\ActionOrigin;
use App\Services\Telegram\BotMenuService;

/**
 * W2.N2-01 (ADR-190) — модель экрана «Мир»: одна для карты бота и сетки `/play`, без Markdown.
 *
 * Окно 12×12 со смещением 6 вокруг игрока; у каждой клетки код маркера по прежней приоритетной
 * лестнице `TextMapService` (за краем 0..999 → игрок → своя база → поселение → узел → приманка →
 * NPC рядом → туман → чужая база на открытой клетке → биом), биом (только у открытой клетки),
 * значок бота и признак «открыта». Плюс ближайшая своя база, статы, действия экрана и легенда.
 *
 * Бот рисует из модели текст ({@see TextMapService}) и кнопки ({@see MoveSurfaceService}), веб —
 * HTML-сетку (`site/_play/native_map`). Механика и гейты слоёв (`settlements.enabled`,
 * `world.nodes.point_mode_enabled`, cold-open приманка) — прежние.
 *
 * Модели мира принимаются через конструктор: `TextMapService` отдаёт сюда свои (в тестах —
 * подменённые), чтобы запросы шли тем же путём.
 *
 * @phpstan-type Cell array{x:int, y:int, code:string, biome:?int, marker:string, explored:bool}
 * @phpstan-type Grid array{error:?string, center:?array{x:int, y:int, biome:?int}, window:?array{x0:int, y0:int, size:int}, cells:list<list<Cell>>}
 * @phpstan-type Base array{distance:int, x:int, y:int, arrow:string}
 * @phpstan-type Action array{id:string, label:string, callback:string, group:string, dir:?string}
 * @phpstan-type LegendEntry array{marker:string, label:string, biome:?int}
 * @phpstan-type LiveMap array{
 *     error:?string, center:?array{x:int, y:int, biome:?int}, window:?array{x0:int, y0:int, size:int},
 *     cells:list<list<Cell>>, distance_to_base:?Base, stats:array{health:float, tired:float},
 *     actions:list<Action>, legend:list<LegendEntry>
 * }
 */
class LiveMapService
{
    /** Окно карты — то же, что у бота (не баланс: размер экрана). */
    public const SIZE   = 12;
    public const OFFSET = 6;

    /** Границы мира по обеим осям. */
    public const WORLD_MIN = 0;
    public const WORLD_MAX = 999;

    /** Коды клеток — ступени приоритетной лестницы. */
    public const CODE_OUT           = 'out';
    public const CODE_PLAYER        = 'player';
    public const CODE_OWN_BASE      = 'own_base';
    public const CODE_SETTLEMENT    = 'settlement';
    public const CODE_NODE          = 'node';
    public const CODE_NODE_COOLDOWN = 'node_cooldown';
    public const CODE_BAIT          = 'bait';
    public const CODE_NPC           = 'npc';
    public const CODE_FOG           = 'fog';
    public const CODE_FOREIGN_BASE  = 'foreign_base';
    public const CODE_BIOME         = 'biome';

    /** Группы действий экрана. */
    public const GROUP_DIR   = 'dir';   // роза направлений
    public const GROUP_CELL  = 'cell';  // действия на своей клетке (база, добыча, хаб)
    public const GROUP_NAV   = 'nav';   // Поход, легенда, обзор
    public const GROUP_WORLD = 'world'; // витрины острова (остров, события, дроны)

    /** Значки кодов (кроме биома и поселения — у них свой значок). */
    public const MARKERS = [
        self::CODE_OUT           => '⬜',
        self::CODE_PLAYER        => '🙎‍♂️',
        self::CODE_OWN_BASE      => '🏕',
        self::CODE_NODE          => '☠',
        self::CODE_NODE_COOLDOWN => '⏳',
        self::CODE_BAIT          => '🎯',
        self::CODE_NPC           => '🥷',
        self::CODE_FOG           => '⬛️',
        self::CODE_FOREIGN_BASE  => '🚫',
    ];

    /** Значок поселения по умолчанию (у поселения может быть свой `icon`). */
    public const SETTLEMENT_MARKER = '🏚';

    /** Значок биома без записи в {@see BIOMES}. */
    public const UNKNOWN_BIOME_MARKER = '❓';

    /** Биомы: id в `biomes` → значок и имя. Порядок = порядок легенды. */
    public const BIOMES = [
        1 => ['🌲', 'Лес'],
        2 => ['⛰️', 'Горы'],
        3 => ['❄️', 'Тундра'],
        4 => ['🌊', 'Реки'],
        5 => ['🌴', 'Джунгли'],
        6 => ['🌾', 'Поля'],
        7 => ['🕳️', 'Пещеры'],
        8 => ['🌋', 'Вулкан'],
        9 => ['🏜️', 'Пустыни'],
    ];

    /** Легенда маркеров: значок → подпись. Порядок = порядок легенды бота. */
    public const MARKER_LEGEND = [
        ['🙎‍♂️', 'игрок'],
        ['🏕', 'ваша база'],
        ['🚫', 'чужая база'],
        ['🏚', 'поселение'],
        ['☠', 'узел (босс)'],
        ['⏳', 'узел в кулдауне'],
        ['🥷', 'NPC'],
        ['⬛️', 'не изучено'],
        ['⬜', 'за пределами мира'],
    ];

    /**
     * Направления розы: код → [dx, dy, подпись кнопки]. y растёт на юг (север = меньший y).
     * Порядок = порядок розы бота (ряды 3 · 2 · 3).
     */
    public const DIRECTIONS = [
        'northwest' => [-1, -1, '↖️ Сев-Запад'],
        'north'     => [0, -1, '⬆️ Север'],
        'northeast' => [1, -1, '↗️ Сев-Восток'],
        'west'      => [-1, 0, '⬅️ Запад'],
        'east'      => [1, 0, '➡️ Восток'],
        'southwest' => [-1, 1, '↙️ Юго-Запад'],
        'south'     => [0, 1, '⬇️ Юг'],
        'southeast' => [1, 1, '↘️ Юго-Восток'],
    ];

    public function __construct(
        private ?MapModel $mapModel = null,
        private ?ExploredCellsModel $exploredCellsModel = null,
        private ?ClaimedCellModel $claimedCellModel = null,
        private ?NpcSpawnModel $npcSpawnModel = null,
        private ?CharacterModel $characterModel = null
    ) {
    }

    /**
     * Модель экрана «Мир» персонажа.
     *
     * @return LiveMap
     */
    public function forCharacter(int $characterId): array
    {
        $character = ($this->characterModel ??= new CharacterModel())->find($characterId);
        if ($character instanceof CharacterEntity) {
            return $this->fromCharacter($character);
        }
        if (! is_array($character)) {
            return self::emptyModel('Персонаж не найден');
        }
        $row = [];
        foreach ($character as $k => $v) {
            $row[(string) $k] = $v;
        }

        return $this->fromCharacter($row);
    }

    /**
     * Модель из уже загруженной строки персонажа (бот держит её на руках).
     *
     * @param array<string, mixed>|CharacterEntity $character
     *
     * @return LiveMap
     */
    public function fromCharacter(array|CharacterEntity $character, bool $withActions = true): array
    {
        $grid = $this->grid($character);

        return $grid + [
            'distance_to_base' => $grid['center'] === null ? null : $this->nearestBase($character, $grid['center']['x'], $grid['center']['y']),
            'stats'            => [
                'health' => self::float($character['health'] ?? 0),
                'tired'  => self::float($character['tired'] ?? 0),
            ],
            'actions' => $withActions ? $this->actions($character) : [],
            'legend'  => self::legend(),
        ];
    }

    /**
     * Окно 12×12: клетки по приоритетной лестнице. Без `cell_number` или клетки в `map` —
     * `error` с прежним текстом бота и пустое окно.
     *
     * @param array<string, mixed>|CharacterEntity $character
     *
     * @return Grid
     */
    public function grid(array|CharacterEntity $character): array
    {
        $cellNumber = $character['cell_number'] ?? 0;
        if (! $cellNumber) {
            return self::emptyGrid('Нет cell_number у персонажа');
        }
        $mapRow = $this->map()->where('cell_number', $cellNumber)->first();
        if (! is_array($mapRow)) {
            return self::emptyGrid('Map не найдена для cell_number=' . self::str($cellNumber));
        }

        $pX = self::int($mapRow['coordinate_x'] ?? 0);
        $pY = self::int($mapRow['coordinate_y'] ?? 0);

        $xMin = $pX - self::OFFSET;
        $xMax = $pX + (self::OFFSET - 1);
        $yMin = $pY - self::OFFSET;
        $yMax = $pY + (self::OFFSET - 1);

        $characterId = self::int($character['id'] ?? 0);

        // Клетки, которые персонаж «изучил».
        $exploredRows = $this->explored()
            ->select('map_cell_id')
            ->where('character_id', $characterId)
            ->whereIn('map_cell_id', static function ($builder) use ($xMin, $xMax, $yMin, $yMax) {
                $builder->select('cell_number')
                    ->from('map')
                    ->where("coordinate_x >= {$xMin}")
                    ->where("coordinate_x <= {$xMax}")
                    ->where("coordinate_y >= {$yMin}")
                    ->where("coordinate_y <= {$yMax}");
            })
            ->findAll();
        $exploredSet = [];
        foreach ($exploredRows as $er) {
            if (is_array($er)) {
                $exploredSet[self::int($er['map_cell_id'] ?? 0)] = true;
            }
        }

        // Биомы клеток окна.
        $mapData = $this->map()
            ->select('cell_number, biome_id, coordinate_x, coordinate_y')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();
        $cells = [];
        foreach ($mapData as $row) {
            if (is_array($row)) {
                $cells[self::int($row['coordinate_x'] ?? 0) . '_' . self::int($row['coordinate_y'] ?? 0)] = [
                    'biome_id'    => self::int($row['biome_id'] ?? 0),
                    'cell_number' => self::int($row['cell_number'] ?? 0),
                ];
            }
        }

        // story angela-second-base-bugs-01 — ВСЕ активные базы персонажа, не одна.
        $ownBaseCells = [];
        foreach ($this->claimed()->findAllActiveCells($characterId) as $claimedRow) {
            $mapCellIdRaw = $claimedRow['map_cell_id'] ?? null;
            if (! is_numeric($mapCellIdRaw)) {
                continue;
            }
            $baseMapRow = $this->map()->find((int) $mapCellIdRaw);
            if (is_array($baseMapRow)) {
                $ownBaseCells[self::int($baseMapRow['coordinate_x'] ?? 0) . '_' . self::int($baseMapRow['coordinate_y'] ?? 0)] = true;
            }
        }

        // Чужие базы в окне.
        $otherBases    = [];
        $claimedInArea = $this->claimed()
            ->select('claimed_cells.character_id, claimed_cells.map_cell_id, map.coordinate_x, map.coordinate_y')
            ->join('map', 'map.id = claimed_cells.map_cell_id', 'left')
            ->where('claimed_cells.status', 'active')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();
        foreach ($claimedInArea as $cRow) {
            if (is_array($cRow) && self::int($cRow['character_id'] ?? 0) !== $characterId) {
                $otherBases[self::int($cRow['coordinate_x'] ?? 0) . '_' . self::int($cRow['coordinate_y'] ?? 0)] = true;
            }
        }

        // ADR-101 — активные поселения в окне (gated settlements.enabled).
        $settlementCells = [];
        if ((new \App\Services\Settlement\SettlementZoneService())->layerEnabled()) {
            foreach ((new \App\Models\SettlementModel())->allActive() as $st) {
                $sx = is_numeric($st['coordinate_x'] ?? null) ? (int) $st['coordinate_x'] : null;
                $sy = is_numeric($st['coordinate_y'] ?? null) ? (int) $st['coordinate_y'] : null;
                if ($sx === null || $sy === null) {
                    continue;
                }
                if ($sx >= $xMin && $sx <= $xMax && $sy >= $yMin && $sy <= $yMax) {
                    $settlementCells["{$sx}_{$sy}"] = is_string($st['icon'] ?? null) && $st['icon'] !== '' ? $st['icon'] : self::SETTLEMENT_MARKER;
                }
            }
        }

        // WB12 (ADR-137 «Узлы») — узлы-боссы в окне (gated world.nodes.point_mode_enabled).
        $nodeCells = [];
        if ((new GameSettingsService())->get('world.nodes.point_mode_enabled', false) === true) {
            $nodeRows = (new \App\Models\BossPointModel())
                ->select('coordinate_x, coordinate_y, status')
                ->where('coordinate_x >=', $xMin)->where('coordinate_x <=', $xMax)
                ->where('coordinate_y >=', $yMin)->where('coordinate_y <=', $yMax)
                ->whereIn('status', ['alive', 'cooldown'])
                ->findAll();
            foreach ($nodeRows as $nr) {
                if (! is_array($nr)) {
                    continue;
                }
                $nx = is_numeric($nr['coordinate_x'] ?? null) ? (int) $nr['coordinate_x'] : null;
                $ny = is_numeric($nr['coordinate_y'] ?? null) ? (int) $nr['coordinate_y'] : null;
                if ($nx === null || $ny === null) {
                    continue;
                }
                $nodeCells["{$nx}_{$ny}"] = ($nr['status'] ?? '') === 'alive' ? self::CODE_NODE : self::CODE_NODE_COOLDOWN;
            }
        }

        // S4 (ADR-139) — 🎯 cold-open приманка (gated signal_hook + level ≤ cap).
        $baitLevel = self::int($character['level'] ?? 0);
        $baitCells = (new \App\Services\Onboarding\ColdOpenSignalService())
            ->markerCellsInViewport($baitLevel, $xMin, $xMax, $yMin, $yMax);

        $npcsInArea = $this->npcsAround($pX, $pY);

        $rows = [];
        for ($localY = 0; $localY < self::SIZE; $localY++) {
            $worldY = $yMin + $localY;
            $row    = [];
            for ($localX = 0; $localX < self::SIZE; $localX++) {
                $worldX  = $xMin + $localX;
                $key     = "{$worldX}_{$worldY}";
                $known   = $cells[$key] ?? null;
                $open    = $known !== null && isset($exploredSet[$known['cell_number']]);
                $biomeId = $open ? $known['biome_id'] : null;

                if ($worldX < self::WORLD_MIN || $worldX > self::WORLD_MAX || $worldY < self::WORLD_MIN || $worldY > self::WORLD_MAX) {
                    $row[] = self::cell($worldX, $worldY, self::CODE_OUT, null, false);
                } elseif ($worldX === $pX && $worldY === $pY) {
                    $row[] = self::cell($worldX, $worldY, self::CODE_PLAYER, $biomeId, $open);
                } elseif (isset($ownBaseCells[$key])) {
                    $row[] = self::cell($worldX, $worldY, self::CODE_OWN_BASE, $biomeId, $open);
                } elseif (isset($settlementCells[$key])) {
                    $row[] = self::cell($worldX, $worldY, self::CODE_SETTLEMENT, $biomeId, $open, $settlementCells[$key]);
                } elseif (isset($nodeCells[$key])) {
                    $row[] = self::cell($worldX, $worldY, $nodeCells[$key], $biomeId, $open);
                } elseif (isset($baitCells[$key])) {
                    // Приманку видно и на неоткрытой клетке — это направленный крючок.
                    $row[] = self::cell($worldX, $worldY, self::CODE_BAIT, $biomeId, $open);
                } elseif (isset($npcsInArea[$key])) {
                    $row[] = self::cell($worldX, $worldY, self::CODE_NPC, $biomeId, $open);
                } elseif (! $open) {
                    $row[] = self::cell($worldX, $worldY, self::CODE_FOG, null, false);
                } elseif (isset($otherBases[$key])) {
                    $row[] = self::cell($worldX, $worldY, self::CODE_FOREIGN_BASE, $biomeId, true);
                } else {
                    $row[] = self::cell($worldX, $worldY, self::CODE_BIOME, $biomeId, true, self::biomeMarker($biomeId));
                }
            }
            $rows[] = $row;
        }

        $centerCell = $cells["{$pX}_{$pY}"] ?? null;

        return [
            'error'  => null,
            'center' => ['x' => $pX, 'y' => $pY, 'biome' => $centerCell === null ? null : $centerCell['biome_id']],
            'window' => ['x0' => $xMin, 'y0' => $yMin, 'size' => self::SIZE],
            'cells'  => $rows,
        ];
    }

    /**
     * Ближайшая (по Чебышёву) активная своя база и стрелка к ней; null — баз нет.
     * Координаты игрока — из `map` по `cell_number`, если не переданы.
     *
     * @param array<string, mixed>|CharacterEntity $character
     *
     * @return Base|null
     */
    public function nearestBase(array|CharacterEntity $character, ?int $pX = null, ?int $pY = null): ?array
    {
        // story angela-second-base-bugs-01 — до БЛИЖАЙШЕЙ из всех активных, не до первой.
        $claimedRows = $this->claimed()->findAllActiveCells(self::int($character['id'] ?? 0));
        if ($claimedRows === []) {
            return null;
        }
        if ($pX === null || $pY === null) {
            $cellNumber = $character['cell_number'] ?? 0;
            if (! $cellNumber) {
                return null;
            }
            $mapRowPlayer = $this->map()->where('cell_number', $cellNumber)->first();
            if (! is_array($mapRowPlayer)) {
                return null;
            }
            $pX = self::int($mapRowPlayer['coordinate_x'] ?? 0);
            $pY = self::int($mapRowPlayer['coordinate_y'] ?? 0);
        }

        $best = null;
        foreach ($claimedRows as $claimedRow) {
            $mapCellIdRaw = $claimedRow['map_cell_id'] ?? null;
            if (! is_numeric($mapCellIdRaw)) {
                continue;
            }
            $mapRowBase = $this->map()->find((int) $mapCellIdRaw);
            if (! is_array($mapRowBase)) {
                continue;
            }
            $candX        = self::int($mapRowBase['coordinate_x'] ?? 0);
            $candY        = self::int($mapRowBase['coordinate_y'] ?? 0);
            $candDistance = max(abs($pX - $candX), abs($pY - $candY));
            if ($best === null || $candDistance < $best['distance']) {
                $best = ['distance' => $candDistance, 'x' => $candX, 'y' => $candY];
            }
        }
        if ($best === null) {
            return null;
        }

        // Идея #13 (Yupirex, 23.01.2025): emoji-стрелка направления к базе.
        return $best + ['arrow' => self::arrow($pX, $pY, $best['x'], $best['y'])];
    }

    /**
     * Действия экрана «Мир» — те же кнопки и те же гейты, что у карты бота: роза, действия
     * клетки, нав-ряд (Поход · Легенда · Обзор при world_hub), витрины острова.
     *
     * @param array<string, mixed>|CharacterEntity $character
     *
     * @return list<Action>
     */
    public function actions(
        array|CharacterEntity $character,
        ?bool $islandEnabled = null,
        ?bool $worldHub = null,
        ?bool $finalGrid = null,
        ?bool $gatherOnCompass = null
    ): array {
        $islandEnabled   ??= (new IslandPulseService())->enabled();
        $worldHub        ??= self::worldHubEnabled();
        $finalGrid       ??= BotMenuService::finalGridEnabled();
        $gatherOnCompass ??= BotMenuService::gatherOnCompassEnabled();

        $out = [];
        foreach (self::DIRECTIONS as $dir => [, , $label]) {
            $out[] = self::action('move_' . $dir, $label, 'move_dir_' . $dir, self::GROUP_DIR, $dir);
        }

        $out[] = self::action('base', '🏠 База', 'Base', self::GROUP_CELL);
        if ($gatherOnCompass) {
            // ADR-168 — метка источника входа в добычу с компаса.
            $out[] = self::action('gather', BotMenuService::actionLabel('gather'), ActionOrigin::tag('gather', ActionOrigin::FROM_COMPASS), self::GROUP_CELL);
            $out[] = self::action('actions_hub', BotMenuService::actionLabel('actionsHub'), 'characterActions', self::GROUP_CELL);
        } else {
            $out[] = self::action('actions_hub', BotMenuService::actionLabel('actionsHubCompact'), 'characterActions', self::GROUP_CELL);
        }

        $out[] = self::action('march', '🗺️ Поход', 'march', self::GROUP_NAV); // ADR-019
        if ($worldHub) {
            $out[] = self::action('legend', '❓ Легенда', 'mapLegend', self::GROUP_NAV);
            $out[] = self::action('overview', '🗺 Обзор', 'mapOverview', self::GROUP_NAV);
        }

        // S7 (ADR-145) «Остров живёт» и ADR-150 «События» — витрины острова, не персонажа.
        if ($islandEnabled) {
            $out[] = self::action('island', '🌍 Остров живёт', 'island', self::GROUP_WORLD);
        }
        if ($finalGrid) {
            $out[] = self::action('events', '🎉 События', 'events', self::GROUP_WORLD);
        }
        // Drone-discoverability story 02 — «🚁 Дроны»: killswitch, затем владение (самый горячий
        // экран игры — лишний запрос при выключенном killswitch недопустим).
        if ((new \App\Services\Player\DroneService())->isEnabled() && $this->ownsDrone(self::int($character['id'] ?? 0))) {
            $out[] = self::action('drones', '🚁 Дроны', 'droneScoutList', self::GROUP_WORLD);
        }

        return $out;
    }

    /**
     * Легенда: маркеры, затем биомы (без компаса — он в легенде бота по кнопке).
     *
     * @return list<LegendEntry>
     */
    public static function legend(): array
    {
        $out = [];
        foreach (self::MARKER_LEGEND as [$marker, $label]) {
            $out[] = ['marker' => $marker, 'label' => $label, 'biome' => null];
        }
        foreach (self::BIOMES as $id => [$marker, $label]) {
            $out[] = ['marker' => $marker, 'label' => $label, 'biome' => $id];
        }

        return $out;
    }

    /** Имя биома по id; null — неизвестный. */
    public static function biomeName(?int $biomeId): ?string
    {
        return $biomeId === null ? null : (self::BIOMES[$biomeId][1] ?? null);
    }

    /** Значок биома; неизвестный id — ❓. */
    public static function biomeMarker(?int $biomeId): string
    {
        return $biomeId === null ? self::UNKNOWN_BIOME_MARKER : (self::BIOMES[$biomeId][0] ?? self::UNKNOWN_BIOME_MARKER);
    }

    /**
     * 8-octant компас: emoji-стрелка от игрока к цели. y растёт на юг (north = меньший y).
     */
    public static function arrow(int $pX, int $pY, int $bX, int $bY): string
    {
        if ($pX === $bX && $pY === $bY) {
            return '🎯';
        }
        $dx = $bX - $pX;
        $dy = $bY - $pY;
        if ($dx === 0) {
            return $dy < 0 ? '⬆️' : '⬇️';
        }
        if ($dy === 0) {
            return $dx < 0 ? '⬅️' : '➡️';
        }
        if (abs($dx) > 2 * abs($dy)) {
            return $dx < 0 ? '⬅️' : '➡️';
        }
        if (abs($dy) > 2 * abs($dx)) {
            return $dy < 0 ? '⬆️' : '⬇️';
        }
        if ($dy < 0) {
            return $dx < 0 ? '↖️' : '↗️';
        }

        return $dx < 0 ? '↙️' : '↘️';
    }

    /** Killswitch ADR-150 Слайс 1 (navigation.world_hub.enabled). */
    public static function worldHubEnabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.world_hub.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
    }

    /**
     * Живые NPC только в 8 соседних клетках (не на клетке игрока): ключ "x_y".
     *
     * @return array<string, true>
     */
    private function npcsAround(int $pX, int $pY): array
    {
        $spawnRows = $this->npcs()
            ->where('status', 'alive')
            ->where('coordinate_x >=', $pX - 1)
            ->where('coordinate_x <=', $pX + 1)
            ->where('coordinate_y >=', $pY - 1)
            ->where('coordinate_y <=', $pY + 1)
            ->findAll();

        $result = [];
        foreach ($spawnRows as $npcRow) {
            if (! is_array($npcRow)) {
                continue;
            }
            $nx = self::int($npcRow['coordinate_x'] ?? 0);
            $ny = self::int($npcRow['coordinate_y'] ?? 0);
            if ($nx === $pX && $ny === $pY) {
                continue;
            }
            $result["{$nx}_{$ny}"] = true;
        }

        return $result;
    }

    private function ownsDrone(int $characterId): bool
    {
        $droneRow = (new \App\Models\CraftedItemsModel())->where('name_eng', 'DroneScout')->first();
        if (! is_array($droneRow)) {
            return false;
        }
        $droneId = self::int($droneRow['id'] ?? 0);
        if ($droneId <= 0) {
            return false;
        }

        return (bool) (new \App\Models\CraftedItemsLogModel())
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $droneId)
            ->where('quantity >', 0)
            ->first();
    }

    /** @return Cell */
    private static function cell(int $x, int $y, string $code, ?int $biome, bool $explored, ?string $marker = null): array
    {
        return [
            'x'        => $x,
            'y'        => $y,
            'code'     => $code,
            'biome'    => $biome,
            'marker'   => $marker ?? self::MARKERS[$code] ?? self::UNKNOWN_BIOME_MARKER,
            'explored' => $explored,
        ];
    }

    /** @return Action */
    private static function action(string $id, string $label, string $callback, string $group, ?string $dir = null): array
    {
        return ['id' => $id, 'label' => $label, 'callback' => $callback, 'group' => $group, 'dir' => $dir];
    }

    /** @return Grid */
    private static function emptyGrid(string $error): array
    {
        return ['error' => $error, 'center' => null, 'window' => null, 'cells' => []];
    }

    /** @return LiveMap */
    private static function emptyModel(string $error): array
    {
        return self::emptyGrid($error) + [
            'distance_to_base' => null,
            'stats'            => ['health' => 0.0, 'tired' => 0.0],
            'actions'          => [],
            'legend'           => self::legend(),
        ];
    }

    private function map(): MapModel
    {
        return $this->mapModel ??= new MapModel();
    }

    private function explored(): ExploredCellsModel
    {
        return $this->exploredCellsModel ??= new ExploredCellsModel();
    }

    private function claimed(): ClaimedCellModel
    {
        return $this->claimedCellModel ??= new ClaimedCellModel();
    }

    private function npcs(): NpcSpawnModel
    {
        return $this->npcSpawnModel ??= new NpcSpawnModel();
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function float(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
