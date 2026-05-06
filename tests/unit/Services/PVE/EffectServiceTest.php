<?php

namespace Tests\Unit\Services\PVE;

use App\Entities\BattleCharacter;
use App\Services\PVE\EffectService;
use CodeIgniter\Test\CIUnitTestCase;
use Psr\Log\NullLogger;

/**
 * F2.11+ — unit-тесты EffectService::applyEffects.
 *
 * EffectService — pure-functional с side-effects ТОЛЬКО на переданный
 * BattleCharacter, БД не трогает. Идеальный кандидат для unit-теста
 * без моков.
 *
 * Поведение из EffectService.php (sourсе истины):
 *   - tired < 30:   damageValue × 0.9, attackSpeed × 0.85
 *   - health < 20:  attackSpeed × 0.8
 *   - body-armor с special_bonus='damage_reduction': armor += 5
 *
 * Эти три эффекта могут срабатывать одновременно. Проверяем что
 * умножения корректно складываются.
 */
final class EffectServiceTest extends CIUnitTestCase
{
    private EffectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EffectService(new NullLogger());
    }

    public function testHealthyAndRestedNoChange(): void
    {
        $char = $this->makeCharacter([
            'tired'        => 100.0,
            'health'       => 100.0,
            'damage_value' => 50.0,
            'attack_speed' => 1.0,
            'armor'        => 10,
        ]);

        $this->service->applyEffects($char);

        $this->assertSame(50.0, $char->damageValue);
        $this->assertSame(1.0, $char->attackSpeed);
        $this->assertSame(10.0, $char->armor);
    }

    public function testTiredBelow30ReducesDamageAndAttackSpeed(): void
    {
        $char = $this->makeCharacter([
            'tired'        => 25.0,    // <30
            'health'       => 100.0,
            'damage_value' => 50.0,
            'attack_speed' => 1.0,
        ]);

        $this->service->applyEffects($char);

        $this->assertEqualsWithDelta(45.0, $char->damageValue, 0.001);  // 50 × 0.9
        $this->assertEqualsWithDelta(0.85, $char->attackSpeed, 0.001);  // 1.0 × 0.85
    }

    public function testTiredBoundaryAt29Triggers(): void
    {
        $char = $this->makeCharacter([
            'tired'        => 29.0,
            'damage_value' => 100.0,
            'attack_speed' => 1.0,
        ]);

        $this->service->applyEffects($char);

        $this->assertEqualsWithDelta(90.0, $char->damageValue, 0.001);
    }

    public function testTiredBoundaryAt30DoesNotTrigger(): void
    {
        $char = $this->makeCharacter([
            'tired'        => 30.0,    // НЕ <30, граничное значение
            'damage_value' => 100.0,
            'attack_speed' => 1.0,
        ]);

        $this->service->applyEffects($char);

        $this->assertSame(100.0, $char->damageValue);
        $this->assertSame(1.0, $char->attackSpeed);
    }

    public function testWoundedHealthReducesAttackSpeed(): void
    {
        $char = $this->makeCharacter([
            'tired'        => 100.0,
            'health'       => 15.0,    // <20
            'damage_value' => 50.0,
            'attack_speed' => 1.0,
        ]);

        $this->service->applyEffects($char);

        $this->assertSame(50.0, $char->damageValue);          // не изменилось
        $this->assertEqualsWithDelta(0.8, $char->attackSpeed, 0.001);  // 1.0 × 0.8
    }

    public function testTiredAndWoundedStackOnAttackSpeed(): void
    {
        // Срабатывают оба эффекта: tired<30 (×0.85) И health<20 (×0.8).
        // Ожидание: 1.0 × 0.85 × 0.8 = 0.68
        $char = $this->makeCharacter([
            'tired'        => 10.0,
            'health'       => 10.0,
            'damage_value' => 100.0,
            'attack_speed' => 1.0,
        ]);

        $this->service->applyEffects($char);

        $this->assertEqualsWithDelta(90.0, $char->damageValue, 0.001);     // tired
        $this->assertEqualsWithDelta(0.68, $char->attackSpeed, 0.001);      // both
    }

    public function testArmorBonusFromBodyEquipment(): void
    {
        $char = $this->makeCharacter([
            'armor'     => 10,
            'equipment' => [
                'body' => ['special_bonus' => 'damage_reduction'],
            ],
        ]);

        $this->service->applyEffects($char);

        $this->assertSame(15.0, $char->armor);  // 10 + 5
    }

    public function testArmorBonusOnlyForBodySlot(): void
    {
        // Бонус привязан к слоту 'body'. Если special_bonus стоит на
        // 'head' или 'legs' — игнорируется.
        $char = $this->makeCharacter([
            'armor'     => 10,
            'equipment' => [
                'head' => ['special_bonus' => 'damage_reduction'],
            ],
        ]);

        $this->service->applyEffects($char);

        $this->assertSame(10.0, $char->armor);  // без изменений
    }

    public function testArmorBonusIgnoresOtherBonusTypes(): void
    {
        $char = $this->makeCharacter([
            'armor'     => 10,
            'equipment' => [
                'body' => ['special_bonus' => 'fire_resistance'],
            ],
        ]);

        $this->service->applyEffects($char);

        $this->assertSame(10.0, $char->armor);  // другой бонус не трогает armor
    }

    public function testNoArmorBonusWhenNoEquipment(): void
    {
        $char = $this->makeCharacter([
            'armor' => 10,
            // equipment не задана
        ]);

        $this->service->applyEffects($char);

        $this->assertSame(10.0, $char->armor);
    }

    public function testAllThreeEffectsApplyTogether(): void
    {
        // Уставший (tired=20), раненый (health=10), с armor-бонусом
        $char = $this->makeCharacter([
            'tired'        => 20.0,
            'health'       => 10.0,
            'damage_value' => 100.0,
            'attack_speed' => 1.0,
            'armor'        => 50,
            'equipment'    => [
                'body' => ['special_bonus' => 'damage_reduction'],
            ],
        ]);

        $this->service->applyEffects($char);

        $this->assertEqualsWithDelta(90.0, $char->damageValue, 0.001);     // tired
        $this->assertEqualsWithDelta(0.68, $char->attackSpeed, 0.001);      // tired+wounded
        $this->assertSame(55.0, $char->armor);                             // 50+5
    }

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
            'equipment'    => [],
        ], $overrides));
    }
}
