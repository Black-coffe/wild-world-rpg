<?php

namespace Tests\Unit\Services\PVE;

use App\Entities\BattleCharacter;
use App\Services\PVE\DamageService;
use CodeIgniter\Test\CIUnitTestCase;
use Psr\Log\NullLogger;

/**
 * F2.11 — первый набор unit-тестов критичной формулы боя.
 *
 * Зачем: `DamageService::calculateDamage` — это базовая формула PvP/PvE
 * (см. `mmorpg-vault/lore/combat/PvP.md`). Её декомпозиция в F2.3 будет
 * безопасной только если у нас есть характеристика «до» в виде тестов.
 *
 * Тесты — pure-unit: никакой БД, моков моделей. DamageService принимает
 * `BattleCharacter` (data class) и `LoggerInterface` (NullLogger в тестах).
 *
 * Покрытие:
 *   - calculateDamage: базовый случай, level diff (положительный/отрицательный),
 *     характеристики (strength/agility), броня, weak-vs-strong-bootstrap,
 *     минимальный damage floor (baseDamage × 0.5).
 *   - computeDamageRatio: clamp [0.8 .. 1.2], граничные точки.
 */
final class DamageServiceTest extends CIUnitTestCase
{
    private DamageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DamageService(new NullLogger());
    }

    // -----------------------------------------------------------------
    // calculateDamage
    // -----------------------------------------------------------------

    public function testCalculateDamageBasicSameLevel(): void
    {
        $attacker = $this->makeCharacter([
            'level'        => 10,
            'strength'     => 100.0,
            'agility'      => 100.0,
            'damage_value' => 50.0,
        ]);
        $defender = $this->makeCharacter([
            'level'    => 10,
            'strength' => 0.0,
            'agility'  => 0.0,
            'armor'    => 0,
        ]);

        $damage = $this->service->calculateDamage($attacker, $defender, 'forest');

        // Формула:
        //   base × (1 + 0 + 100*0.0025 + 100*0.0015) × (1 - 0)
        //   = 50 × 1.4 × 1 = 70
        $this->assertEqualsWithDelta(70.0, $damage, 0.01);
    }

    public function testCalculateDamageScalesWithLevelDifference(): void
    {
        // ВАЖНО: strength/agility ≥ 5 чтобы НЕ сработал bootstrap-multiplier
        // (он применяется при strength<5 AND agility<5 и работает в обе стороны
        // — усиливает слабых атакующих и наказывает сильных по уровню атакующих
        // с низкими статами).
        $attacker = $this->makeCharacter([
            'level'        => 20,
            'strength'     => 10.0,
            'agility'      => 10.0,
            'damage_value' => 50.0,
        ]);
        $defender = $this->makeCharacter([
            'level' => 10,
            'armor' => 0,
        ]);

        $damage = $this->service->calculateDamage($attacker, $defender, 'forest');

        // levelDiff = (20-10) × 0.02 = 0.20
        // strBonus  = 10 × 0.0025 = 0.025
        // agiBonus  = 10 × 0.0015 = 0.015
        // multiplier = 1 + 0.20 + 0.025 + 0.015 = 1.24
        // result = 50 × 1.24 × (1-0) = 62.0
        $this->assertEqualsWithDelta(62.0, $damage, 0.01);
    }

    public function testBootstrapMultiplierAppliesBothDirections(): void
    {
        // ⚠️ Документируем неочевидное поведение:
        // bootstrap-formula: $multiplier = 1 + (1 - attLvl/max(1, defLvl)) × 0.5
        // Если attL > defL и strength<5 AND agility<5 — multiplier < 1.
        // То есть «опытный, но не качающий характеристики» атакующий теряет урон.
        // Это сохраняем как фактическое поведение (записать в баланс-доку).
        $attacker = $this->makeCharacter([
            'level'        => 20,
            'strength'     => 1.0,  // <5 → срабатывает bootstrap
            'agility'      => 1.0,
            'damage_value' => 50.0,
        ]);
        $defender = $this->makeCharacter([
            'level' => 10,
            'armor' => 0,
        ]);

        $damage = $this->service->calculateDamage($attacker, $defender, 'forest');

        // bootstrap multiplier = 1 + (1 - 20/10) × 0.5 = 1 + (-0.5) = 0.5
        // base после bootstrap: 50 × 0.5 = 25
        // levelDiff = 0.20, strBonus ≈ 0.0025, agiBonus ≈ 0.0015
        // result = 25 × (1 + 0.2 + 0.0025 + 0.0015) = 25 × 1.204 = 30.1
        // floor = max(50 × 0.5, 30.1) = 30.1 (база уже изменена)
        $this->assertLessThan(50.0, $damage); // ниже исходного base
        $this->assertEqualsWithDelta(30.1, $damage, 0.5);
    }

    public function testCalculateDamageReducedByArmor(): void
    {
        $attacker = $this->makeCharacter([
            'level'        => 10,
            'strength'     => 0.0,
            'agility'      => 0.0,
            'damage_value' => 100.0,
        ]);
        // armor = 100 → armorEffect = 100 / (100+100) = 0.5 → 50% reduction
        $defender = $this->makeCharacter([
            'level' => 10,
            'armor' => 100,
        ]);

        $damage = $this->service->calculateDamage($attacker, $defender, 'forest');

        // 100 × 1.0 × (1 - 0.5) = 50
        $this->assertEqualsWithDelta(50.0, $damage, 0.01);
    }

    public function testCalculateDamageWeakAttackerGetsBootstrapMultiplier(): void
    {
        // strength<5 AND agility<5 = слабый: получает множитель
        // multiplier = 1 + (1 - attLvl/max(1, defLvl)) × 0.5
        // Для attL=1, defL=10: 1 + (1 - 1/10) × 0.5 = 1 + 0.45 = 1.45
        $attacker = $this->makeCharacter([
            'level'        => 1,
            'strength'     => 1.0,
            'agility'      => 1.0,
            'damage_value' => 10.0,
        ]);
        $defender = $this->makeCharacter([
            'level' => 10,
            'armor' => 0,
        ]);

        $damage = $this->service->calculateDamage($attacker, $defender, 'forest');

        // damageBoost: 10 × 1.45 = 14.5
        // levelDiff = (1-10) × 0.02 = -0.18
        // strengthBonus = 1 × 0.0025 = 0.0025
        // agilityBonus = 1 × 0.0015 = 0.0015
        // multiplier = 1 - 0.18 + 0.0025 + 0.0015 = 0.824
        // result = 14.5 × 0.824 × 1 = 11.948
        // НО есть floor: max(baseDamage × 0.5, ...) = max(10*0.5, 11.948) = 11.948
        // (после bootstrap baseDamage становится 14.5, а floor считается ОТ изменённого)
        $this->assertGreaterThan(10.0, $damage);
    }

    public function testCalculateDamageRespectsHalfBaseFloor(): void
    {
        // Собираем сценарий когда формула даёт меньше base × 0.5
        // и проверяем что floor сработал.
        $attacker = $this->makeCharacter([
            'level'        => 1,
            'strength'     => 50.0, // > 5 → нет bootstrap
            'agility'      => 50.0,
            'damage_value' => 100.0,
        ]);
        $defender = $this->makeCharacter([
            'level' => 100,  // огромная разница уровней
            'armor' => 999,  // огромная броня
        ]);

        $damage = $this->service->calculateDamage($attacker, $defender, 'forest');

        // floor = base × 0.5 = 50
        $this->assertGreaterThanOrEqual(50.0, $damage);
    }

    // -----------------------------------------------------------------
    // WB1 (ADR-137 «Узлы») — закап разницы уровней в бою с боссом-узлом.
    // GameSettingsService в unit-тесте (нет ключа/БД) деградирует к default 30.
    // -----------------------------------------------------------------

    public function testBossEncounterClampsLevelDifferencePreventingOneShot(): void
    {
        // Босс-узел L200 бьёт игрока L40. Без капа множитель уровня = 160×0.02 = 3.2
        // (×4.24 к урону = почти уаншот). С капом 30: 30×0.02 = 0.6.
        $boss = $this->makeCharacter([
            'is_boss'      => true,
            'level'        => 200,
            'strength'     => 10.0, // ≥5 → без bootstrap-множителя
            'agility'      => 10.0,
            'damage_value' => 50.0,
        ]);
        $player = $this->makeCharacter(['level' => 40, 'armor' => 0]);

        $damage = $this->service->calculateDamage($boss, $player, 'forest');

        // 50 × (1 + 0.6 + 10×0.0025 + 10×0.0015) = 50 × 1.64 = 82.0
        $this->assertEqualsWithDelta(82.0, $damage, 0.01);
        // Кап реально сработал: без него было бы 50 × 4.24 = 212.0.
        $this->assertLessThan(212.0, $damage);
    }

    public function testNonBossLargeLevelDiffStaysUnclamped(): void
    {
        // Та же разница уровней, но НЕ босс → кап не применяется (байт-идентично легаси).
        $attacker = $this->makeCharacter([
            'is_boss'      => false,
            'level'        => 200,
            'strength'     => 10.0,
            'agility'      => 10.0,
            'damage_value' => 50.0,
        ]);
        $defender = $this->makeCharacter(['level' => 40, 'armor' => 0]);

        $damage = $this->service->calculateDamage($attacker, $defender, 'forest');

        // levelDiff = 160 × 0.02 = 3.2 → 50 × (1 + 3.2 + 0.025 + 0.015) = 50 × 4.24 = 212.0
        $this->assertEqualsWithDelta(212.0, $damage, 0.01);
    }

    public function testPlayerAttackingBossAlsoClamped(): void
    {
        // Обратное направление: игрок L40 бьёт босса-узла L200 (defender->isBoss).
        // Отрицательная разница тоже клампится (−30) → урон не обнуляется огромным
        // штрафом, floor base×0.5 как нижняя граница.
        $player = $this->makeCharacter([
            'level'        => 40,
            'strength'     => 10.0,
            'agility'      => 10.0,
            'damage_value' => 50.0,
        ]);
        $boss = $this->makeCharacter(['is_boss' => true, 'level' => 200, 'armor' => 0]);

        $damage = $this->service->calculateDamage($player, $boss, 'forest');

        // levelDiff clamp −30 → 50 × (1 − 0.6 + 0.025 + 0.015) = 50 × 0.44 = 22.0,
        // но floor max(50×0.5, 22.0) = 25.0.
        $this->assertEqualsWithDelta(25.0, $damage, 0.01);
    }

    public function testBossEncounterBootstrapNeverNegativeForStrongPlayerVsWeakNode(): void
    {
        // WB6 регресс: высокоуровневый игрок с НИЗКИМИ str/agi бьёт СЛАБЫЙ узел.
        // Без isBoss-клампа бутстрап = 1 + (1 − 16/5)×0.5 = −0.1 → урон отрицательный → HP узла рос.
        // С клампом (defender->isBoss) множитель = max(1.0, −0.1) = 1.0 → урон положительный.
        $player = $this->makeCharacter([
            'level'        => 16,
            'strength'     => 0.3,  // <5 → бутстрап
            'agility'      => 0.1,
            'damage_value' => 10.0,
        ]);
        $boss = $this->makeCharacter(['is_boss' => true, 'level' => 5, 'armor' => 0]);

        $damage = $this->service->calculateDamage($player, $boss, 'forest');

        $this->assertGreaterThan(0.0, $damage, 'урон по узлу не может быть отрицательным/нулём');
        // base 10 × множитель 1.0 × (1 + clamp(11)*0.02 + крошечные бонусы) ≈ 12.2; floor max(5, ..) = 12.2
        $this->assertEqualsWithDelta(12.2, $damage, 0.3);
    }

    public function testNonBossBootstrapPenaltyStillAppliesBackcompat(): void
    {
        // Тот же расклад БЕЗ босса → бутстрап-штраф сохранён (обычный PvE байт-идентичен):
        // 16/5 ratio → множитель −0.1 → база отрицательная → floor max(10×(−0.1)×0.5, …) ≤ 0.
        $attacker = $this->makeCharacter([
            'level'        => 16,
            'strength'     => 0.3,
            'agility'      => 0.1,
            'damage_value' => 10.0,
        ]);
        $defender = $this->makeCharacter(['is_boss' => false, 'level' => 5, 'armor' => 0]);

        $damage = $this->service->calculateDamage($attacker, $defender, 'forest');

        $this->assertLessThanOrEqual(0.0, $damage, 'не-боссовый бутстрап-штраф сохранён (поведение не тронуто)');
    }

    // -----------------------------------------------------------------
    // E21 Ф1 (ADR-121) — боевой food-баф (множители default 1.0)
    // -----------------------------------------------------------------

    public function testFoodBuffOutgoingMultiplierBoostsDamage(): void
    {
        $attacker = $this->makeCharacter([
            'level' => 10, 'strength' => 100.0, 'agility' => 100.0, 'damage_value' => 50.0,
        ]);
        $attacker->outgoingDamageMultiplier = 1.10; // сытый игрок-атакующий
        $defender = $this->makeCharacter(['level' => 10, 'strength' => 0.0, 'agility' => 0.0, 'armor' => 0]);

        // База 70.0 (см. testCalculateDamageBasicSameLevel) × 1.10 = 77.0
        $this->assertEqualsWithDelta(77.0, $this->service->calculateDamage($attacker, $defender, 'forest'), 0.01);
    }

    public function testFoodBuffIncomingMultiplierReducesDamage(): void
    {
        $attacker = $this->makeCharacter([
            'level' => 10, 'strength' => 100.0, 'agility' => 100.0, 'damage_value' => 50.0,
        ]);
        $defender = $this->makeCharacter(['level' => 10, 'strength' => 0.0, 'agility' => 0.0, 'armor' => 0]);
        $defender->incomingDamageMultiplier = 0.92; // сытый игрок-защитник

        // База 70.0 × 0.92 = 64.4
        $this->assertEqualsWithDelta(64.4, $this->service->calculateDamage($attacker, $defender, 'forest'), 0.01);
    }

    public function testFoodBuffDefaultMultipliersAreNeutral(): void
    {
        // default 1.0/1.0 → урон byte-identical (как без бафа).
        $attacker = $this->makeCharacter([
            'level' => 10, 'strength' => 100.0, 'agility' => 100.0, 'damage_value' => 50.0,
        ]);
        $defender = $this->makeCharacter(['level' => 10, 'strength' => 0.0, 'agility' => 0.0, 'armor' => 0]);
        $this->assertSame(1.0, $attacker->outgoingDamageMultiplier);
        $this->assertSame(1.0, $defender->incomingDamageMultiplier);
        $this->assertEqualsWithDelta(70.0, $this->service->calculateDamage($attacker, $defender, 'forest'), 0.01);
    }

    // -----------------------------------------------------------------
    // computeDamageRatio
    // -----------------------------------------------------------------

    public function testComputeDamageRatioReturnsOneForZeroDifference(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->service->computeDamageRatio(0.0), 0.01);
    }

    public function testComputeDamageRatioInsideClampRange(): void
    {
        // diff = 80 → 1 + 80/400 = 1.2 (на границе верхнего clamp)
        $this->assertEqualsWithDelta(1.2, $this->service->computeDamageRatio(80.0), 0.01);

        // diff = 40 → 1 + 40/400 = 1.1
        $this->assertEqualsWithDelta(1.1, $this->service->computeDamageRatio(40.0), 0.01);

        // diff = -40 → 1 - 0.1 = 0.9
        $this->assertEqualsWithDelta(0.9, $this->service->computeDamageRatio(-40.0), 0.01);
    }

    public function testComputeDamageRatioClampsAboveCap(): void
    {
        // diff > 100 → 1.2
        $this->assertEqualsWithDelta(1.2, $this->service->computeDamageRatio(500.0), 0.001);
    }

    public function testComputeDamageRatioClampsBelowFloor(): void
    {
        // diff < -100 → 0.8
        $this->assertEqualsWithDelta(0.8, $this->service->computeDamageRatio(-500.0), 0.001);
    }

    public function testComputeDamageRatioStaysWithinClampForBoundary(): void
    {
        // diff = 100 — формула даёт 1.25, но clamp обрезает до 1.2
        $this->assertEqualsWithDelta(1.2, $this->service->computeDamageRatio(100.0), 0.001);
    }

    // -----------------------------------------------------------------
    // helper
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $overrides
     */
    private function makeCharacter(array $overrides = []): BattleCharacter
    {
        return new BattleCharacter(array_merge([
            'id'           => 1,
            'name'         => 'Test',
            'level'        => 10,
            'health'       => 100.0,
            'tired'        => 100.0,
            'strength'     => 10.0,
            'agility'      => 10.0,
            'intellect'    => 10.0,
            'armor'        => 0,
            'damage_type'  => 'Physical',
            'damage_value' => 10.0,
            'attack_speed' => 1.0,
        ], $overrides));
    }
}
