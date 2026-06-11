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

        if ($extraBuilding !== null && $extraBuilding !== '' && strcasecmp($extraBuilding, 'Workshop') !== 0
            && $this->boostBuildingEnabled($extraBuilding)) {
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
     * S15 (v0.51.197) — множник вартості телепорту з урахуванням рівня
     * TeleportationCenter (live-tunable через GameSettings).
     *
     * Базова формула — `1000 × character.level` (TeleportCostService). L1 TC =
     * baseline (multiplier 1.0). L2 → 0.85 (-15%), L3 → 0.70 (-30%). L4-L10
     * plateau cascade на L3 (як S11/S12/S13/S14).
     *
     * Якщо у char немає TeleportationCenter — повертає 1.0 (no effect; чар
     * без TC і так не може телепортуватись з base через побудоване здання,
     * але safety guard).
     *
     * Застосовується у TeleportCostService::calculateTeleportCost($level, $charId).
     */
    public function getTeleportCostMultiplier(int $charId): float
    {
        $level = $this->resolveBuildingLevel($charId, 'TeleportationCenter');
        if ($level <= 1) {
            return 1.0;
        }
        return $this->resolveLevelMultiplier(
            'teleportationcenter',
            $level,
            'teleport_cost_multiplier',
        );
    }

    /**
     * V7 (vNext) — множник УРОЖАЯ активной посадки (V6) за уровень теплицы (КАЧЕСТВО).
     *
     * L1/нет теплицы → 1.0 (baseline, 0 регрессии). L2+ → cascade-множитель
     * `building.greenhouse.l<N>.harvest_yield_multiplier` (>1.0). L4-L10 plateau на L3.
     *
     * Применяется в PlantCropCompletionHandler при сборе урожая (по текущему уровню
     * теплицы): yield_final = round(baseYield × rotation × этот множитель).
     */
    public function getGreenhouseYieldMultiplier(int $charId): float
    {
        $level = $this->resolveBuildingLevel($charId, 'Greenhouse');
        if ($level <= 1) {
            return 1.0;
        }
        return $this->resolveLevelMultiplier('greenhouse', $level, 'harvest_yield_multiplier');
    }

    /**
     * V7 (vNext) — множник ВРЕМЕНИ РОСТА активной посадки за уровень теплицы
     * (СКОРОСТЬ / scheduling). Зеркало Workshop craft-time (S11).
     *
     * L1/нет теплицы → 1.0 (baseline). L2+ → cascade-множитель
     * `building.greenhouse.l<N>.grow_time_multiplier` (<1.0). L4-L10 plateau на L3.
     *
     * Применяется в PlantCropActionStart при посадке (end_time замораживается):
     * grow_final = max(1, round(growMinutes × этот множитель)).
     */
    public function getGreenhouseGrowTimeMultiplier(int $charId): float
    {
        $level = $this->resolveBuildingLevel($charId, 'Greenhouse');
        if ($level <= 1) {
            return 1.0;
        }
        return $this->resolveLevelMultiplier('greenhouse', $level, 'grow_time_multiplier');
    }

    /**
     * E18 (ADR-118) — публичный резолв множителя эффекта здания на КОНКРЕТНОМ уровне (для витрины
     * «🏗 Развитие базы»: показать текущий и следующий эффект). Обёртка resolveLevelMultiplier
     * (level-aware, интерполяция L4-L9 ADR-042). Level<2 → 1.0 (нет уровневого бонуса).
     */
    public function effectAtLevel(string $buildingKey, int $level, string $paramName): float
    {
        return $level < 2 ? 1.0 : $this->resolveLevelMultiplier($buildingKey, $level, $paramName);
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
        // ADR-042: плавная интерполяция L4-L9 между L3 и L10-якорем (если оба заданы).
        // Устраняет L4-L10 «dead-upgrade плато». Явный per-level ключ имеет приоритет;
        // без L10-якоря — прежнее cascade-поведение (плато), тесты целы.
        if ($level >= 4 && $level <= 9) {
            $exact = $this->tunable("building.{$buildingKey}.l{$level}.{$paramName}");
            if ($exact !== null) {
                return $exact;
            }
            $v3  = $this->tunable("building.{$buildingKey}.l3.{$paramName}");
            $v10 = $this->tunable("building.{$buildingKey}.l10.{$paramName}");
            if ($v3 !== null && $v10 !== null) {
                return $v3 + ($v10 - $v3) * ($level - 3) / 7.0;
            }
        }

        // Legacy cascade: высший заданный ключ ≤ level (down to L2). Также L10 (явный якорь).
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
     * відсутній (cascade продовжується) або не числовий.
     */
    private function tunable(string $key): ?float
    {
        $val = $this->rawSetting($key);
        return is_numeric($val) ? (float)$val : null;
    }

    /**
     * Сире значення GameSettings або null, якщо ключа немає (sentinel-патерн).
     * Дозволяє відрізнити «ключ відсутній» від «ключ = falsey-значення» —
     * потрібно для опціонального killswitch (boostBuildingEnabled).
     *
     * @return mixed null = ключ відсутній.
     */
    private function rawSetting(string $key): mixed
    {
        $sentinel = '__missing__';
        $reader   = $this->tunableReader ?? static fn (string $k, mixed $d): mixed
            => (new GameSettingsService())->get($k, $d);
        $val = $reader($key, $sentinel);

        return $val === $sentinel ? null : $val;
    }

    /**
     * ADR-086 — опціональний dormant-killswitch craft-time ефекту boost-будівлі.
     *
     * Ключ `building.<lower_name_en>.boost.enabled`. **ВІДСУТНІЙ → enabled**
     * (зворотна сумісність: Workshop/Laboratory/RoboticsWorkshop не мають такого
     * ключа, їх ефект продовжує діяти). **ПРИСУТНІЙ і falsey → ефект пропускається**
     * (Солнечна станція відвантажена dormant; активація = флип у admin-UI).
     */
    private function boostBuildingEnabled(string $buildingNameEn): bool
    {
        $raw = $this->rawSetting('building.' . strtolower($buildingNameEn) . '.boost.enabled');
        if ($raw === null) {
            return true; // немає killswitch → ефект активний (Workshop/Lab/Robotics)
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }
        return false; // неочікуваний тип → dormant (безпечно)
    }
}
