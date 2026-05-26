<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * V25 (ADR-057) — странствующие NPC-караваны. Pure cost + GameSettings-reader
 * (зеркало V24 CraftInsuranceService / V23 NpcRepairService).
 *
 * Караван спавнится с offer'ом: 1 редкий ресурс × random qty × fix-discount-price.
 * Игрок на клетке каравана видит «🚚 Каравaн» → покупает qty за gold.
 */
final class CaravanService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    public function enabled(): bool
    {
        $v = $this->settings->get('caravan.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function maxActive(): int
    {
        $v = $this->settings->get('caravan.max_active', 5);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 5;
    }

    public function lifetimeMinutes(): int
    {
        $v = $this->settings->get('caravan.lifetime_minutes', 120);
        return is_numeric($v) && (int) $v > 0 ? (int) $v : 120;
    }

    public function discountPercent(): float
    {
        $v = $this->settings->get('caravan.discount_percent', 0.3);
        return is_numeric($v) && (float) $v >= 0 && (float) $v < 1 ? (float) $v : 0.3;
    }

    public function qtyMin(): int
    {
        $v = $this->settings->get('caravan.qty_min', 50);
        return is_numeric($v) && (int) $v > 0 ? (int) $v : 50;
    }

    public function qtyMax(): int
    {
        $v = $this->settings->get('caravan.qty_max', 200);
        $max = is_numeric($v) && (int) $v > 0 ? (int) $v : 200;
        return max($max, $this->qtyMin());
    }

    /**
     * Цена за единицу со скидкой. price = max(1, ceil(market_price × (1 - discount))).
     */
    public function computePricePerUnit(int $marketPrice): int
    {
        if ($marketPrice <= 0) {
            return 1;
        }
        $price = (int) ceil($marketPrice * (1.0 - $this->discountPercent()));
        return max(1, $price);
    }
}
