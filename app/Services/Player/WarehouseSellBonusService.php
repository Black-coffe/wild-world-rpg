<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-085 — мягкий бонус цены продажи крафта владельцам Склада (Warehouse).
 *
 * НЕ жёсткий гейт (тот был бы player-hostile): продать может любой, но владелец
 * Склада получает +% к цене. Применяется в `SellCraftConfirmAction` ПОСЛЕ
 * карма-формулы и клампа.
 *
 * Live-tunable (ADR-024, category=economy):
 *  - economy.sell.warehouse_bonus.enabled (bool, default false = killswitch off)
 *  - economy.sell.warehouse_bonus_pct (float, default 0.10 = +10%)
 *
 * Dormant: при killswitch OFF / нет Склада → множитель 1.0 (0 эффекта).
 */
final class WarehouseSellBonusService
{
    private GameSettingsService    $settings;
    private BuildingModel          $buildingModel;
    private CharacterBuildingModel $charBuildingModel;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?BuildingModel $buildingModel = null,
        ?CharacterBuildingModel $charBuildingModel = null,
    ) {
        $this->settings          = $settings ?? new GameSettingsService();
        $this->buildingModel     = $buildingModel ?? new BuildingModel();
        $this->charBuildingModel = $charBuildingModel ?? new CharacterBuildingModel();
    }

    public function isEnabled(): bool
    {
        $v = $this->settings->get('economy.sell.warehouse_bonus.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Множитель цены продажи для персонажа. 1.0 если killswitch off / нет Склада.
     * Иначе 1 + bonus_pct (bonus_pct клампится в [0, 1] — макс +100%).
     */
    public function sellPriceMultiplier(int $characterId): float
    {
        if (! $this->isEnabled()) {
            return 1.0;
        }
        if (! $this->hasWarehouse($characterId)) {
            return 1.0;
        }
        $pctRaw = $this->settings->get('economy.sell.warehouse_bonus_pct', 0.10);
        $pct    = is_numeric($pctRaw) ? (float) $pctRaw : 0.10;
        if ($pct < 0.0) {
            $pct = 0.0;
        }
        if ($pct > 1.0) {
            $pct = 1.0;
        }
        return 1.0 + $pct;
    }

    private function hasWarehouse(int $characterId): bool
    {
        $building = $this->buildingModel->where('name_en', 'Warehouse')->first();
        if (! is_array($building)) {
            return false;
        }
        $idRaw = $building['id'] ?? null;
        if (! is_numeric($idRaw) || (int) $idRaw === 0) {
            return false;
        }
        $row = $this->charBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', (int) $idRaw)
            ->first();
        return is_array($row);
    }
}
