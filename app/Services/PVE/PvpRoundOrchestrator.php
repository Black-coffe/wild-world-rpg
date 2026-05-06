<?php

declare(strict_types=1);

namespace App\Services\PVE;

use Config\GameBalance;

/**
 * F2.3b Step 3 — оркестратор боевого цикла simulateFight.
 *
 * Извлечено из AttackPlayerAction v0.12.0 (god-class ~890 LOC):
 *   - simulateFight             (~124 LOC while loop)
 *   - determineInitiative       (pure)
 *   - checkLuckyStrike          (early return + rollPercent)
 *   - applyLuckyStrikeDebuff    (array_rand stat debuff)
 *
 * DI: PvpDamageCalculator, PvpFormulaService, GameBalance.
 *
 * RNG ORDER PRESERVATION (КРИТИЧНО):
 *   Каждый раунд цикла потребляет mt_rand в строго определённом порядке.
 *   Любое отклонение ломает fixture-fence. Per round:
 *     [a] checkLuckyStrike:
 *         - lvlDiff >= 0  → return false (НИ одного mt_rand)
 *         - lvlDiff < 0   → 1× mt_rand (через rollPercent)
 *     [b] computeDamage:
 *         - 1× mt_rand (rollPercent для weapon crit_chance)
 *         - 1× mt_rand (dodgeRoll)
 *         - если НЕ dodge: 1× mt_rand (critRoll, dead branch)
 *         - если levelDiff >= threshold: 1× mt_rand (oneShotRoll)
 *     [c] applyLuckyStrikeDebuff (только если luckyStrikeActive):
 *         - 1× mt_rand (через array_rand для выбора стата)
 *
 *   Эти инварианты валидируются AttackPlayerActionFixtureFenceTest.
 */
final class PvpRoundOrchestrator
{
    private PvpDamageCalculator $damageCalc;
    private PvpFormulaService $formulas;
    private GameBalance $balance;

    public function __construct(
        ?PvpDamageCalculator $damageCalc = null,
        ?PvpFormulaService $formulas = null,
        ?GameBalance $balance = null
    ) {
        $this->damageCalc = $damageCalc ?? new PvpDamageCalculator();
        $this->formulas   = $formulas   ?? new PvpFormulaService();
        $this->balance    = $balance    ?? config('GameBalance');
    }

