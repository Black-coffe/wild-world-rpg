<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * V24 (ADR-056) — NPC-страховой агент на базе: pre-paid вечный полис на
 * конкретные строки `crafted_items_log` (тип ∈ eligible_types).
 *
 * Формула цены полиса:
 *   gold = max(min_cost_gold, ceil( (gold_required + Σ qty×market_price) × policy_fraction × log_quantity ))
 *
 *  - gold_required        — Config\CraftRecipes['<recipe>']['gold_required']
 *  - qty/market_price     — recipe.resources × resources.price (передаются снаружи)
 *  - policy_fraction      — craft_insurance.policy_fraction (default 0.2)
 *  - log_quantity         — crafted_items_log.quantity (полис покрывает всю партию)
 *  - min_cost_gold        — craft_insurance.min_cost_gold (default 50)
 *
 * Pure-функция + GameSettings-reader (зеркало NpcRepairService V23 / RobotRepairService V19).
 * Цены ресурсов передаются снаружи `array<string,int>` — сервис не лезет в БД.
 */
final class CraftInsuranceService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch слоя страховки. off → кнопка скрыта + LootProcessor игнорирует флаг. */
    public function enabled(): bool
    {
        $v = $this->settings->get('craft_insurance.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Доля от стоимости перекрафта за полис (0.2 = 20%). */
    public function policyFraction(): float
    {
        $v = $this->settings->get('craft_insurance.policy_fraction', 0.2);
        return is_numeric($v) && (float) $v > 0 ? (float) $v : 0.2;
    }

    /** Минимальная gold-цена полиса. */
    public function minCostGold(): int
    {
        $v = $this->settings->get('craft_insurance.min_cost_gold', 50);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 50;
    }

    /**
     * @return list<string> Допустимые crafted_items.type для страхования
     */
    public function eligibleTypes(): array
    {
        $v   = $this->settings->get('craft_insurance.eligible_types', 'robots,workbench,transport');
        $raw = is_string($v) ? $v : 'robots,workbench,transport';
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $t = trim($part);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out === [] ? ['robots', 'workbench', 'transport'] : $out;
    }

    /**
     * Стоимость вечного полиса для одной строки crafted_items_log.
     *
     * @param array<array-key,mixed>  $recipe          Config\CraftRecipes['<name_eng>']
     * @param array<string,int>       $resourcePrices  name => price (из resources.price)
     * @param int                     $logQuantity     crafted_items_log.quantity
     */
    public function computePolicyCost(array $recipe, array $resourcePrices, int $logQuantity): int
    {
        if ($logQuantity <= 0) {
            return $this->minCostGold();
        }

        $goldRequiredRaw = $recipe['gold_required'] ?? 0;
        $goldRequired    = is_numeric($goldRequiredRaw) ? (float) $goldRequiredRaw : 0.0;

        $resCost = 0.0;
        $template = $recipe['resources'] ?? [];
        if (is_array($template)) {
            foreach ($template as $name => $qtyRaw) {
                if (! is_string($name) || $name === '' || ! is_numeric($qtyRaw)) {
                    continue;
                }
                $price = $resourcePrices[$name] ?? 0;
                if ($price <= 0) {
                    continue;
                }
                $resCost += (float) $qtyRaw * (float) $price;
            }
        }

        $totalCraftValue = $goldRequired + $resCost;
        $gold            = (int) ceil($totalCraftValue * $this->policyFraction() * (float) $logQuantity);
        return max($this->minCostGold(), $gold);
    }
}
