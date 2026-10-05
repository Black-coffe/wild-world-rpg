<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Models\GameSettingsModel;
use App\Services\Craft\CraftBatchConfirmPolicy;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * craft-batch-price-confirm — порог подтверждения крупной партии крафта.
 *
 * Исходный случай — жалоба 05.10.2026: 50 сапёрных лопат (300 000 золота) ушли одним нажатием.
 *
 * @internal
 */
final class CraftBatchConfirmPolicyTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    /** @param array<string,int> $settings */
    private function policy(array $settings): CraftBatchConfirmPolicy
    {
        // Кэш GameSettings (60 с) переживает смену модели внутри одного теста.
        service('cache')->clean();
        $model = new class ($settings) extends GameSettingsModel {
            /** @param array<string,int> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->values)) {
                    return null;
                }

                return ['setting_key' => $key, 'value_type' => 'int', 'value_int' => $this->values[$key]];
            }
        };

        return new CraftBatchConfirmPolicy(new GameSettingsService($model));
    }

    public function testThePlayerReportBatchNeedsConfirmation(): void
    {
        $this->assertTrue($this->policy([])->needsConfirm(50, 300000));
    }

    public function testQuantityBoundary(): void
    {
        $p = $this->policy(['craft.confirm.min_qty' => 25, 'craft.confirm.min_gold' => 50000]);
        $this->assertFalse($p->needsConfirm(24, 0));
        $this->assertTrue($p->needsConfirm(25, 0));
    }

    public function testGoldBoundary(): void
    {
        $p = $this->policy(['craft.confirm.min_qty' => 25, 'craft.confirm.min_gold' => 50000]);
        $this->assertFalse($p->needsConfirm(1, 49999));
        $this->assertTrue($p->needsConfirm(1, 50000));
    }

    /** Соседняя форма: условие по штукам выключено, а дорогая партия всё равно спрашивает. */
    public function testZeroDisablesOnlyItsOwnCondition(): void
    {
        $noQty = $this->policy(['craft.confirm.min_qty' => 0, 'craft.confirm.min_gold' => 50000]);
        $this->assertFalse($noQty->needsConfirm(100, 0));
        $this->assertTrue($noQty->needsConfirm(5, 60000));

        $noGold = $this->policy(['craft.confirm.min_qty' => 25, 'craft.confirm.min_gold' => 0]);
        $this->assertFalse($noGold->needsConfirm(1, 9999999));
        $this->assertTrue($noGold->needsConfirm(25, 0));
    }

    public function testBothZeroNeverAsks(): void
    {
        $p = $this->policy(['craft.confirm.min_qty' => 0, 'craft.confirm.min_gold' => 0]);
        $this->assertFalse($p->needsConfirm(100, 10000000));
    }
}
