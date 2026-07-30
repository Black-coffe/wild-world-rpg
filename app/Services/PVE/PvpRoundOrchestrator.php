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
     * S26 (ADR-030): опциональный $defense — defensive structures защитника у его
     * базы. `null` (default) → поведение ИДЕНТИЧНО legacy (fixture-fence цел, т.к.
     * все текущие фикстуры без структур). Эффекты детерминированные (без mt_rand):
     * WoodenWall снижает урон по owner, BarbedFence бьёт атакующего owner'а/раунд.
     * S26b (ADR-031): WatchTower даёт owner'у множитель инициативы (determineInitiative).
     *
     * @param array<string, mixed> $p1
     * @param array<string, mixed> $p2
     * @param array<string, mixed>|\App\Entities\BiomeEntity $biome
     * @param array{owner_id:int, damage_reduction:float, fence_damage:int, initiative_bonus:float, structure_ids:list<int>}|null $defense
     */
    public function simulateFight(array $p1, array $p2, array|\App\Entities\BiomeEntity $biome, ?array $defense = null): array
    {
        // 🔴 Числа боя обязаны быть числами. Персонаж приезжает из БД через
        // Entity::toRawArray(), а тот ОБХОДИТ касты, объявленные в CharacterEntity
        // ($casts: health => float, level => integer, …) — на выходе строки
        // ('52.95', '17'). Арифметике это безразлично, но файл под strict_types=1,
        // и любой вызов round()/min()/max() со строкой — TypeError.
        // Нормализуем на входе, а не в точке падения: строки текут дальше в
        // PvpDamageCalculator, PvpFormulaService и в $winner/$loser для
        // PvpRewardOrchestrator — там те же грабли ждут в каждом вызове.
        $p1 = self::normalizeFighter($p1);
        $p2 = self::normalizeFighter($p2);

        $roundLogs = [];

        $attacker = $this->determineInitiative($p1, $p2, $defense);
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

            // S26: id текущего раунд-защитника (narrowed для сравнения с owner_id).
            $curDefenderId = is_numeric($defender['id']) ? (int) $defender['id'] : 0;

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

            // S26 (ADR-030): WoodenWall — детерминированное снижение урона по
            // защитнику-владельцу, когда он защищается у своей базы. Без mt_rand.
            if ($defense !== null
                && $curDefenderId === $defense['owner_id']
                && $defense['damage_reduction'] > 0.0
            ) {
                $damage *= (1.0 - $defense['damage_reduction']);
            }

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

            // S26 (ADR-030): BarbedFence — flat урон атакующему, когда защитник-
            // владелец выжил после удара у своей базы. Детерминированно. Если
            // ограда добивает атакующего — владелец побеждает.
            if ($loser === null
                && $defense !== null
                && $curDefenderId === $defense['owner_id']
                && $defense['fence_damage'] > 0
            ) {
                $attacker['health'] = max(0, $attacker['health'] - $defense['fence_damage']);
                if ($attacker['health'] <= 0) {
                    $loser  = $attacker;
                    $winner = $defender;
                }
            }

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
     * Числовые поля персонажа, которые читает боевой и наградный путь.
     *
     * Источник истины по типам — `$casts` в `App\Entities\CharacterEntity`;
     * `max_health`/`max_tired` каста не имеют, но нужны `PvpRewardOrchestrator`.
     * Полноту списка относительно каст-карты сторожит
     * `PvpFighterNormalizationTest::testCoversEveryNumericCastOfCharacterEntity`.
     *
     * @var array<string, 'int'|'float'>
     */
    private const NUMERIC_FIELDS = [
        'id'               => 'int',
        'telegram_user_id' => 'int',
        'level'            => 'int',
        'gold'             => 'int',
        'cell_number'      => 'int',
        'biome_id'         => 'int',
        'last_message_id'  => 'int',
        'health'           => 'float',
        'max_health'       => 'float',
        'tired'            => 'float',
        'max_tired'        => 'float',
        'experience'       => 'float',
        'strength'         => 'float',
        'agility'          => 'float',
        'intellect'        => 'float',
        'trading_karma'    => 'float',
    ];

    /**
     * Приводит числовые поля бойца к числам, не трогая остальное.
     *
     * Только числовые строки: `name` игрока может состоять из одних цифр, и
     * превращать его в int нельзя — поэтому список полей явный, а не «всё, что
     * похоже на число». `null` остаётся `null` (колонки nullable), мусорная
     * строка остаётся как есть — молча подставлять 0 значит врать о состоянии
     * персонажа; такой случай должен падать громко, а не тихо ломать баланс боя.
     *
     * @param array<string, mixed> $fighter
     * @return array<string, mixed>
     */
    public static function normalizeFighter(array $fighter): array
    {
        foreach (self::NUMERIC_FIELDS as $field => $type) {
            if (!array_key_exists($field, $fighter)) {
                continue;
            }
            $value = $fighter[$field];
            if (!is_string($value) || !is_numeric($value)) {
                continue; // уже число, null или не-число — не наша забота
            }
            $fighter[$field] = $type === 'int' ? (int) $value : (float) $value;
        }

        return $fighter;
    }

    /**
     * Инициатива: i = agility + (level+1)*0.5. Кто выше — бьёт первым.
     * Если ничья — побеждает первый параметр (c1).
     *
     * S26b (ADR-031): опциональный $defense — WatchTower даёт владельцу-защитнику
     * множитель инициативы у его базы. Множитель ДЕТЕРМИНИРОВАННЫЙ (без mt_rand →
     * RNG-fence цел). $defense=null (default) → поведение идентично legacy.
     *
     * @param array{owner_id:int, initiative_bonus:float}|array<string,mixed>|null $defense
     */
    public function determineInitiative(array|\App\Entities\CharacterEntity $c1, array|\App\Entities\CharacterEntity $c2, ?array $defense = null): array
    {
        $i1 = $c1['agility'] + ($c1['level'] + 1) * 0.5;
        $i2 = $c2['agility'] + ($c2['level'] + 1) * 0.5;

        if ($defense !== null) {
            $rawBonus = $defense['initiative_bonus'] ?? null;
            $rawOwner = $defense['owner_id'] ?? null;
            $bonus    = is_numeric($rawBonus) ? (float) $rawBonus : 0.0;
            $ownerId  = is_numeric($rawOwner) ? (int) $rawOwner : 0;
            if ($bonus > 0.0 && $ownerId > 0) {
                $c1Id = is_numeric($c1['id'] ?? null) ? (int) $c1['id'] : 0;
                $c2Id = is_numeric($c2['id'] ?? null) ? (int) $c2['id'] : 0;
                if ($c1Id === $ownerId) {
                    $i1 *= (1.0 + $bonus);
                } elseif ($c2Id === $ownerId) {
                    $i2 *= (1.0 + $bonus);
                }
            }
        }

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
