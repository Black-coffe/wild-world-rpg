<?php

declare(strict_types=1);

namespace App\Services\Bases;

use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Services\Coverage\CommunicationTowerCoverageService;
use App\Services\Housing\BaseCampDecorService;
use App\Services\Onboarding\OnboardingChainService;
use App\Services\Onboarding\OnboardingHintService;
use App\Services\Web\VirtualIdentityService;

/**
 * w2-n4-base-01 (ADR-190) — ядро экранов базы без `chat_id` и Markdown: какую базу показать,
 * что на ней стоит и что значит «открыл базу». Из этой модели рисуют оба клиента: бот
 * ({@see \App\Services\BaseService}, `Camp\DetailedBaseInfoAction`) и веб (`/play?view=base`).
 *
 * Два правила выбора базы, как у бота:
 * - {@see resolve()} — «🏠 База»: своя клетка → она; нет баз → `no_base`; одна база — под сигналом
 *   Вышки (прежний {@see CommunicationTowerCoverageService::checkCoverage()}) или `far`; две и больше —
 *   ровно одна под сигналом своей Вышки → она, иначе `picker`.
 * - {@see resolveConstruction()} — «🏘 Постройки»: без пикера; две базы вне сигнала — `far` первой по id.
 *
 * Явный `baseId` (суффикс `_b<id>` у бота, `b` у веба) — только подсказка: доступность
 * перепроверяется {@see BaseScopeResolver::resolveForBase()} (своя, активная, на ней или под сигналом
 * её Вышки). Чужая, неактивная, недоступная база — `unavailable`, никогда не чужие данные.
 *
 * Покрытие в модели — `{covered, tower_level, distance, max}`; `null` — игрок на базе.
 *
 * @phpstan-type Coverage array{covered: bool, tower_level: int, distance: int, max: int}
 * @phpstan-type PickerBase array{base_id:int, cell:int, name:string, x:int, y:int, towerLevel:int, distance:int, maxCoverage:int, isCovered:bool}
 * @phpstan-type Resolution array{state: string, base_id: int, on_base: bool, coverage: Coverage|null, bases: list<PickerBase>, text: string}
 * @phpstan-type BuildingRow array{char_building_id:int, building_id:int, key:string, name:string, icon:string, level:int, amount:int, tax:int, type:string, bridge_callback:string}
 * @phpstan-type Decor array{name: string|null, flag: string|null, hearth: string|null, furniture: string|null, pet: string|null}
 * @phpstan-type Overview array{base: array{id:int, cell:int, x:int|null, y:int|null, biome:string, on_base:bool, days_left:int|null, count:int, tax_total:int, decor:Decor, decor_enabled:bool}, coverage: Coverage|null, buildings: list<BuildingRow>}
 */
final class BaseScreenService
{
    public const STATE_PICKER      = 'picker';
    public const STATE_BASE        = 'base';
    public const STATE_NO_BASE     = 'no_base';
    public const STATE_FAR         = 'far';
    public const STATE_UNAVAILABLE = 'unavailable';

    /**
     * Единственная покрытая база не нашлась строкой (гонка/удалённая запись): база и так покрыта,
     * дело не в положении игрока — простой повтор.
     */
    public const TEXT_PICKER_ROW_MISSING = 'Не удалось открыть базу — нажми «🏠 База» ещё раз.';

    /** Иконка кнопки постройки по `buildings.id` (как было в `DetailedBaseInfoAction`). */
    private const ICONS = [1 => '🚰', 2 => '🔥', 3 => '🏚️', 4 => '🔧', 5 => '🌱', 6 => '☀️', 7 => '🥊', 8 => '🥼'];

    private const ICON_DEFAULT = '🏠';

    private ClaimedCellModel $claimedCells;
    private CommunicationTowerCoverageService $coverage;
    private BaseLocationResolver $locations;
    private BaseBuildingsList $buildings;

