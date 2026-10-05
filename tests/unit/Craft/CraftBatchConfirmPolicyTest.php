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

    /**
     * Исправление владельца 05.10.2026: «от 25 штук и дороже определённой суммы» — И, а не ИЛИ.
     * Соседняя форма исходного случая: дешёвая партия в 25+ штук стартует без вопроса.
     */
    public function testCheapLargeBatchDoesNotAsk(): void
    {
        $p = $this->policy(['craft.confirm.min_qty' => 25, 'craft.confirm.min_gold' => 50000]);
        $this->assertFalse($p->needsConfirm(100, 0), '100 бинтов без золота');
        $this->assertFalse($p->needsConfirm(25, 49999));
        $this->assertFalse($p->needsConfirm(5, 60000), 'мелкая дорогая партия');
    }

    public function testBothBoundariesMustHold(): void
    {
        $p = $this->policy(['craft.confirm.min_qty' => 25, 'craft.confirm.min_gold' => 50000]);
        $this->assertTrue($p->needsConfirm(25, 50000));
        $this->assertFalse($p->needsConfirm(24, 50000));
        $this->assertFalse($p->needsConfirm(25, 49999));
    }

    /** 0 выключает своё условие — остаётся второе. */
    public function testZeroDisablesOnlyItsOwnCondition(): void
    {
        $noQty = $this->policy(['craft.confirm.min_qty' => 0, 'craft.confirm.min_gold' => 50000]);
        $this->assertTrue($noQty->needsConfirm(5, 60000));
        $this->assertFalse($noQty->needsConfirm(100, 49999));

        $noGold = $this->policy(['craft.confirm.min_qty' => 25, 'craft.confirm.min_gold' => 0]);
        $this->assertTrue($noGold->needsConfirm(25, 0));
        $this->assertFalse($noGold->needsConfirm(24, 9999999));
    }

    public function testBothZeroNeverAsks(): void
    {
        $p = $this->policy(['craft.confirm.min_qty' => 0, 'craft.confirm.min_gold' => 0]);
        $this->assertFalse($p->needsConfirm(100, 10000000));
    }
}
