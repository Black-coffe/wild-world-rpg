<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * V18 (ADR-049) — Robotics T2: tier-aware поведение роботов.
 *
 * Реальных семей роботов две: explorer (открывает клетки карты) и gatherer
 * (добывает с claimed-клеток). В каждой — T1 (вход) и T2 (специализированный):
 *   - explorer:  RobotExplorer (T1)  → RobotScout (T2, больше клеток + recon)
 *   - gatherer:  RobotGatherer (T1)  → RobotIndustrial (T2, больше выхода + клеток)
 *
 * Чистый GameSettings-reader (зеркало FoodBuffService/SpecializationService):
 * множители live-tunable (category=resources, ADR-024). Сюда же мигрированы
 * скаляры баланса роботов из Config\GameBalance (cells_base/per_level/random_percent),
 * чтобы убрать ADR-024 drift и оживить per-level cells-rate (фикс 999-бага).
 *
 * completion-хендлеры резолвят `crafted_items.name_eng` запущенного робота (из
 * `task_settings.crafted_item_id`) и спрашивают множитель здесь. Неизвестный/T1/
 * null робот или выключенный killswitch → нейтральные значения (1.0 / 0) = 0 регрессии.
 */
final class RobotService
{
    /** name_eng → семья + тир. T2-роботы несут бонусы из GameSettings. */
    private const ROBOTS = [
        'RobotExplorer'   => ['family' => 'explorer', 'tier' => 1],
        'RobotScout'      => ['family' => 'explorer', 'tier' => 2],
        'RobotGatherer'   => ['family' => 'gatherer', 'tier' => 1],
        'RobotIndustrial' => ['family' => 'gatherer', 'tier' => 2],
    ];

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch слоя T2-роботов. Off → T2 ведёт себя как T1 (множители 1.0/0). */
    public function t2Enabled(): bool
    {
        $v = $this->settings->get('robots.t2.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Известен ли робот по name_eng. */
    public function isKnownRobot(?string $nameEn): bool
    {
        return $nameEn !== null && isset(self::ROBOTS[$nameEn]);
    }

    /** T2-ли робот по name_eng. */
    public function isT2(?string $nameEn): bool
    {
        return $nameEn !== null && isset(self::ROBOTS[$nameEn]) && self::ROBOTS[$nameEn]['tier'] === 2;
    }

    // ── Мигрированные скаляры баланса (бывш. Config\GameBalance) ──────────────

    /** Базовое число клеток разведки в час (на L1 мастерской). */
    public function explorationCellsBase(): int
    {
        return max(0, $this->intSetting('robots.exploration.cells_base', 50));
    }

    /** +N клеток/час за каждый уровень мастерской свыше 1. */
    public function explorationCellsPerLevel(): int
    {
        return max(0, $this->intSetting('robots.exploration.cells_per_level', 10));
    }

    /** ±N% разброс к итоговому количеству ресурсов добычи. */
    public function gatheringRandomPercent(): int
    {
        return max(0, $this->intSetting('robots.gathering.random_percent', 20));
    }

    // ── T2-дифференциация (по name_eng) ──────────────────────────────────────

    /**
     * Множитель числа открываемых клеток для explorer-робота.
     * RobotScout (T2) → robots.scout.cells_multiplier; иначе → 1.0.
     */
    public function explorationCellsMultiplierFor(?string $nameEn): float
    {
        if (! $this->t2Enabled() || $nameEn !== 'RobotScout') {
            return 1.0;
        }
        $v = $this->floatSetting('robots.scout.cells_multiplier', 1.6);
        return $v > 0 ? $v : 1.0;
    }

    /**
     * Множитель выхода ресурсов для gatherer-робота.
     * RobotIndustrial (T2) → robots.industrial.yield_multiplier; иначе → 1.0.
     */
    public function gatheringYieldMultiplierFor(?string $nameEn): float
    {
        if (! $this->t2Enabled() || $nameEn !== 'RobotIndustrial') {
            return 1.0;
        }
        $v = $this->floatSetting('robots.industrial.yield_multiplier', 1.5);
        return $v > 0 ? $v : 1.0;
    }

    /**
     * Доп. клетки обхода для gatherer-робота сверх уровня мастерской.
     * RobotIndustrial (T2) → robots.industrial.extra_cells; иначе → 0.
     */
    public function gatheringExtraCellsFor(?string $nameEn): int
    {
        if (! $this->t2Enabled() || $nameEn !== 'RobotIndustrial') {
            return 0;
        }
        return max(0, $this->intSetting('robots.industrial.extra_cells', 1));
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
