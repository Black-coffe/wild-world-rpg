<?php

declare(strict_types=1);

namespace App\Services\BuildingEffects;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * S11 (v0.51.193) — резолвить gameplay-effects building'ов (стартує з Workshop
 * craft_time_multiplier). Foundation для S12-S15 (BlastFurnace yield, Lab+Greenhouse,
 * Robotics, TeleportCenter).
 *
 * **Архітектура.** Per-building per-level effects живуть у GameSettings
 * (constitutional, ADR-024) під pattern'ом `building.<lower_name_en>.l<N>.<param>`.
 * Service отримує char_id → знаходить найвищий level зданого building у
 * character_buildings → cascade-lookup multiplier (з поточного level вниз до
 * найближчого defined). Default = 1.0 (no effect).
 *
 * **L4-L10 plateau (S11 user pick 2026-05-19).** Workshop ROADMAP описує L2/L3
 * effects. L4+ наследують L3 multiplier через cascade. Майбутні сесії (за межами
 * ROADMAP) можуть додати явні L4-L10 GameSettings keys.
 *
 * **DI.** Constructor приймає optional service'и для test injection (GameSettingsService
 * final → callable injection через GameSettingsService::get fallback).
 */
final class BuildingEffectsService
{
    private CharacterBuildingModel $charBuildingModel;
    private BuildingModel          $buildingModel;
    /** @var (callable(string,mixed):mixed)|null */
    private $tunableReader;

    /**
     * @param (callable(string,mixed):mixed)|null $tunableReader  Tests injection.
     *   Production: lazy `new GameSettingsService()->get($key, $default)`.
     */
    public function __construct(
        ?CharacterBuildingModel $charBuildingModel = null,
        ?BuildingModel $buildingModel = null,
        ?callable $tunableReader = null,
    ) {
        $this->charBuildingModel = $charBuildingModel ?? new CharacterBuildingModel();
        $this->buildingModel     = $buildingModel ?? new BuildingModel();
        $this->tunableReader     = $tunableReader;
    }

    /**
     * Множник craft duration для гравця.
     *
     * Завжди застосовує Workshop multiplier (S11, global для всіх рецептів).
     * Якщо передано $extraBuilding (e.g. 'Laboratory' для медичних рецептів) —
     * **multiplicatively stack** з Workshop. Приклад: Workshop L3 (0.75) ×
     * Laboratory L3 (0.75) = 0.5625 (-44% для medical craft).
     *
     * Recipe декларує $extraBuilding через поле `boost_building_time`
     * (optional). Без декларації — лише Workshop ефект.
     *
     * @return float 0..1 (default 1.0 = no effect). Застосовується ПІСЛЯ
     *   char-stats формули у GenericCraftActionStart::calculateCraftingDuration().
     */
    public function getCraftTimeMultiplier(int $charId, ?string $extraBuilding = null): float
    {
        $multiplier = 1.0;

        $workshopLevel = $this->resolveBuildingLevel($charId, 'Workshop');
        if ($workshopLevel >= 2) {
            $multiplier *= $this->resolveLevelMultiplier('workshop', $workshopLevel, 'craft_time_multiplier');
        }

        if ($extraBuilding !== null && $extraBuilding !== '' && strcasecmp($extraBuilding, 'Workshop') !== 0) {
            $extraLevel = $this->resolveBuildingLevel($charId, $extraBuilding);
            if ($extraLevel >= 2) {
                $multiplier *= $this->resolveLevelMultiplier(
                    strtolower($extraBuilding),
                    $extraLevel,
                    'craft_time_multiplier',
                );
            }
        }

        return $multiplier;
    }

