<?php

declare(strict_types=1);

namespace App\Services\Player\Progression;

use App\Services\GameSettings\GameSettingsService;

/**
 * S3 (ROADMAP-RETENTION-10, ADR-138) — ридер рычагов слома стены L1→L5.
 *
 * Тонкая обёртка над GameSettingsService (кеш 60s). Гейтит 4 рычага раннего прогресса по
 * killswitch `progression.early.enabled` + порогу `progression.early.level_cap`:
 *   A — gather_xp (XP за завершение добычи);
 *   B — gain_multiplier (×ранние гейны опыта/статов — компрессия ВРЕМЕНИ, не силы);
 *   C — move_cost_factor (мягче цена усталости хода);
 *   D — interrupt_penalty_factor (×штраф прерывания задачи — анти-фрустрация новичка).
 *
 * ИНВАРИАНТ byte-identical: при enabled=false ИЛИ level>=cap все геттеры возвращают
 * НЕЙТРАЛЬНЫЕ значения (множители 1.0, gather_xp 0.0) → call-site арифметика не меняется.
 * Поэтому call-site'ы безусловно вызывают геттер и умножают/прибавляют — ветвлений на стороне
 * caller'а не нужно, dormant гарантирован здесь.
 *
 * Тест-сим: 2-й аргумент конструктора $overrides (map key→value) читается вместо GameSettings —
 * чистый unit-тест логики гейта/множителей без БД (паттерн «чистая логика без mock'ов», ADR-131).
 *
 * @see \App\Database\Migrations\Adr138EarlyProgressionGameSettings
 */
final class EarlyProgressionService
{
    private ?GameSettingsService $settings;

    /** @var array<string, mixed>|null тест-оверрайды (минуя GameSettings). */
    private ?array $overrides;

    /**
     * @param array<string, mixed>|null $overrides
     */
    public function __construct(?GameSettingsService $settings = null, ?array $overrides = null)
    {
        $this->settings  = $settings;
        $this->overrides = $overrides;
    }

    /** Мастер-killswitch. */
    public function isEnabled(): bool
    {
        return $this->toBool($this->rawSetting('progression.early.enabled', false));
    }

    /** Порог: рычаги активны при level < cap. */
    public function levelCap(): int
    {
        $raw = $this->rawSetting('progression.early.level_cap', 5);

        return is_numeric($raw) ? (int) $raw : 5;
    }

    /** Новичок в ранней полосе (enabled && level < cap). */
    public function isEarly(int|float $level): bool
    {
        return $this->isEnabled() && (float) $level < (float) $this->levelCap();
    }

    /**
     * Множитель ранних гейнов опыта/статов (ход/марш/добыча). 1.0 = нейтрально.
     * Компрессия времени: ускоряет накопление, но не меняет формулу уровня.
     */
    public function gainMultiplier(int|float $level): float
    {
        return $this->isEarly($level)
            ? $this->floatSetting('progression.early.gain_multiplier', 2.0)
            : 1.0;
    }

    /**
     * Опыт за ЗАВЕРШЕНИЕ добычи (уже с учётом gain_multiplier). 0.0 = нейтрально (легаси).
     * Прибавляется к experience в GatherResultPersister.
     */
    public function gatherXpEarned(int|float $level): float
    {
        if (! $this->isEarly($level)) {
            return 0.0;
        }

        return $this->floatSetting('progression.early.gather_xp', 0.30)
            * $this->floatSetting('progression.early.gain_multiplier', 2.0);
    }

    /** Множитель цены усталости хода. 1.0 = нейтрально (легаси-цена). */
    public function moveCostFactor(int|float $level): float
    {
        return $this->isEarly($level)
            ? $this->floatSetting('progression.early.move_cost_factor', 0.80)
            : 1.0;
    }

    /** Множитель штрафа прерывания задачи. 1.0 = нейтрально (полный легаси-штраф). */
    public function interruptPenaltyFactor(int|float $level): float
    {
        return $this->isEarly($level)
            ? $this->floatSetting('progression.early.interrupt_penalty_factor', 0.0)
            : 1.0;
    }

    // ── internals ───────────────────────────────────────────────────────

    private function rawSetting(string $key, mixed $default): mixed
    {
        if ($this->overrides !== null) {
            return array_key_exists($key, $this->overrides) ? $this->overrides[$key] : $default;
        }
        $this->settings ??= new GameSettingsService();

        return $this->settings->get($key, $default);
    }

    private function floatSetting(string $key, float $activeDefault): float
    {
        $raw = $this->rawSetting($key, $activeDefault);

        return is_numeric($raw) ? (float) $raw : $activeDefault;
    }

    private function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
