<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Services\PVE\PvpActivityContextService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Asana «PvP-контекст» — unit-тесты чистого маппинга активности (без БД).
 *
 * @internal
 */
final class PvpActivityContextServiceTest extends CIUnitTestCase
{
    private PvpActivityContextService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PvpActivityContextService();
    }

    public function testFieldActivitiesMapToPhrases(): void
    {
        $this->assertSame('за добычей ресурсов', $this->svc->phraseForTaskName('Gather'));
        $this->assertSame('за разведкой местности', $this->svc->phraseForTaskName('ExploreTheArea'));
        $this->assertSame('в походе вглубь острова', $this->svc->phraseForTaskName('Marching'));
    }

    public function testBaseActivitiesMapToPhrases(): void
    {
        $this->assertSame('за работой на грядках', $this->svc->phraseForTaskName('plantCrop'));
        $this->assertSame('за починкой снаряжения', $this->svc->phraseForTaskName('Repair'));
        $this->assertSame('в разгар переезда лагеря', $this->svc->phraseForTaskName('FullRelocation'));
    }

    public function testCraftPrefixMapsToWorkbench(): void
    {
        $this->assertSame('за работой у верстака', $this->svc->phraseForTaskName('craftGaussPistol'));
        $this->assertSame('за работой у верстака', $this->svc->phraseForTaskName('craftBandage'));
    }

    public function testBuildPrefixMapsToConstruction(): void
    {
        $this->assertSame('на стройке', $this->svc->phraseForTaskName('buildWatchTower'));
        $this->assertSame('на стройке', $this->svc->phraseForTaskName('startBuildWarehouse'));
        $this->assertSame('на стройке', $this->svc->phraseForTaskName('buildingManualPump'));
    }

    public function testRobotTasksExcluded(): void
    {
        $this->assertNull($this->svc->phraseForTaskName('GatheringResourcesRobot'));
        $this->assertNull($this->svc->phraseForTaskName('ExploringLocationRobot'));
        $this->assertNull($this->svc->phraseForTaskName('craftRobotScout'));
    }

    public function testUnknownPersonalTaskHasGenericFallback(): void
    {
        $this->assertSame('за своими делами', $this->svc->phraseForTaskName('SomeFutureTask'));
    }

    public function testEmptyNameIsNull(): void
    {
        $this->assertNull($this->svc->phraseForTaskName(''));
    }
}
