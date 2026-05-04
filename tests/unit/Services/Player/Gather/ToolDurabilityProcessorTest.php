<?php

namespace Tests\Unit\Services\Player\Gather;

use App\Libraries\ToolManager;
use App\Services\Player\Gather\ToolDurabilityProcessor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F2.7b — unit-тесты на чистую функцию pickBestTool.
 * loadAvailableTools / consumeAndRefresh требуют БД и покрываются
 * отдельным интеграционным тестом (F2.7d).
 *
 * @internal
 */
final class ToolDurabilityProcessorTest extends CIUnitTestCase
{
    private ToolDurabilityProcessor $proc;

    protected function setUp(): void
    {
        parent::setUp();
        // Реальный ToolManager — у него pure метод getToolsForResource (mapping).
        $this->proc = new ToolDurabilityProcessor(null, null, new ToolManager());
    }

    public function testReturnsNullForUnknownResource(): void
    {
        $this->assertNull($this->proc->pickBestTool('NonexistentResource', [
            'LumberjackAxe' => ['id' => 1],
        ]));
    }

    public function testReturnsNullWhenCacheEmpty(): void
    {
        $this->assertNull($this->proc->pickBestTool('Древесина', []));
    }

    public function testReturnsNullWhenCacheLacksRequiredTool(): void
    {
        // Древесина требует LumberjackAxe; в cache только Hoe.
        $this->assertNull($this->proc->pickBestTool('Древесина', [
            'Hoe' => ['id' => 1],
        ]));
    }

    public function testPicksOnlyAvailableTool(): void
    {
        // Древесина → LumberjackAxe (0.30).
        $best = $this->proc->pickBestTool('Древесина', [
            'LumberjackAxe' => ['id' => 1],
        ]);
        $this->assertSame('LumberjackAxe', $best['name']);
        $this->assertSame(0.30, $best['bonus']);
    }

    public function testPicksHighestBonusWhenMultipleAvailable(): void
    {
        // Камни → StonePickaxe(0.30), IronPickaxe(0.56), DiamondPickaxe(1.8).
        $best = $this->proc->pickBestTool('Камни', [
            'StonePickaxe'   => ['id' => 1],
            'IronPickaxe'    => ['id' => 2],
            'DiamondPickaxe' => ['id' => 3],
        ]);
        $this->assertSame('DiamondPickaxe', $best['name']);
        $this->assertSame(1.8, $best['bonus']);
    }

    public function testPicksHigherTierWhenSomeMissing(): void
    {
        // Камни требует Stone/Iron/Diamond; в cache только Iron.
        $best = $this->proc->pickBestTool('Камни', [
            'IronPickaxe' => ['id' => 2],
        ]);
        $this->assertSame('IronPickaxe', $best['name']);
        $this->assertSame(0.56, $best['bonus']);
    }

    public function testPicksLowerTierIfHigherMissing(): void
    {
        // Камни без Diamond, но с Stone+Iron → выбираем Iron (более высокий бонус).
        $best = $this->proc->pickBestTool('Камни', [
            'StonePickaxe' => ['id' => 1],
            'IronPickaxe'  => ['id' => 2],
        ]);
        $this->assertSame('IronPickaxe', $best['name']);
    }

    public function testIgnoresIrrelevantToolsInCache(): void
    {
        // Cache содержит инструмент НЕ из mapping этого ресурса.
        $best = $this->proc->pickBestTool('Древесина', [
            'LumberjackAxe' => ['id' => 1],
            'IronPickaxe'   => ['id' => 2], // не нужен для Древесина
        ]);
        $this->assertSame('LumberjackAxe', $best['name']);
        $this->assertSame(0.30, $best['bonus']);
    }
}
