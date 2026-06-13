<?php

declare(strict_types=1);

namespace App\Services\Bases;

use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-095 Фаза 2 (DORMANT) — жизненный цикл базы: срок жизни (base-TTL) + конфиг налог-каскада.
 *
 * Чистый GameSettings-reader (как BaseLimitService / ConsumableExpiryService). Под killswitch'ами
 * `buildings.lifecycle.*` (по умолчанию OFF → byte-identical поведение). Активация — решение
 * владельца после валидации баланса (Gate-1: tunable + dormant + мягкие дефолты).
 *
 * base-TTL: база живёт ttlDaysFor(level) дней с последнего визита (claimed_cells.last_visited_at).
 * Истекла → крон сносит базу со всеми постройками. Мягкий пол ttl_min_days защищает казуалов.
 *
 * ADR-125 (E26) — warn-слой «мягкого режима»: ОТДЕЛЬНЫЙ killswitch `warn_enabled` для двухфазной
 * активации (сначала только предупреждения за warn_days дней до сноса с CTA «зайди — таймер
 * сбросится», потом `ttl_enabled` для самого сноса). Математика истечения (expiryTs) вынесена
 * из-под killswitch'ей, чтобы предупреждения считались при warn_enabled ON / ttl_enabled OFF.
 *
 * Налог-каскад: при cascade ON после tax_cascade_grace_days дней неуплаты подряд уничтожается
 * наименьшая база (логика — в TaxCollectionHandler; здесь только конфиг).
 */
final class BaseLifecycleService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    // ── base-TTL ───────────────────────────────────────────────────────────

    /** Killswitch срока жизни базы. false → базы вечны (dormant). */
    public function ttlEnabled(): bool
    {
        return $this->boolSetting('buildings.lifecycle.ttl_enabled', false);
    }

    /** Сколько дней жизни базы даёт один уровень персонажа (ТЗ: «level дней»). */
    public function ttlDaysPerLevel(): int
    {
        return max(0, $this->intSetting('buildings.lifecycle.ttl_days_per_level', 1));
    }

    /** Мягкий пол срока жизни базы (дней) независимо от уровня (анти-churn). */
    public function ttlMinDays(): int
    {
        return max(1, $this->intSetting('buildings.lifecycle.ttl_min_days', 30));
    }

    /** Итоговый срок жизни базы (дней) для уровня: max(пол, level × дней/уровень). */
    public function ttlDaysFor(int $level): int
    {
        $byLevel = max(0, $level) * $this->ttlDaysPerLevel();
        return max($this->ttlMinDays(), $byLevel);
    }

    /**
     * Момент истечения базы (unix ts) = last_visited_at + ttlDaysFor(level) дней.
     * НЕ зависит от killswitch'ей (чистая математика) — нужен и для warn-слоя. null — нет визита.
     */
    private function expiryTs(mixed $lastVisitedAt, int $level): ?int
    {
        $ts = $this->parseTs($lastVisitedAt);
        if ($ts === null) {
            return null;
        }
        return $ts + $this->ttlDaysFor($level) * 86400;
    }

    /**
     * Просрочена ли база (TTL включён И now > истечение).
     * ttl_enabled OFF / пустой visit → false (база не сносится).
     */
    public function isExpired(mixed $lastVisitedAt, int $level, ?int $nowTs = null): bool
    {
        if (! $this->ttlEnabled()) {
            return false;
        }
        $expiry = $this->expiryTs($lastVisitedAt, $level);
        if ($expiry === null) {
            return false;
        }
        return ($nowTs ?? time()) > $expiry;
    }

    /**
     * Сколько дней осталось до сноса базы (для UI). null — оба killswitch'а выключены / нет визита.
     * Показываем остаток когда включён ttl_enabled ЛИБО warn_enabled (в warn-фазе игрок видит
     * обратный отсчёт). 0 — истекает сегодня/просрочена.
     */
    public function daysRemaining(mixed $lastVisitedAt, int $level, ?int $nowTs = null): ?int
    {
        if (! $this->ttlEnabled() && ! $this->warnEnabled()) {
            return null;
        }
        $expiry = $this->expiryTs($lastVisitedAt, $level);
        if ($expiry === null) {
            return null;
        }
        $left = $expiry - ($nowTs ?? time());
        return $left <= 0 ? 0 : (int) ceil($left / 86400);
    }

    // ── warn-слой (ADR-125 / E26) ───────────────────────────────────────────

    /** Killswitch фазы предупреждений (отдельно от сноса). false → предупреждения не шлются. */
    public function warnEnabled(): bool
    {
        return $this->boolSetting('buildings.lifecycle.warn_enabled', false);
    }

    /** За сколько дней до истечения начинать предупреждать (окно предупреждения). */
    public function warnDays(): int
    {
        return max(0, $this->intSetting('buildings.lifecycle.warn_days', 7));
    }

    /** Минимум дней между предупреждениями одной базе (анти-спам / эскалация). */
    public function warnCooldownDays(): int
    {
        return max(1, $this->intSetting('buildings.lifecycle.warn_cooldown_days', 3));
    }

    /**
     * Слать ли предупреждение этой базе сейчас:
     *  - warn_enabled ON,
     *  - до истечения осталось ≤ warn_days (включая уже просроченные),
     *  - с прошлого предупреждения прошло ≥ warn_cooldown_days (троттлинг).
     * Визит сбрасывает last_warned_at (BaseService::touchVisit) → следующий простой предупреждает заново.
     */
    public function shouldWarn(mixed $lastVisitedAt, int $level, mixed $lastWarnedAt, ?int $nowTs = null): bool
    {
        if (! $this->warnEnabled()) {
            return false;
        }
        $expiry = $this->expiryTs($lastVisitedAt, $level);
        if ($expiry === null) {
            return false;
        }
        $now = $nowTs ?? time();
        // ещё не в окне предупреждения (до истечения больше warn_days дней).
        if ($expiry - $now > $this->warnDays() * 86400) {
            return false;
        }
        // троттлинг: не чаще раза в warn_cooldown_days.
        $lastWarn = $this->parseTs($lastWarnedAt);
        if ($lastWarn !== null && ($now - $lastWarn) < $this->warnCooldownDays() * 86400) {
            return false;
        }
        return true;
    }

    // ── налог-каскад (конфиг) ──────────────────────────────────────────────

    /** Killswitch налог-каскада до уничтожения базы. false → удаляется постройка (текущее). */
    public function taxCascadeEnabled(): bool
    {
        return $this->boolSetting('buildings.lifecycle.tax_cascade_enabled', false);
    }

    /** Сколько дней неуплаты налога подряд до уничтожения наименьшей базы. */
    public function taxCascadeGraceDays(): int
    {
        return max(1, $this->intSetting('buildings.lifecycle.tax_cascade_grace_days', 3));
    }

    /**
     * E23/ADR-122 — killswitch per-base налога. false → налог агрегируется по character_id
     * и статус ставится на ВСЕ постройки разом (текущее, byte-identical). true → налог
     * считается/списывается/статусится per-base (по map_cell_id) — одна недофинансированная
     * база не морозит производство на других. Для одно-базовых игроков ON ≡ OFF (byte-identical).
     */
    public function taxPerBaseEnabled(): bool
    {
        return $this->boolSetting('buildings.tax.per_base_enabled', false);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function parseTs(mixed $v): ?int
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts === false ? null : $ts;
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $v = $this->settings->get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }
}
