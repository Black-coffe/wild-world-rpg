<?php

declare(strict_types=1);

namespace App\Services\PVE;

use Config\GameBalance;

/**
 * F2.3b Step 2 — формулы урона PvP боя.
 *
 * Извлечено из AttackPlayerAction v0.11.0 (god-class 1020 LOC):
 *   - computeDamage             (~100 LOC, бoss-метод формулы)
 *   - computeEquipmentDamage    (75% urоn'a — D_base × K_rar × F_type × F_range × F_spec)
 *   - computeArmorResistance    (sum по equipped outfits, clamp 0.9)
 *   - computeDistance           (sqrt dx²+dy²)
 *   - computeBiomeCoefficient   (новый pure-метод; legacy inline в computeDamage)
 *
 * DI: PvpFormulaService (level/stats/dodge/range/rarity/rollPercent),
 *     PvpEquipmentRepository (DB reads), GameBalance (константы).
 *
 * RNG ORDER PRESERVATION:
 *   Метод computeDamage сохраняет точный порядок mt_rand вызовов
 *   из legacy v0.11.0:
 *     1. computeEquipmentDamage → rollPercent для weapon crit_chance
 *     2. dodgeRoll  = mt_rand(0, 100)
 *     3. critRoll   = mt_rand(0, 100)            — critChance=0 (dead branch)
 *     4. oneShotRoll = mt_rand(0, 100)            — только при levelDiff ≥ threshold
 *   Это критично для fixture-fence byte-equivalent после Step 2.
 *
 * DEAD CODE NOTE:
 *   $luckyStrikeActive параметр в computeDamage ПРОБРАСЫВАЕТСЯ из
 *   simulateFight но НЕ используется внутри метода — luckyStrike multiplier
 *   применяется в RoundOrchestrator снаружи (Step 3). Сохранён для
 *   совместимости с сигнатурой.
 *
 *   $critChance = 0 хардкод в computeDamage делает if($isCrit) бранч dead
 *   с момента написания. Сохранено как есть — fix отдельным baseline-update
 *   ticket'ом.
 */
final class PvpDamageCalculator
{
    private PvpFormulaService $formulas;
    private PvpEquipmentRepository $repo;
    private GameBalance $balance;

    public function __construct(
        ?PvpFormulaService $formulas = null,
        ?PvpEquipmentRepository $repo = null,
        ?GameBalance $balance = null
    ) {
        $this->formulas = $formulas ?? new PvpFormulaService();
        $this->repo     = $repo     ?? new PvpEquipmentRepository();
        $this->balance  = $balance  ?? config('GameBalance');
    }

    /**
     * Boss-метод формулы урона.
     * Возвращает array с детальным разложением урона и rolls (для логирования
     * раундов в roundLogs).
     */
    /**
     * F1.4.1: $biome widened — BiomeEntity (BiomeModel returnType) або raw array (legacy/tests).
     *
     * @param array<string, mixed> $attacker
     * @param array<string, mixed> $defender
     * @param array<string, mixed>|\App\Entities\BiomeEntity $biome
     * @return array<string, mixed>
     */
    public function computeDamage(
        array|\App\Entities\CharacterEntity $attacker,
        array|\App\Entities\CharacterEntity $defender,
        array|\App\Entities\BiomeEntity $biome,
        bool  $luckyStrikeActive,  // see DEAD CODE NOTE in class docblock
        bool  $isFirstHit
    ): array {
        // 1) Equipment (~75%)
        $D_equip = $this->computeEquipmentDamage($attacker, $defender);

        // 2) Level bonus (~10%)
        $levelBonus = $this->formulas->computeLevelBonus($attacker, $defender);
        $D_level    = $levelBonus * $D_equip;

        // 3) Stats bonus (~10%)
        $statsBonus = $this->formulas->computeStatsBonus($attacker);
        $D_stats    = $statsBonus * $D_equip;

        // 4) Initiative (5%) — только на первый удар.
        $D_init = $isFirstHit ? (0.05 * $D_equip) : 0.0;

        $baseDamage = $D_equip + $D_level + $D_stats + $D_init;

        // Биом-коэффициент.
        $danger     = $biome['danger_level'] ?? 1;
        $biomeCoeff = $this->computeBiomeCoefficient((int) $danger);

        // Default-значения для возвращаемого массива.
        $critChance    = 0;
        $critRoll      = 0;
        $oneShotChance = 0;
        $oneShotRoll   = 0;

        // Dodge.
        $dodgeChance = $this->formulas->getDodgeChance($defender);
        $dodgeRoll   = mt_rand(0, 100);

        if ($dodgeRoll < $dodgeChance) {
            return [
                'D_equip'       => $D_equip,
                'D_level'       => $D_level,
                'D_stats'       => $D_stats,
                'D_init'        => $D_init,
                'dodgeChance'   => $dodgeChance,
                'dodgeRoll'     => $dodgeRoll,
                'critChance'    => $critChance,
                'critRoll'      => $critRoll,
                'biomeCoeff'    => $biomeCoeff,
                'oneShotChance' => $oneShotChance,
                'oneShotRoll'   => $oneShotRoll,
                'finalDamage'   => 0.0,
                'notes'         => 'Defender dodged',
            ];
        }

        $damageAfterBiome = $baseDamage * $biomeCoeff;

        // Crit (dead branch — critChance hardcoded 0; mt_rand call preserved
        // для RNG order parity с legacy).
        $critRoll = mt_rand(0, 100);
        $isCrit   = ($critRoll < $critChance);
        if ($isCrit) {
            $damageAfterBiome *= 2;
        }

        // One-shot при существенной разнице уровней.
        $levelDiff = $attacker['level'] - $defender['level'];
        if ($levelDiff >= $this->balance->oneshotLevelDiffThreshold) {
            $oneShotChance = min(
                (float) $this->balance->oneshotMaxChance,
                ($levelDiff / 1000) * 100
            );
            $oneShotRoll = mt_rand(0, 100);

            if ($oneShotRoll < $oneShotChance) {
                $damageAfterBiome = $defender['health'];
            }
        }

        $final = max(0, $damageAfterBiome);

        return [
            'D_equip'       => $D_equip,
            'D_level'       => $D_level,
            'D_stats'       => $D_stats,
            'D_init'        => $D_init,
            'dodgeChance'   => $dodgeChance,
            'dodgeRoll'     => $dodgeRoll,
            'critChance'    => $critChance,
            'critRoll'      => $critRoll,
            'biomeCoeff'    => $biomeCoeff,
            'oneShotChance' => $oneShotChance,
            'oneShotRoll'   => $oneShotRoll,
            'finalDamage'   => $final,
            'notes'         => $isCrit ? 'Crit!' : '',
        ];
    }