    public function __construct(
        ?ClaimedCellModel $claimedCells = null,
        ?CommunicationTowerCoverageService $coverage = null,
        ?BaseLocationResolver $locations = null,
        ?BaseBuildingsList $buildings = null,
    ) {
        $this->claimedCells = $claimedCells ?? new ClaimedCellModel();
        $this->coverage     = $coverage ?? new CommunicationTowerCoverageService();
        $this->locations    = $locations ?? new BaseLocationResolver();
        $this->buildings    = $buildings ?? new BaseBuildingsList();
    }

    /**
     * Какую базу показывает «🏠 База». Без побочных эффектов: визит пишет {@see open()}.
     *
     * @return Resolution
     */
    public function resolve(int $characterId, ?int $baseId = null): array
    {
        $currentCell = $this->currentCell($characterId);
        if ($baseId !== null) {
            return $this->resolveExplicit($characterId, $currentCell, $baseId);
        }

        $onCell = $this->claimedCells->findActiveCell($characterId, $currentCell);
        if ($onCell !== null) {
            return $this->result(self::STATE_BASE, self::idOf($onCell), true);
        }

        $active = $this->claimedCells->findAllActiveCells($characterId);
        if ($active === []) {
            return $this->result(self::STATE_NO_BASE);
        }

        if (count($active) === 1) {
            $single = self::idOf($active[0]);
            $legacy = $this->coverage->checkCoverage($characterId);
            if (! empty($legacy['isCovered'])) {
                return $this->result(self::STATE_BASE, $single, false, [
                    'covered'     => true,
                    'tower_level' => self::int($legacy['towerLevel'] ?? 0),
                    'distance'    => self::int($legacy['distanceToBase'] ?? 0),
                    'max'         => self::int($legacy['maxCoverage'] ?? 0),
                ]);
            }

            return $this->result(self::STATE_FAR, $single);
        }

        $byBase  = $this->coverage->coverageByBase($characterId, $currentCell);
        $covered = array_values(array_filter($byBase, static fn (array $row): bool => $row['isCovered']));
        if (count($covered) === 1) {
            $row = $this->findBaseRow($covered[0]['base_id']);
            if ($row === null) {
                return $this->result(self::STATE_UNAVAILABLE, 0, false, null, [], self::TEXT_PICKER_ROW_MISSING);
            }

            return $this->result(self::STATE_BASE, $covered[0]['base_id'], false, self::coverageOf($covered[0]));
        }

        return $this->result(self::STATE_PICKER, 0, false, null, $byBase);
    }

    /**
     * Какую базу показывает «🏘 Постройки» (правило экрана построек бота: без пикера).
     *
     * @return Resolution
     */
    public function resolveConstruction(int $characterId, ?int $baseId = null): array
    {
        $currentCell = $this->currentCell($characterId);
        if ($baseId !== null) {
            return $this->resolveExplicit($characterId, $currentCell, $baseId);
        }

        $onCell = $this->claimedCells->findActiveCell($characterId, $currentCell);
        if ($onCell !== null) {
            return $this->result(self::STATE_BASE, self::idOf($onCell), true);
        }

        $active = $this->claimedCells->findAllActiveCells($characterId);
        if ($active === []) {
            return $this->result(self::STATE_NO_BASE);
        }

        $legacy = $this->coverage->checkCoverage($characterId);
        if (empty($legacy['isCovered'])) {
            return $this->result(self::STATE_FAR, self::idOf($active[0]));
        }

        $scoped = (new BaseScopeResolver())->resolve($characterId, $currentCell);
        if ($scoped['cell'] === null) {
            return $this->result(self::STATE_UNAVAILABLE, 0, false, null, [], (string) $scoped['text']);
        }
        foreach ($active as $row) {
            if (is_numeric($row['map_cell_id'] ?? null) && (int) $row['map_cell_id'] === $scoped['cell'] && self::idOf($row) > 0) {
                $id = self::idOf($row);

                return $this->result(self::STATE_BASE, $id, false, $this->coverageForBase($characterId, $currentCell, $id));
            }
        }

        return $this->result(self::STATE_UNAVAILABLE, 0, false, null, [], BaseScopeResolver::TEXT_AMBIGUOUS);
    }

