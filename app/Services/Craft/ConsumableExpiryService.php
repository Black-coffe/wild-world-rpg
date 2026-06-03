<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-094 — «Устаревание расходников»: время-устаревание скрафченных МЕДИКАМЕНТОВ
 * (crafted_items.type='drug') с ДЕГРАДАЦИЕЙ эффекта (предмет НЕ теряется).
 *
 * Зеркало философии свежести еды (V10/ADR-035 `FoodBuffService`), но для лечения:
 *  - при крафте медикамента ставится `crafted_items_log.durability_time` =
 *    today + shelfLifeDays (template `crafted_items.durability_time` дней, либо
 *    fallback `craft.expiry.default_shelf_days` если у рецепта нет шелф-лайфа);
 *  - при использовании просроченного медикамента (`UsePharmacyAction`) heal-эффект
 *    умножается на `stalePercent`% (предмет остаётся, durability_count не трогается).
 *
 * Lazy-expiry (как еда): отдельный крон не нужен — годность считается на лету по
 * сравнению today с durability_time (DATE-гранулярность, включительно).
 *
 * Еда (perishable) остаётся под `FoodBuffService` (свежесть → сытость), оружие/броня —
 * под `current_durability` (износ по использованию). Здесь — только медикаменты.
 *
 * Весь баланс live-tunable через GameSettings `craft.expiry.*` (ADR-024): killswitch,
 * % эффекта у просрочки, fallback-срок. GameSettingsService::get деградирует к default
 * при отсутствии ключа/БД.
 */
final class ConsumableExpiryService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch механики устаревания медикаментов. false → durability_time не ставится и не деградирует. */
    public function enabled(): bool
    {
        $v = $this->settings->get('craft.expiry.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Какой % эффекта даёт просроченный медикамент (50 = вдвое слабее). Clamp 0..100. */
    public function stalePercent(): int
    {
        $v = $this->intSetting('craft.expiry.stale_effect_percent', 50);
        return max(0, min(100, $v));
    }

    /** Fallback-срок годности (дней) для медикаментов без template durability_time. */
    public function defaultShelfDays(): int
    {
        return max(1, $this->intSetting('craft.expiry.default_shelf_days', 30));
    }

    /**
     * Срок годности (дней) для предмета: template durability_time (если >0), иначе
     * fallback defaultShelfDays().
     */
    public function shelfLifeDays(?int $templateDays): int
    {
        return ($templateDays !== null && $templateDays > 0) ? $templateDays : $this->defaultShelfDays();
    }

    /**
     * Дата (Y-m-d) до которой медикамент годен, если скрафчен сейчас. null если
     * механика выключена (caller тогда не ставит durability_time → бессрочно, как раньше).
     */
    public function expiryDateFor(?int $templateDays, ?int $nowTs = null): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $ts = ($nowTs ?? time()) + ($this->shelfLifeDays($templateDays) * 86400);
        return date('Y-m-d', $ts);
    }

    /**
     * Просрочен ли экземпляр. null/пусто durability_time = бессрочно (legacy / медикамент
     * без срока). DATE-гранулярность: годен пока today <= durability_time (включительно).
     */
    public function isExpired(mixed $durabilityTime, ?int $nowTs = null): bool
    {
        if (! is_string($durabilityTime) || $durabilityTime === '') {
            return false;
        }
        $today = date('Y-m-d', $nowTs ?? time());
        return $today > substr($durabilityTime, 0, 10);
    }

    /**
     * Множитель эффекта с учётом просрочки. Свежо / выключено / без срока → 1.0.
     * Просрочено → stalePercent/100.
     */
    public function effectMultiplier(mixed $durabilityTime, ?int $nowTs = null): float
    {
        if (! $this->enabled() || ! $this->isExpired($durabilityTime, $nowTs)) {
            return 1.0;
        }
        return $this->stalePercent() / 100;
    }

    /**
     * Деградировать карту эффектов ($key => int change) по множителю просрочки.
     * Сохраняет знак; ненулевой эффект не схлопывается в 0 (min |1|), чтобы предмет
     * всё ещё что-то делал. Свежо/выключено → возвращает $effects без изменений.
     *
     * @param array<string,int> $effects
     * @return array<string,int>
     */
    public function degradeEffects(array $effects, mixed $durabilityTime, ?int $nowTs = null): array
    {
        $m = $this->effectMultiplier($durabilityTime, $nowTs);
        if ($m >= 1.0) {
            return $effects;
        }
        $out = [];
        foreach ($effects as $k => $v) {
            if ($v === 0) {
                $out[$k] = 0;
                continue;
            }
            $scaled = (int) round($v * $m);
            if ($scaled === 0) {
                $scaled = $v > 0 ? 1 : -1;
            }
            $out[$k] = $scaled;
        }
        return $out;
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }
}