    /**
     * S13b (v0.51.195) — production table для Greenhouse рівня (water cost +
     * harvest resources). Foundation для future GameSettings migration:
     * наразі read-through від `Config\GameBalance::$greenhouseLevels`.
     *
     * Caller (GreenhouseProductionHandler) використовує цей метод замість
     * прямого доступу до GameBalance, що дозволить майбутньому S## мігрувати
     * production table у `game_settings` без зміни handler logic.
     *
     * Greenhouse — НЕ pure cosmetic (на відміну від Workshop/BlastFurnace/Lab):
     * рівні **вже працюють** через GameBalance.greenhouseLevels (L1..L10:
     * different water + harvest). S13b лише оборачує доступ у service.
     *
     * @return array<string,int> e.g. `['water'=>3, 'Fruit'=>3, 'Berries'=>2]`.
     *   Empty array якщо level out of range.
     */
    public function getGreenhouseProductionForLevel(int $level): array
    {
        $cfg = config('GameBalance');
        if (! is_object($cfg) || ! property_exists($cfg, 'greenhouseLevels')) {
            return [];
        }
        $levels = $cfg->greenhouseLevels;
        if (! is_array($levels)) {
            return [];
        }
        $row = $levels[$level] ?? null;
        if (! is_array($row)) {
            return [];
        }
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $out[$key] = (int) $value;
            }
        }
        return $out;
    }

    /**
     * S12 (v0.51.194) — множник quantity output'у крафту з урахуванням рівня
     * boost-будівлі (на сьогодні: BlastFurnace для MetalFragments).
     *
     * Recipe декларує `boost_building` (наприклад 'BlastFurnace') у Config\CraftRecipes.
     * Якщо $boostBuilding=null (no boost_building у recipe) → return 1.0 (no effect).
     * Якщо у char немає такої будівлі / L1 → return 1.0.
     * Інакше — cascade lookup multiplier (як Workshop S11).
     *
     * Застосовується у GenericCraftCompletionHandler::handle() перед записом
     * у crafted_items_log: qty_final = max(qty_base, round(qty_base × multiplier)).
     */
    public function getCraftYieldMultiplier(int $charId, ?string $boostBuilding): float
    {
        if ($boostBuilding === null || $boostBuilding === '') {
            return 1.0;
        }
        $level = $this->resolveBuildingLevel($charId, $boostBuilding);
        if ($level <= 1) {
            return 1.0;
        }
        return $this->resolveLevelMultiplier(
            strtolower($boostBuilding),
            $level,
            'craft_yield_multiplier',
        );
    }

    /**
     * Знайти найвищий level building'а певного типу у character_buildings.
     * Гравець може мати кілька instances одного building (на різних cells);
     * беремо max(level) — найвищий апгрейд є «активним» для game-effects.
     *
     * @return int 0 якщо building'а немає.
     */
    private function resolveBuildingLevel(int $charId, string $buildingNameEn): int
    {
        $buildingRow = $this->buildingModel
            ->where('name_en', $buildingNameEn)
            ->first();
        if (! is_array($buildingRow)) {
            return 0;
        }
        $idRaw = $buildingRow['id'] ?? null;
        if (! is_numeric($idRaw)) {
            return 0;
        }
        $buildingId = (int)$idRaw;
        if ($buildingId === 0) {
            return 0;
        }

        $charBuilding = $this->charBuildingModel
            ->where('character_id', $charId)
            ->where('building_id', $buildingId)
            ->orderBy('level', 'DESC')
            ->first();
        if (! is_array($charBuilding)) {
            return 0;
        }
        $levelRaw = $charBuilding['level'] ?? null;
        return is_numeric($levelRaw) ? (int)$levelRaw : 0;
    }

    /**
     * Cascade-lookup multiplier: від поточного $level вниз до найближчого
     * defined GameSettings ключа. Якщо нічого не знайшли — повертає 1.0.
     *
     * Приклад (Workshop): char має L5, GameSettings має тільки L2 (0.90) і
     * L3 (0.75) → cascade від L5 → L4 (no key) → L3 (0.75) → return 0.75.
     */
    private function resolveLevelMultiplier(string $buildingKey, int $level, string $paramName): float
    {
        for ($l = $level; $l >= 2; $l--) {
            $key = "building.{$buildingKey}.l{$l}.{$paramName}";
            $val = $this->tunable($key);
            if ($val !== null) {
                return $val;
            }
        }
        return 1.0;
    }

    /**
     * Прочитати tunable значення з GameSettings; повертає null якщо запис
     * відсутній (cascade продовжується).
     */
    private function tunable(string $key): ?float
    {
        $sentinel = '__missing__';
        $reader   = $this->tunableReader ?? static fn (string $k, mixed $d): mixed
            => (new GameSettingsService())->get($k, $d);
        $val = $reader($key, $sentinel);

        if ($val === $sentinel) {
            return null;
        }
        return is_numeric($val) ? (float)$val : null;
    }
}
