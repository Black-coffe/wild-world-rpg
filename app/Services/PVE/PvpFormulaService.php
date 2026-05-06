<?php

declare(strict_types=1);

namespace App\Services\PVE;

use Config\GameBalance;

/**
 * F2.3 — чистые формулы PvP боя.
 *
 * Извлечено из AttackPlayerAction v0.5.0 (1116 LOC god-класс).
 * Все формулы pure (без БД, без I/O), используют GameBalance для
 * хардкоднутых ранее констант (F2.10 wire-in для PvP).
 *
 * Покрывает 6 building blocks из 21 константы PvP:
 *   - computeLevelBonus
 *   - computeStatsBonus
 *   - getDodgeChance
 *   - computeRangePenalty
 *   - getRarityCoefficient
 *   - rollPercent (pure-ish — mt_rand)
 *
 * Полная декомпозиция AttackPlayerAction (PvpDamageCalculator +
 * RoundOrchestrator + RewardOrchestrator + EncounterController) —
 * F2.3b sprint после write-down всех fixture-тестов на текущий
 * legacy-флоу для regression-fence.
 */
final class PvpFormulaService
{
    private GameBalance $cfg;

    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg = $cfg ?? config('GameBalance');
    }

    /**
     * Бонус по разнице уровней. Cap'нуто на ±5 ступенек × 0.02 = ±10%.
     * Возвращает значение в [-0.1, +0.1].
     */
    public function computeLevelBonus(array|\App\Entities\CharacterEntity $attacker, array|\App\Entities\CharacterEntity $defender): float
    {
        $diff    = (int) $attacker['level'] - (int) $defender['level'];
        $bounded = max(-$this->cfg->levelDiffCap, min($this->cfg->levelDiffCap, $diff));
        return $bounded * $this->cfg->levelDiffBonusPerLvl;
    }

    /**
     * Бонус от силы: 100 strength → +10%. Cap 0.1.
     */
    public function computeStatsBonus(array|\App\Entities\CharacterEntity $attacker): float
    {
        $statVal = (float) ($attacker['strength'] ?? 0);
        $bonus   = $statVal * $this->cfg->statsBonusFactor;
        return min(0.1, $bonus);
    }

    /**
     * Шанс уворота: agility × 0.25, cap на maxDodgeChance из GameBalance.
     */
    public function getDodgeChance(array|\App\Entities\CharacterEntity $defender): float
    {
        $raw = ((float) ($defender['agility'] ?? 0)) * 0.25;
        return min($raw, (float) $this->cfg->maxDodgeChancePercent);
    }

    /**
     * Штраф по дистанции: если distance > weaponRange — линейное падение.
     * 0..1.
     */
    public function computeRangePenalty(float $weaponRange, float $distance): float
    {
        if ($distance <= $weaponRange) {
            return 1.0;
        }
        $ratio = $weaponRange / $distance;
        return max(0.0, $ratio);
    }

    /**
     * Коэффициент редкости предмета: common→1.0, uncommon→1.1, rare→1.3,
     * epic→1.5, legendary→1.7.
     */
    public function getRarityCoefficient(string $rarity): float
    {
        return match (strtolower($rarity)) {
            'uncommon'  => 1.1,
            'rare'      => 1.3,
            'epic'      => 1.5,
            'legendary' => 1.7,
            default     => 1.0,
        };
    }

    /**
     * Бросок 0..100, true если выпало < chance. Pure-ish (mt_rand).
     */
    public function rollPercent(float $chance): bool
    {
        $roll = mt_rand(0, 10000) / 100.0;
        return $roll < $chance;
    }

    /**
     * Шанс лаки-страйка для слабого аттакера. Возвращает float [0, max].
     * Не делает roll — just calculates probability. Caller использует
     * rollPercent сам.
     */
    public function calculateLuckyStrikeChance(array|\App\Entities\CharacterEntity $attacker, array|\App\Entities\CharacterEntity $defender): float
    {
        $lvlDiff = (int) $attacker['level'] - (int) $defender['level'];
        if ($lvlDiff >= 0) {
            return 0.0;
        }
        $absDiff = abs($lvlDiff);
        $chance  = $absDiff * $this->cfg->luckyStrikeDiffFactor;

        $agiDiff = (float) $attacker['agility'] - (float) $defender['agility'];
        if ($agiDiff > 0) {
            $chance += $agiDiff * $this->cfg->luckyStrikeChancePerAgi * 100;
        }

        return min($chance, (float) $this->cfg->luckyStrikeMaxChance);
    }
}
