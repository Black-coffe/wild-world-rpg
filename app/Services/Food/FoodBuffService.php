<?php

declare(strict_types=1);

namespace App\Services\Food;

use App\Services\GameSettings\GameSettingsService;

/**
 * V9 (ADR-034) — «Сытость» (food-buff): продуктивность от приготовленной еды.
 *
 * Чистый GameSettings-reader (как FarmingService). Поел блюдо (V8) → сыт N минут →
 * крафт быстрее + добыча щедрее. PvE-only, детерминир., pure-bonus.
 *
 * Хранение состояния — `characters.well_fed_until` (DATETIME). Lazy expiry:
 * isWellFed() сравнивает с now, отдельный cron не нужен. Множители/длительности —
 * live-tunable через GameSettings (category=craft).
 */
final class FoodBuffService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch food-buff слоя. false → buff не выдаётся и не применяется. */
    public function isEnabled(): bool
    {
        $v = $this->settings->get('food.buffs.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Длительность сытости (мин) от блюда по его snake-имени. 0 — блюдо не даёт
     * сытости (не food-buff item, напр. медикамент).
     */
    public function mealWellFedMinutes(string $snake): int
    {
        if ($snake === '') {
            return 0;
        }
        return max(0, $this->intSetting("food.{$snake}.well_fed_minutes", 0));
    }

    /** Множитель времени крафта, пока сыт (<1.0 = быстрее). */
    public function craftTimeMultiplier(): float
    {
        $v = $this->floatSetting('food.well_fed.craft_time_multiplier', 0.90);
        return $v > 0 ? $v : 1.0;
    }

    /** Множитель добычи, пока сыт (>1.0 = щедрее). */
    public function gatherYieldMultiplier(): float
    {
        $v = $this->floatSetting('food.well_fed.gather_yield_multiplier', 1.15);
        return $v > 0 ? $v : 1.0;
    }

    /**
     * Сыт ли персонаж сейчас (now < well_fed_until). Принимает значение колонки
     * characters.well_fed_until (string|null) — учитывает killswitch.
     */
    public function isWellFed(mixed $wellFedUntil, ?int $nowTs = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if (! is_string($wellFedUntil) || $wellFedUntil === '') {
            return false;
        }
        $ts = strtotime($wellFedUntil);
        if ($ts === false) {
            return false;
        }
        return $ts > ($nowTs ?? time());
    }

    /**
     * Множитель крафта с учётом сытости (1.0 если не сыт / выключено).
     */
    public function craftTimeMultiplierFor(mixed $wellFedUntil, ?int $nowTs = null): float
    {
        return $this->isWellFed($wellFedUntil, $nowTs) ? $this->craftTimeMultiplier() : 1.0;
    }

    /**
     * Множитель добычи с учётом сытости (1.0 если не сыт / выключено).
     */
    public function gatherYieldMultiplierFor(mixed $wellFedUntil, ?int $nowTs = null): float
    {
        return $this->isWellFed($wellFedUntil, $nowTs) ? $this->gatherYieldMultiplier() : 1.0;
    }

    // ── V10: свежесть (ADR-035) ───────────────────────────────────────────

    /** Killswitch механики свежести. false → блюда не черствеют (всегда полная сытость). */
    public function freshnessEnabled(): bool
    {
        $v = $this->settings->get('food.freshness.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Сколько дней приготовленное блюдо остаётся свежим. */
    public function freshDays(): int
    {
        return max(1, $this->intSetting('food.freshness.fresh_days', 2));
    }

    /** % длительности «Сытости» от зачерствевшего блюда (heal не трогается). */
    public function staleSatietyPercent(): int
    {
        $v = $this->intSetting('food.freshness.stale_satiety_percent', 50);
        return max(0, min(100, $v));
    }

    /**
     * Дата (Y-m-d) до которой блюдо свежо, если готовится сейчас. Для perishable
     * блюд — today + freshDays. Caller ставит в crafted_items_log.durability_time.
     */
    public function freshUntilDate(?int $nowTs = null): string
    {
        $ts = ($nowTs ?? time()) + ($this->freshDays() * 86400);
        return date('Y-m-d', $ts);
    }

    /**
     * Свежо ли блюдо. null durability_time = всегда свежо (консервы / не-еда / legacy).
     * DATE-гранулярность: свежо пока today <= durability_time (включительно).
     */
    public function isFresh(mixed $durabilityTime, ?int $nowTs = null): bool
    {
        if (! is_string($durabilityTime) || $durabilityTime === '') {
            return true;
        }
        $today = date('Y-m-d', $nowTs ?? time());
        return $today <= substr($durabilityTime, 0, 10);
    }

    /**
     * Итоговая длительность «Сытости» с учётом свежести. Свежо / выключено →
     * base. Зачерствело → base × staleSatietyPercent% (min 1, если base>0).
     */
    public function effectiveWellFedMinutes(int $baseMinutes, mixed $durabilityTime, ?int $nowTs = null): int
    {
        if ($baseMinutes <= 0) {
            return 0;
        }
        if (! $this->freshnessEnabled() || $this->isFresh($durabilityTime, $nowTs)) {
            return $baseMinutes;
        }
        return max(1, (int) round($baseMinutes * $this->staleSatietyPercent() / 100));
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private function floatSetting(string $key, float $default): float
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (float) $v : $default;
    }
}