    /**
     * Модель экрана базы. `$coverage` — из {@see resolve()}/{@see resolveConstruction()}; `null` и игрок
     * не на базе — покрытие считается по Вышке этой базы. Чужая или неактивная база — `null`.
     * Клетка базы без строки карты — `x`/`y` = `null`, `biome` = `???`.
     *
     * @param Coverage|null $coverage
     * @return Overview|null
     */
    public function overview(int $characterId, int $baseId, ?array $coverage = null): ?array
    {
        $base = $this->ownActiveBase($characterId, $baseId);
        if ($base === null) {
            return null;
        }
        $character = $this->character($characterId);
        $cell      = self::int($base['map_cell_id'] ?? 0);
        $onBase    = $cell > 0 && $cell === self::int($character['cell_number'] ?? 0);
        if (! $onBase && $coverage === null) {
            $coverage = $this->coverageForBase($characterId, self::int($character['cell_number'] ?? 0), $baseId);
        }
        if ($onBase) {
            $coverage = null;
        }

        $x = $y = null;
        $biome = '???';
        $map = $this->locations->findMapRow($cell);
        if ($map !== null) {
            $x        = self::int($map['coordinate_x'] ?? 0);
            $y        = self::int($map['coordinate_y'] ?? 0);
            $biomeRow = $this->locations->findBiomeRow(self::int($map['biome_id'] ?? 0));
            $biome    = is_array($biomeRow) && isset($biomeRow['name']) && is_scalar($biomeRow['name']) ? (string) $biomeRow['name'] : '???';
        }

        $rows      = [];
        $taxTotal  = 0;
        foreach ($this->buildings->rows($characterId, $cell) as $row) {
            $taxTotal += $row['tax'];
            $rows[] = [
                'char_building_id' => $row['id'],
                'building_id'      => $row['buildingId'],
                'key'              => $row['key'],
                'name'             => $row['name'],
                'icon'             => self::ICONS[$row['buildingId']] ?? self::ICON_DEFAULT,
                'level'            => $row['level'],
                'amount'           => $row['amount'],
                'tax'              => $row['tax'],
                'type'             => $row['type'],
                'bridge_callback'  => BaseCallbackSuffix::append('building_' . $row['buildingId'] . '_' . $row['key'], $baseId),
            ];
        }

        $decorService = new BaseCampDecorService();
        $level        = self::int($character['level'] ?? 1);

        return [
            'base' => [
                'id'            => $baseId,
                'cell'          => $cell,
                'x'             => $x,
                'y'             => $y,
                'biome'         => $biome,
                'on_base'       => $onBase,
                'days_left'     => (new BaseLifecycleService())->daysRemaining($base['last_visited_at'] ?? null, $level > 0 ? $level : 1),
                'count'         => count($rows),
                'tax_total'     => $taxTotal,
                'decor'         => $decorService->getCampDecor($characterId, $cell),
                'decor_enabled' => $decorService->enabled(),
            ],
            'coverage'  => $coverage,
            'buildings' => $rows,
        ];
    }

    /**
     * Игрок открыл экран базы — то же, что делал бот: визит (`last_visited_at`, сброс
     * `last_warned_at`), если он на самой базе; событие онбординга и одноразовые подсказки — всегда.
     * Чат подсказок — `$chatId` или чат персонажа (у веб-игрока без Telegram — виртуальный: его
     * сообщения уходят во входящие `/play`). Чужая или неактивная база — ничего.
     */
    public function open(int $characterId, int $baseId, ?int $chatId = null): void
    {
        $base = $this->ownActiveBase($characterId, $baseId);
        if ($base === null) {
            return;
        }
        $character = $this->character($characterId);
        if ($character === []) {
            return;
        }

        if (self::int($base['map_cell_id'] ?? 0) === self::int($character['cell_number'] ?? 0)) {
            $this->claimedCells->update($baseId, [
                'last_visited_at' => date('Y-m-d H:i:s'),
                'last_warned_at'  => null,
            ]);
        }

        if ($chatId === null) {
            $identity = (new VirtualIdentityService())->identityForCharacter($characterId);
            if ($identity === null) {
                return;
            }
            $chatId = $identity['telegram_id'];
        }

        (new OnboardingChainService())->recordBaseOpened($character, $chatId);

        // Одна подсказка за открытие: «первая постройка» важнее теплицы; автоматизация — своё окно уровней.
        $hints = new OnboardingHintService();
        if (! $hints->maybeSendFirstBuildHint($character, $chatId)) {
            $hints->maybeSendGreenhouseTip($character, $chatId);
        }
        $hints->maybeSendAutomationHint($character, $chatId);
    }

