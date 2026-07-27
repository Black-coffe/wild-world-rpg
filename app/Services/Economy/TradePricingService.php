<?php

declare(strict_types=1);

namespace App\Services\Economy;

use App\Services\GameSettings\GameSettingsService;
use Config\GameBalance;

/**
 * ADR-157 — единая точка расчёта цен NPC-торговца на КРАФТОВЫЕ предметы.
 *
 * Заменяет две разъехавшиеся формулы, которые жили прямо в action'ах
 * (`SellCraftConfirmAction`, `BuyCraftConfirmAction`) и в паре давали
 * инвертированный спред: при нейтральной карме продажа шла по той же цене,
 * что и покупка, а складской бонус (ADR-085) делал продажу ДОРОЖЕ покупки.
 * Круг «купил → продал» печатал золото, а карма росла от суммы продажи
 * (`+цена × tradingKarmaSellBonus`) без верхней границы — то есть цена продажи
 * разгоняла сама себя до зажима 10.5× базовой.
 *
 * Правила, которые сервис держит:
 *  1. **Карма ограничена жёстко** — `[karma_min, karma_max]`. Значение вне
 *     границ нормализуется на чтении, поэтому исторические «раздутые» кармы
 *     не дают преимущества даже до нормализации данных.
 *  2. **Торговец не платит выше базовой цены.** Потолок множителя продажи —
 *     1.0: хорошая карма возвращает честную цену, но не создаёт премию.
 *  3. **Инвариант спреда:** итоговая цена продажи (уже со всеми внешними
 *     бонусами вроде складского) ВСЕГДА ниже цены покупки минимум на
 *     `min_spread_pct`. Инвариант применяется последним и не отключается
 *     настройками — любая комбинация admin-значений остаётся безубыточной
 *     для игры.
 *
 * Все числа admin-tunable (ADR-024), ключи `economy.trade.*`.
 */
final class TradePricingService
{
    public const KEY_KARMA_MIN      = 'economy.trade.karma_min';
    public const KEY_KARMA_MAX      = 'economy.trade.karma_max';
    public const KEY_SELL_MULT_MIN  = 'economy.trade.sell_multiplier_min';
    public const KEY_SELL_MULT_MAX  = 'economy.trade.sell_multiplier_max';
    public const KEY_BUY_MULT_MIN   = 'economy.trade.buy_multiplier_min';
    public const KEY_BUY_MULT_MAX   = 'economy.trade.buy_multiplier_max';
    public const KEY_MIN_SPREAD_PCT = 'economy.trade.min_spread_pct';

    private const DEFAULT_KARMA_MIN      = 0.0;
    private const DEFAULT_KARMA_MAX      = 200.0;
    private const DEFAULT_SELL_MULT_MIN  = 0.50;
    private const DEFAULT_SELL_MULT_MAX  = 1.00;
    private const DEFAULT_BUY_MULT_MIN   = 1.25;
    private const DEFAULT_BUY_MULT_MAX   = 3.00;
    private const DEFAULT_MIN_SPREAD_PCT = 0.10;

    /** Абсолютный пол спреда: даже при admin-значении 0 круг остаётся убыточным. */
    private const SPREAD_FLOOR = 0.01;

    private GameSettingsService $settings;
    private float $neutralKarma;

    public function __construct(?GameSettingsService $settings = null, ?GameBalance $balance = null)
    {
        $this->settings     = $settings ?? new GameSettingsService();
        $this->neutralKarma = (float) ($balance ?? new GameBalance())->startingTradingKarma;
    }

    /**
     * Карма в допустимых границах. Значения вне диапазона (исторические
     * раздутые, отрицательные) прижимаются к границе.
     */
    public function normalizeKarma(float $karma): float
    {
        $min = $this->setting(self::KEY_KARMA_MIN, self::DEFAULT_KARMA_MIN);
        $max = $this->setting(self::KEY_KARMA_MAX, self::DEFAULT_KARMA_MAX);
        if ($max < $min) {
            $max = $min;
        }

        return $this->clamp($karma, $min, $max);
    }

    /** Множитель цены продажи торговцу (без внешних бонусов). */
    public function sellMultiplier(float $karma): float
    {
        $mult = 1.0 + ($this->normalizeKarma($karma) - $this->neutralKarma) / 200.0;

        return $this->clamp(
            $mult,
            $this->setting(self::KEY_SELL_MULT_MIN, self::DEFAULT_SELL_MULT_MIN),
            $this->setting(self::KEY_SELL_MULT_MAX, self::DEFAULT_SELL_MULT_MAX)
        );
    }

    /** Множитель цены покупки у торговца. */
    public function buyMultiplier(float $karma): float
    {
        $mult = 1.0 + ($this->neutralKarma - $this->normalizeKarma($karma)) / 50.0;

        return $this->clamp(
            $mult,
            $this->setting(self::KEY_BUY_MULT_MIN, self::DEFAULT_BUY_MULT_MIN),
            $this->setting(self::KEY_BUY_MULT_MAX, self::DEFAULT_BUY_MULT_MAX)
        );
    }

    /** Цена покупки одной штуки у торговца. */
    public function buyUnitPrice(float $basePrice, float $karma): float
    {
        return max(0.0, $basePrice) * $this->buyMultiplier($karma);
    }

    /**
     * Цена продажи одной штуки торговцу.
     *
     * `$extraMultiplier` — внешние бонусы поверх кармы (складской бонус ADR-085).
     * Инвариант спреда применяется ПОСЛЕ них, поэтому никакой бонус не может
     * сделать продажу выгоднее покупки.
     */
    public function sellUnitPrice(float $basePrice, float $karma, float $extraMultiplier = 1.0): float
    {
        $basePrice = max(0.0, $basePrice);
        $price     = $basePrice * $this->sellMultiplier($karma) * max(0.0, $extraMultiplier);

        $spread = $this->clamp(
            $this->setting(self::KEY_MIN_SPREAD_PCT, self::DEFAULT_MIN_SPREAD_PCT),
            self::SPREAD_FLOOR,
            0.95
        );

        return min($price, $this->buyUnitPrice($basePrice, $karma) * (1.0 - $spread));
    }

    private function setting(string $key, float $default): float
    {
        $value = $this->settings->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($value, $max));
    }
}
