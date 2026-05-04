<?php

namespace Tests\Unit\Services\Player\Gather;

use App\Services\Player\Gather\GatherEventModifierService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F2.7c — unit-тесты на чистые функции event-модификаторов.
 * loadActiveEvents покрыт integration-тестом (требует БД).
 *
 * @internal
 */
final class GatherEventModifierServiceTest extends CIUnitTestCase
{
    private GatherEventModifierService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new GatherEventModifierService();
    }

    private function inactiveAll(): array
    {
        return [
            'LocustExodus'    => ['active' => false, 'effect_value' => 0.0],
            'FishStock'       => ['active' => false, 'effect_value' => 0.0],
            'ExoticFlowering' => ['active' => false, 'effect_value' => 0.0],
            'BerryBoom'       => ['active' => false, 'effect_value' => 0.0],
            'Dryness'         => ['active' => false, 'effect_value' => 0.0],
        ];
    }

    // ---- applyResourceModifiers ----

    public function testNoEventsActiveReturnsAmountUnchanged(): void
    {
        $this->assertSame(100.0, $this->svc->applyResourceModifiers(100.0, 'Древесина', $this->inactiveAll()));
    }

    public function testFishStockBoostsFishOnly(): void
    {
        $events = $this->inactiveAll();
        $events['FishStock'] = ['active' => true, 'effect_value' => 30.0];

        // Рыба: +30% → 100 × 1.3 = 130
        $this->assertSame(130.0, $this->svc->applyResourceModifiers(100.0, 'Рыба', $events));
        // Древесина не задета.
        $this->assertSame(100.0, $this->svc->applyResourceModifiers(100.0, 'Древесина', $events));
    }

    public function testExoticFloweringBoostsOrchidsOnly(): void
    {
        $events = $this->inactiveAll();
        $events['ExoticFlowering'] = ['active' => true, 'effect_value' => 50.0];

        $this->assertSame(150.0, $this->svc->applyResourceModifiers(100.0, 'Цветы орхидей', $events));
        $this->assertSame(100.0, $this->svc->applyResourceModifiers(100.0, 'Рыба', $events));
    }

    public function testBerryBoomBoostsBerriesOnly(): void
    {
        $events = $this->inactiveAll();
        $events['BerryBoom'] = ['active' => true, 'effect_value' => 25.0];

        $this->assertSame(125.0, $this->svc->applyResourceModifiers(100.0, 'Ягоды', $events));
        $this->assertSame(100.0, $this->svc->applyResourceModifiers(100.0, 'Древесина', $events));
    }

    public function testLocustExodusReducesAllResources(): void
    {
        $events = $this->inactiveAll();
        $events['LocustExodus'] = ['active' => true, 'effect_value' => 20.0];

        // -20% ко всему.
        $this->assertSame(80.0, $this->svc->applyResourceModifiers(100.0, 'Древесина', $events));
        $this->assertSame(80.0, $this->svc->applyResourceModifiers(100.0, 'Рыба', $events));
        $this->assertSame(80.0, $this->svc->applyResourceModifiers(100.0, 'Ягоды', $events));
    }

    public function testFishStockAndLocustStackMultiplicatively(): void
    {
        $events = $this->inactiveAll();
        $events['FishStock']    = ['active' => true, 'effect_value' => 30.0];
        $events['LocustExodus'] = ['active' => true, 'effect_value' => 20.0];

        // 100 × 1.3 × 0.8 = 104.0
        $this->assertEqualsWithDelta(104.0, $this->svc->applyResourceModifiers(100.0, 'Рыба', $events), 0.001);
    }

    public function testInactiveEventNotApplied(): void
    {
        $events = $this->inactiveAll();
        $events['FishStock'] = ['active' => false, 'effect_value' => 30.0]; // active=false

        $this->assertSame(100.0, $this->svc->applyResourceModifiers(100.0, 'Рыба', $events));
    }

    // ---- applyDrynessPenalty ----

    public function testDrynessInactiveReturnsUnchanged(): void
    {
        $found = [
            ['resource_id' => 1, 'amount' => 100, 'type' => 'water'],
            ['resource_id' => 2, 'amount' => 50,  'type' => 'wood'],
        ];
        $result = $this->svc->applyDrynessPenalty($found, $this->inactiveAll());
        $this->assertSame($found, $result);
    }

    public function testDrynessReducesWaterTypeOnly(): void
    {
        $events = $this->inactiveAll();
        $events['Dryness'] = ['active' => true, 'effect_value' => 50.0]; // -50%

        $found = [
            ['resource_id' => 1, 'amount' => 100, 'type' => 'water'],
            ['resource_id' => 2, 'amount' => 50,  'type' => 'wood'],
        ];
        $result = $this->svc->applyDrynessPenalty($found, $events);

        // 100 × 0.5 = 50; wood не тронут.
        $this->assertSame(50, $result[0]['amount']);
        $this->assertSame(50, $result[1]['amount']);
    }

    public function testDrynessClampedToMinimumOne(): void
    {
        $events = $this->inactiveAll();
        $events['Dryness'] = ['active' => true, 'effect_value' => 99.0]; // -99%

        $found = [
            ['resource_id' => 1, 'amount' => 1, 'type' => 'water'], // 1 × 0.01 = 0.01 → clamp 1
        ];
        $result = $this->svc->applyDrynessPenalty($found, $events);
        $this->assertSame(1, $result[0]['amount']);
    }

    public function testDrynessAffectsCompoundWaterTypes(): void
    {
        // 'fresh_water' тоже содержит 'water' — должно сработать str_contains.
        $events = $this->inactiveAll();
        $events['Dryness'] = ['active' => true, 'effect_value' => 50.0];

        $found = [
            ['resource_id' => 1, 'amount' => 100, 'type' => 'fresh_water'],
        ];
        $result = $this->svc->applyDrynessPenalty($found, $events);
        $this->assertSame(50, $result[0]['amount']);
    }

    // ---- loadActiveEvents — пустой случай (без БД) ----

    public function testLoadActiveEventsEmptyNamesReturnsEmpty(): void
    {
        $this->assertSame([], $this->svc->loadActiveEvents([]));
    }
}
