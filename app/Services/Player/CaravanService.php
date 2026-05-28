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

    // ──────────────────────────────────────────────────────────────────
    // W14a (ADR-068) — multi-resource bundle («богатый караван»).
    // ──────────────────────────────────────────────────────────────────

    /** Шанс, что спавн станет богатым караваном (multi-resource bundle). Default 0 = dormant. */
    public function bundleChance(): float
    {
        $v = $this->settings->get('caravan.bundle_chance', 0.0);
        return is_numeric($v) && (float) $v >= 0 && (float) $v <= 1 ? (float) $v : 0.0;
    }

    /** Минимум товаров в богатом караване. */
    public function bundleMin(): int
    {
        $v = $this->settings->get('caravan.bundle_min', 2);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 2;
    }

    /** Максимум товаров в богатом караване (clamp ≥ bundleMin). */
    public function bundleMax(): int
    {
        $v   = $this->settings->get('caravan.bundle_max', 4);
        $max = is_numeric($v) && (int) $v >= 1 ? (int) $v : 4;
        return max($max, $this->bundleMin());
    }

    // ──────────────────────────────────────────────────────────────────
    // W14b (ADR-068) — bargain/торг: детерминированная скидка от trading_karma.
    // RNG-fence safe (0 mt_rand). Стэкается поверх caravan fix-discount, bounded cap.
    // ──────────────────────────────────────────────────────────────────

    /** Killswitch торга. Default OFF = dormant. */
    public function bargainEnabled(): bool
    {
        $v = $this->settings->get('caravan.bargain.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Максимальная скидка торга (%). */
    public function bargainMaxPct(): int
    {
        $v = $this->settings->get('caravan.bargain.max_pct', 15);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 15;
    }

    /** Делитель trading_karma для расчёта скидки торга. */
    public function bargainDivisor(): int
    {
        $v = $this->settings->get('caravan.bargain.divisor', 10);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 10;
    }

    /**
     * Скидка торга (%) = min(max_pct, intdiv(trading_karma, divisor)). Чистая формула
     * (без killswitch-проверки — caller гейтит через bargainEnabled). Negative karma → 0.
     */
    public function bargainDiscountPct(int $tradingKarma): int
    {
        $pct = intdiv(max(0, $tradingKarma), $this->bargainDivisor());
        return max(0, min($this->bargainMaxPct(), $pct));
    }

    /**
     * Цена за единицу после торга. max(1, ceil(price × (100 − pct) / 100)).
     * pct=0 → цена без изменений.
     */
    public function applyBargain(int $price, int $tradingKarma): int
    {
        if ($price <= 0) {
            return $price;
        }
        $pct = $this->bargainDiscountPct($tradingKarma);
        if ($pct <= 0) {
            return $price;
        }
        return max(1, (int) ceil($price * (100 - $pct) / 100));
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

    // ──────────────────────────────────────────────────────────────────
    // W5 (ADR-064) — Drone-offer integration. Сallback из SpawnCaravanCron
    // и CaravanLookAction. Premium для не-крафтящих игроков (markup ×3 default).
    // ──────────────────────────────────────────────────────────────────

    /**
     * Map drone-type → recipe-key + name_rus + emoji для caravan-offer.
     *
     * @return array<string, array{recipe:string, name_rus:string, emoji:string}>
     */
    public function droneOfferCatalog(): array
    {
        return [
            'scout'  => ['recipe' => 'DroneScout',  'name_rus' => 'Дрон-разведчик',  'emoji' => '🚁'],
            'cargo'  => ['recipe' => 'DroneCargo',  'name_rus' => 'Карго-дрон',      'emoji' => '🚚'],
            'repair' => ['recipe' => 'DroneRepair', 'name_rus' => 'Дрон-ремонтник', 'emoji' => '🔧'],
            'combat' => ['recipe' => 'DroneCombat', 'name_rus' => 'Боевой дрон',    'emoji' => '🛡'],
        ];
    }

    /**
     * Gold-цена готового дрона у каравана. Формула:
     *   gold = ceil(recipe.gold_required × drone.<type>.caravan_markup_multiplier).
     * Если recipe не найден или gold_required<=0 → 0.
     */
    public function computeDroneOfferGold(string $droneType): int
    {
        $catalog = $this->droneOfferCatalog();
        if (! isset($catalog[$droneType])) {
            return 0;
        }
        $recipeKey = $catalog[$droneType]['recipe'];
        $recipe    = config(\Config\CraftRecipes::class)->get($recipeKey);
        if (! is_array($recipe)) {
            return 0;
        }
        $rawGold = $recipe['gold_required'] ?? 0;
        $baseGold = is_numeric($rawGold) ? (int) $rawGold : 0;
        if ($baseGold <= 0) {
            return 0;
        }
        $markup = (new \App\Services\Player\DroneService($this->settings))->caravanMarkupMultiplierFor($droneType);
        if ($markup <= 0) {
            return 0;
        }
        return (int) ceil($baseGold * $markup);
    }

    /**
     * Проверка: offer_type принадлежит drone-семейству ('drone_scout'/'drone_cargo'/'drone_repair'/'drone_combat').
     */
    public function isDroneOfferType(string $offerType): bool
    {
        return in_array(
            $offerType,
            ['drone_scout', 'drone_cargo', 'drone_repair', 'drone_combat'],
            true
        );
    }

    /**
     * Извлекает drone-type из offer_type. 'drone_scout' → 'scout'.
     * Возвращает пустую строку для не-drone offer_type.
     */
    public function droneTypeFromOffer(string $offerType): string
    {
        if (! $this->isDroneOfferType($offerType)) {
            return '';
        }
        return substr($offerType, strlen('drone_'));
    }
}