    /**
     * 75% урона: D_base × K_rar × F_type × F_range × F_spec(crit).
     * Контракт идентичен legacy.
     */
    public function computeEquipmentDamage(array|\App\Entities\CharacterEntity $attacker, array|\App\Entities\CharacterEntity $defender): float
    {
        $weapon = $this->repo->getEquippedWeapon((int) $attacker['id']);
        if (!$weapon) {
            // Fists.
            $D_base      = 2.0;
            $rarityCoeff = 1.0;
            $damageType  = 'Physical';
            $rangeVal    = 1.0;
            $critChance  = 0.0;
        } else {
            $D_base      = (float) ($weapon['damage_value']  ?? 5.0);
            $rarity      = $weapon['rarity']                 ?? 'Common';
            $rarityCoeff = $this->formulas->getRarityCoefficient($rarity);
            $damageType  = $weapon['damage_type']            ?? 'Physical';
            $rangeVal    = (float) ($weapon['range_value']   ?? 1.0);

            $weaponCrit  = !empty($weapon['crit_chance']) ? (float) $weapon['crit_chance'] : 0.0;
            $critChance  = min($weaponCrit, 50.0);
        }

        // Armor resistance.
        $outfits = $this->repo->getEquippedOutfitsWithDetails((int) $defender['id']);
        $R_armor = $this->computeArmorResistance($outfits, $damageType);
        $F_type  = max(0, 1 - $R_armor);

        // Range penalty.
        $mapA     = $this->repo->getMapCell((int) $attacker['cell_number']);
        $mapB     = $this->repo->getMapCell((int) $defender['cell_number']);
        $distance = $this->computeDistance($mapA, $mapB);
        $F_range  = $this->formulas->computeRangePenalty($rangeVal, $distance);

        // Crit от оружия (mt_rand consumed here — preserve sequence!).
        $isCrit = $this->formulas->rollPercent($critChance);
        $F_spec = $isCrit ? 2.0 : 1.0;

        $D_equip = $D_base * $rarityCoeff * $F_type * $F_range * $F_spec;
        return max(0, $D_equip);
    }

    /**
     * Сумма resistance по слотам брони (clamp 0.9).
     * Pure: принимает уже-загруженные outfits, не делает DB I/O.
     *
     * Контракт идентичен legacy AttackPlayerAction::computeArmorResistance v0.11.0.
     */
    public function computeArmorResistance(array $outfits, string $damageType): float
    {
        $resSum = 0.0;
        foreach ($outfits as $outfit) {
            switch (strtolower($damageType)) {
                case 'physical':
                default:
                    $resSum += ($outfit['physical_resistance'] ?? 0) / 100.0;
                    break;
                case 'fire':
                    $resSum += ($outfit['fire_resistance']     ?? 0) / 100.0;
                    break;
                case 'poison':
                    $resSum += ($outfit['poison_resistance']   ?? 0) / 100.0;
                    break;
            }
        }
        return min(0.9, $resSum);
    }

    /**
     * Евклидова дистанция между двумя клетками карты. Pure.
     * Возвращает 1.0 если хотя бы одна клетка отсутствует.
     */
    public function computeDistance(?array $mapA, ?array $mapB): float
    {
        if (!$mapA || !$mapB) {
            return 1.0;
        }
        $dx = abs($mapA['coordinate_x'] - $mapB['coordinate_x']);
        $dy = abs($mapA['coordinate_y'] - $mapB['coordinate_y']);
        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * Биом-коэффициент к урону: 1 + (max(1, danger) - 1) × damageBiomeBase.
     *
     * F2.3b Step 2: вынесен из inline в computeDamage (legacy строка 516).
     * Использует GameBalance.damageBiomeBase (F2.10 wire-in).
     */
    public function computeBiomeCoefficient(int $dangerLevel): float
    {
        return 1 + ((max(1, $dangerLevel) - 1) * $this->balance->damageBiomeBase);
    }
}
