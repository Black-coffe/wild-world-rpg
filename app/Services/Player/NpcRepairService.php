<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * V23 (ADR-055) — NPC-мастер на базе: мгновенный gold-only ремонт tools.
 *
 * Альтернатива S5 (RepairCraftedItemAction): тот же tool, та же шкала durability,
 * но игрок платит золотом вместо ресурсов и получает результат сразу (без task-таймера).
 *
 * Формула: gold = max( min_cost_gold, ceil( Σ(qty[i] × cost_fraction × price[i]) × markup ) ).
 *  - qty[i]            — template resource amount из CraftRecipes
 *  - cost_fraction     — npc.repair.cost_fraction (default 0.5, зеркало S5)
 *  - price[i]          — resources.price (int) — рыночная цена ресурса
 *  - markup            — npc.repair.markup (default 1.5) — наценка NPC сверх рынка
 *  - min_cost_gold     — npc.repair.min_cost_gold (default 10) — защита от 0-cost
 *
 * Конструкция: pure-функция + GameSettings-reader (зеркало RobotRepairService).
 * Цены ресурсов передаются снаружи `array<string, int>` — сервис не лезет в БД,
 * тестируется юнит-тестом без CIUnitTestCase инфраструктуры.
 */
final class NpcRepairService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch слоя NPC-ремонта. off → кнопка скрыта, action заблокирован. */
    public function enabled(): bool
    {
        $v = $this->settings->get('npc.repair.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Наценка NPC сверх рыночной стоимости ресурсов (1.5 = +50%). */
    public function markup(): float
    {
        $v = $this->settings->get('npc.repair.markup', 1.5);
        return is_numeric($v) && (float) $v > 0 ? (float) $v : 1.5;
    }

    /** Доля template-ресурсов в расчёте gold-цены (0.5 = зеркало S5). */
    public function costFraction(): float
    {
        $v = $this->settings->get('npc.repair.cost_fraction', 0.5);
        return is_numeric($v) && (float) $v > 0 ? (float) $v : 0.5;
    }

    /** Минимальная gold-цена ремонта (защита от 0-cost). */
    public function minCostGold(): int
    {
        $v = $this->settings->get('npc.repair.min_cost_gold', 10);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 10;
    }

    /**
     * Стоимость NPC-ремонта в gold.
     *
     * @param array<array-key,mixed>  $recipe          запись из Config\CraftRecipes (tool)
     * @param array<string,int>       $resourcePrices  name => price (из resources.price)
     */
    public function computeGoldCost(array $recipe, array $resourcePrices): int
    {
        $template = $recipe['resources'] ?? [];
        if (! is_array($template)) {
            return $this->minCostGold();
        }

        $frac   = $this->costFraction();
        $markup = $this->markup();

        $sumRubles = 0.0;
        foreach ($template as $name => $qtyRaw) {
            if (! is_string($name) || $name === '' || ! is_numeric($qtyRaw)) {
                continue;
            }
            $qty   = (float) $qtyRaw;
            $price = $resourcePrices[$name] ?? 0;
            if ($price <= 0) {
                continue;
            }
            $sumRubles += $qty * $frac * (float) $price;
        }

        $gold = (int) ceil($sumRubles * $markup);
        return max($this->minCostGold(), $gold);
    }
}
