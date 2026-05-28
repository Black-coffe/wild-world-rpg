<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsService;

/**
 * W17 (ADR-071) — PvP-дуэли: stat-equalize честной арены.
 *
 * Pure GameSettings-reader + чистый пре-процесс структуры бойца ДО неизменного
 * `PvpRoundOrchestrator::simulateFight` (ADR-070 RNG-fence-safe план: simulateFight
 * не трогаем, дуэль — новый CALLER с equalized входом). Снаряжение НЕ нормализуется
 * (грузится по реальному id внутри боя) → решает билд игрока.
 */
final class DuelService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch дуэлей. Default OFF = dormant. */
    public function enabled(): bool
    {
        $v = $this->settings->get('pvp.duel.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function baselineLevel(): int
    {
        $v = $this->settings->get('pvp.duel.baseline_level', 20);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 20;
    }

    public function baselineStat(): int
    {
        $v = $this->settings->get('pvp.duel.baseline_stat', 50);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 50;
    }

    public function baselineHealth(): int
    {
        $v = $this->settings->get('pvp.duel.baseline_health', 1000);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 1000;
    }

    /**
     * Stat-equalize: клон бойца с нормализованными level/strength/agility/intellect/
     * health/max_health/tired к baseline. id/name/cell_number/faction/прочее — сохранены
     * (нужны для идентичности исхода + загрузки снаряжения по id внутри боя).
     * Снаряжение НЕ трогается — билд игрока решает.
     *
     * @param array<string,mixed> $char
     * @return array<string,mixed>
     */
    public function equalize(array $char): array
    {
        $level  = $this->baselineLevel();
        $stat   = $this->baselineStat();
        $health = $this->baselineHealth();

        $char['level']      = $level;
        $char['strength']   = $stat;
        $char['agility']    = $stat;
        $char['intellect']  = $stat;
        $char['health']     = $health;
        $char['max_health'] = $health;
        // tired ≥ 30 → без tired-штрафа EffectService (равный отыгрыш билда).
        $char['tired']      = 100;

        return $char;
    }
}