    // ── внутреннее ───────────────────────────────────────────────────────────

    /** @return Resolution */
    private function resolveExplicit(int $characterId, int $currentCell, int $baseId): array
    {
        $resolved = (new BaseScopeResolver())->resolveForBase($characterId, $currentCell, $baseId);
        if ($resolved['reason'] === BaseScopeResolver::REASON_UNAVAILABLE) {
            return $this->result(self::STATE_UNAVAILABLE, 0, false, null, [], (string) $resolved['text']);
        }
        if ($this->findBaseRow($baseId) === null) {
            return $this->result(self::STATE_UNAVAILABLE, 0, false, null, [], BaseScopeResolver::TEXT_UNAVAILABLE);
        }
        if ($resolved['reason'] === BaseScopeResolver::REASON_ON_BASE) {
            return $this->result(self::STATE_BASE, $baseId, true);
        }

        return $this->result(self::STATE_BASE, $baseId, false, $this->coverageForBase($characterId, $currentCell, $baseId));
    }

    /**
     * @param Coverage|null $coverage
     * @param list<PickerBase> $bases
     * @return Resolution
     */
    private function result(string $state, int $baseId = 0, bool $onBase = false, ?array $coverage = null, array $bases = [], string $text = ''): array
    {
        return ['state' => $state, 'base_id' => $baseId, 'on_base' => $onBase, 'coverage' => $coverage, 'bases' => $bases, 'text' => $text];
    }

    /** @return Coverage */
    private function coverageForBase(int $characterId, int $currentCell, int $baseId): array
    {
        foreach ($this->coverage->coverageByBase($characterId, $currentCell) as $row) {
            if ($row['base_id'] === $baseId) {
                return self::coverageOf($row);
            }
        }

        return ['covered' => false, 'tower_level' => 0, 'distance' => 0, 'max' => 0];
    }

    /**
     * @param PickerBase $row
     * @return Coverage
     */
    private static function coverageOf(array $row): array
    {
        return ['covered' => $row['isCovered'], 'tower_level' => $row['towerLevel'], 'distance' => $row['distance'], 'max' => $row['maxCoverage']];
    }

    /** @return array<string,mixed>|null */
    private function findBaseRow(int $baseId): ?array
    {
        $row = $this->claimedCells->find($baseId);
        if (! is_array($row)) {
            return null;
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function ownActiveBase(int $characterId, int $baseId): ?array
    {
        $row = $baseId > 0 ? $this->findBaseRow($baseId) : null;
        if ($row === null || self::int($row['character_id'] ?? 0) !== $characterId || ($row['status'] ?? null) !== 'active') {
            return null;
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function character(int $characterId): array
    {
        $row = (new CharacterModel())->find($characterId);
        if ($row instanceof \App\Entities\CharacterEntity) {
            $data = $row->toArray();
        } elseif (is_array($row)) {
            $data = $row;
        } else {
            return [];
        }
        $out  = [];
        foreach ($data as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private function currentCell(int $characterId): int
    {
        return self::int($this->character($characterId)['cell_number'] ?? 0);
    }

    /** @param array<string,mixed> $row */
    private static function idOf(array $row): int
    {
        return self::int($row['id'] ?? 0);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
