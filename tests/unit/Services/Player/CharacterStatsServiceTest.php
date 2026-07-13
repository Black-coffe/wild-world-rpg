<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\CharacterStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Чистая математика относительных апдейтов статов (applyDeltas/clamp).
 * Атомарность (FOR UPDATE + транзакция) юнитом не проверить — она
 * фиксируется source-scan'ом UsePharmacyAtomicUpdateTest и Tier-3 smoke.
 *
 * @internal
 */
final class CharacterStatsServiceTest extends CIUnitTestCase
{
    public function testPlainDeltasApply(): void
    {
        $new = CharacterStatsService::applyDeltas(
            ['health' => 63.92, 'tired' => 65.0, 'gold' => 100.0],
            ['health' => 25, 'tired' => 15, 'gold' => -30],
        );

        $this->assertSame(88.92, $new['health']);
        $this->assertSame(80.0, $new['tired']);
        $this->assertSame(70.0, $new['gold']);
    }

    public function testHealthAndTiredCapAt100(): void
    {
        $new = CharacterStatsService::applyDeltas(
            ['health' => 95.0, 'tired' => 99.5],
            ['health' => 25, 'tired' => 15],
        );

        $this->assertSame(100.0, $new['health']);
        $this->assertSame(100.0, $new['tired']);
    }

    public function testFloorAtZeroByDefault(): void
    {
        $new = CharacterStatsService::applyDeltas(
            ['health' => 3.0, 'gold' => 10.0],
            ['health' => -10, 'gold' => -50],
        );

        $this->assertSame(0.0, $new['health']);
        $this->assertSame(0.0, $new['gold']); // конкурентная трата золота не уводит в минус
    }

    public function testBoundsOverrideCustomFloor(): void
    {
        // GatherAction: выносливость не падает ниже 0.01 (floor, не 0).
        $new = CharacterStatsService::applyDeltas(
            ['tired' => 2.0],
            ['tired' => -5],
            ['tired' => ['min' => 0.01]],
        );

        $this->assertSame(0.01, $new['tired']);
    }

    public function testUnknownFieldsAndNonNumericDeltasIgnored(): void
    {
        $new = CharacterStatsService::applyDeltas(
            ['health' => 50.0],
            ['health' => 5, 'cell_number' => 42, 'level' => 99],
        );

        $this->assertSame(['health' => 55.0], $new);
    }

    public function testOnlyRequestedFieldsReturned(): void
    {
        $new = CharacterStatsService::applyDeltas(
            ['health' => 50.0, 'tired' => 50.0, 'gold' => 10.0],
            ['gold' => 1],
        );

        $this->assertSame(['gold' => 11.0], $new);
    }
}