    /**
     * Симуляция PvP боя: пошаговый цикл до победителя или MAX_ROUNDS.
     *
     * Возвращает массив с выходом боя:
     *   ['type' => 'normal'|'exhausted', 'rounds' => int,
     *    'firstAttacker' => string, 'winner' => ?array, 'loser' => ?array,
     *    'roundLogs' => array]
     *
     * Контракт идентичен AttackPlayerAction::simulateFight v0.12.0.
     *
     * F1.4.1: $biome signature widened — BiomeModel returnType = BiomeEntity.
     * Старі тести passing raw array, prod — Entity. ArrayAccess trait робить $biome['x'] working для обох.
     *
     * @param array<string, mixed> $p1
     * @param array<string, mixed> $p2
     * @param array<string, mixed>|\App\Entities\BiomeEntity $biome
     */
    public function simulateFight(array $p1, array $p2, array|\App\Entities\BiomeEntity $biome): array
    {
        $roundLogs = [];

        $attacker = $this->determineInitiative($p1, $p2);
        $defender = ($attacker['id'] === $p1['id']) ? $p2 : $p1;

        $round       = 0;
        $maxRounds   = $this->balance->pvpMaxRounds;
        $winner      = null;
        $loser       = null;
        $firstName   = $attacker['name'];
        $damageBoost = 1.0;
        $isFirstHit  = true;

        while ($attacker['health'] > 0 && $defender['health'] > 0 && $round < $maxRounds) {
            $round++;

            if ($round % $this->balance->pvpRoundsPerDamageIncrease === 0) {
                $damageBoost += $this->balance->pvpDamageIncreasePerStep;
            }

            $luckyStrikeActive = $this->checkLuckyStrike($attacker, $defender);

            $calc = $this->damageCalc->computeDamage($attacker, $defender, $biome, $luckyStrikeActive, $isFirstHit);

            $damage = $calc['finalDamage'];
            $luckyStrikeApplied = false;
            if ($luckyStrikeActive) {
                $damage *= $this->balance->luckyStrikeDamageMult;
                $this->applyLuckyStrikeDebuff($defender);
                $luckyStrikeApplied = true;
            }

            $damage *= $damageBoost;

            $defHealthBefore     = $defender['health'];
            $defender['health']  = max(0, $defender['health'] - $damage);

            if ($defender['health'] <= 0) {
                $loser  = $defender;
                $winner = $attacker;
            }

            $roundLogs[] = [
                'round'    => $round,
                'attacker' => $attacker['name'],
                'defender' => $defender['name'],

                'D_equip'      => round($calc['D_equip'], 2),
                'D_level'      => round($calc['D_level'], 2),
                'D_stats'      => round($calc['D_stats'], 2),
                'D_init'       => round($calc['D_init'], 2),
                'dodgeChance'  => round($calc['dodgeChance'], 2),
                'dodgeRoll'    => $calc['dodgeRoll'],
                'critChance'   => round($calc['critChance'], 2),
                'critRoll'     => $calc['critRoll'],
                'biomeCoeff'   => round($calc['biomeCoeff'], 2),
                'oneShotChance'=> round($calc['oneShotChance'], 2),
                'oneShotRoll'  => $calc['oneShotRoll'],

                'baseDamageBeforeBoost' => round($calc['finalDamage'], 2),
                'luckyStrikeApplied'    => $luckyStrikeApplied,
                'damageBoost'           => $damageBoost,
                'finalDamage'           => round($damage, 2),

                'defenderHealthBefore'  => round($defHealthBefore, 2),
                'defenderHealthAfter'   => round($defender['health'], 2),
            ];

            if ($loser !== null) {
                break;
            }

            // Меняем роли.
            [$attacker, $defender] = [$defender, $attacker];
            $isFirstHit = false;
        }

        // Ничья: оба живы и вышли за maxRounds.
        if ($round >= $maxRounds && $attacker['health'] > 0 && $defender['health'] > 0) {
            return [
                'type'          => 'exhausted',
                'rounds'        => $round,
                'firstAttacker' => $firstName,
                'winner'        => null,
                'loser'         => null,
                'roundLogs'     => $roundLogs,
            ];
        }

        // Edge case: победитель не определился внутри цикла (например, упал
        // в самом последнем раунде до break) — определяем по health.
        if (!$winner && !$loser) {
            if ($attacker['health'] <= 0) {
                $loser  = $attacker;
                $winner = $defender;
            } elseif ($defender['health'] <= 0) {
                $loser  = $defender;
                $winner = $attacker;
            }
        }

        return [
            'type'          => 'normal',
            'rounds'        => $round,
            'firstAttacker' => $firstName,
            'winner'        => $winner,
            'loser'         => $loser,
            'roundLogs'     => $roundLogs,
        ];
    }

    /**
     * Инициатива: i = agility + (level+1)*0.5. Кто выше — бьёт первым.
     * Если ничья — побеждает первый параметр (c1).
     */
    public function determineInitiative(array|\App\Entities\CharacterEntity $c1, array|\App\Entities\CharacterEntity $c2): array
    {
        $i1 = $c1['agility'] + ($c1['level'] + 1) * 0.5;
        $i2 = $c2['agility'] + ($c2['level'] + 1) * 0.5;
        return ($i1 >= $i2) ? $c1 : $c2;
    }

    /**
     * Lucky strike check.
     *
     * RNG-ИНВАРИАНТ: при lvlDiff >= 0 возвращаем false БЕЗ mt_rand вызова —
     * это сохраняет RNG sequence parity с legacy v0.12.0. Если бы мы
     * делали `rollPercent(calculateLuckyStrikeChance(...))` напрямую,
     * mt_rand был бы потреблён даже когда chance=0.
     */
    public function checkLuckyStrike(array|\App\Entities\CharacterEntity $attacker, array|\App\Entities\CharacterEntity $defender): bool
    {
        $lvlDiff = $attacker['level'] - $defender['level'];
        if ($lvlDiff >= 0) {
            return false;
        }
        $chance = $this->formulas->calculateLuckyStrikeChance($attacker, $defender);
        return $this->formulas->rollPercent($chance);
    }

    /**
     * Lucky strike debuff: -10% к одному случайному стату (через array_rand,
     * который потребляет mt_rand state).
     *
     * @param array<string,mixed> $defender mutated by reference
     */
    public function applyLuckyStrikeDebuff(array &$defender): void
    {
        $stats = ['strength', 'agility', 'intellect'];
        $st = $stats[array_rand($stats)];
        $defender[$st] = max(1, $defender[$st] * (1 - $this->balance->luckyStrikeDebuffPercent));
    }
}
