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
    /**
     * Машинный `crafted_items.type` → русская подпись для player-facing текста.
     * Покрывает все значения `type`, встречающиеся в БД на момент V24.2 — не только
     * то, что сейчас стоит в `craft_insurance.eligible_types`, чтобы смена настройки
     * в админке не роняла экран новым непереведённым токеном.
     *
     * @var array<string,string>
     */
    private const TYPE_LABELS = [
        'drug'          => 'лекарство',
        'weapon'        => 'оружие',
        'tool'          => 'инструмент',
        'food'          => 'еда',
        'clothing'      => 'одежда',
        'building'      => 'постройка',
        'component'     => 'компонент',
        'transport'     => 'транспорт',
        'utility'       => 'утилита',
        'decorative'    => 'декор',
        'magical item'  => 'магический предмет',
        'military'      => 'военное снаряжение',
        'accessory'     => 'аксессуар',
        'teleport'      => 'телепорт',
        'workbench'     => 'верстак',
        'robots'        => 'робот',
        'defense'       => 'защита',
        'drones'        => 'дрон',
    ];

    /**
     * Дефолт на случай отсутствия ключа в `game_settings` (свежее окружение,
     * тесты). Прод-значение живёт в БД и правится в админке — менять эту
     * константу означает менять только точку старта, не живую настройку.
     *
     * ADR-172: дроны добавлены к трём исходным типам. Карго-дрон завязан на
     * склад базы, разведчик и ремонтник — поздние инструменты ценой 8–27k
     * золота по рецепту (Config\CraftRecipes: разведчик 8000, карго 12000,
     * ремонтник 18000, боевой 27000): потеря такого при смерти бьёт больнее
     * всего по тем, кто до них дошёл, а страховать их было нельзя просто
     * потому, что дронов ещё не существовало, когда список собирали.
     */
    private const DEFAULT_ELIGIBLE_TYPES = 'robots,workbench,transport,drones';

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
        $v   = $this->settings->get('craft_insurance.eligible_types', self::DEFAULT_ELIGIBLE_TYPES);
        $raw = is_string($v) ? $v : self::DEFAULT_ELIGIBLE_TYPES;
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $t = trim($part);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out === [] ? explode(',', self::DEFAULT_ELIGIBLE_TYPES) : $out;
    }

    /**
     * Русская подпись для машинного `crafted_items.type`. Неизвестный тип
     * деградирует в сам токен — новое значение в GameSettings не должно
     * ронять экран или показывать пустоту.
     */
    public function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /**
     * @param  list<string> $types
     * @return string Русские подписи через запятую, для вставки в player-facing текст.
     */
    public function typeLabels(array $types): string
    {
        return implode(', ', array_map(fn (string $t): string => $this->typeLabel($t), $types));
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
