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

    // ── S4 (v0.51.186+): broken-tools tracking ──────────────────────────

    public function testBrokenToolsEmptyInitially(): void
    {
        $this->assertSame([], $this->proc->getBrokenTools());
    }

    public function testClearBrokenToolsResetsState(): void
    {
        // Прямой dirty-state через рефлексию недоступен публично, поэтому
        // проверяем что после clear getBrokenTools пустой (idempotency).
        $this->proc->clearBrokenTools();
        $this->assertSame([], $this->proc->getBrokenTools());
        $this->proc->clearBrokenTools();
        $this->assertSame([], $this->proc->getBrokenTools());
    }

    public function testConsumeAndRefreshTracksBrokenWhenToolGone(): void
    {
        // Мок ToolManager: updateToolDurability возвращает false → tool сломался.
        $toolManager = new class extends ToolManager {
            /** @phpstan-ignore-next-line method.childParameterType */
            public function updateToolDurability($toolData)
            {
                return false; // симулирует tool gone
            }
        };

        // Мок CraftedItemsLogModel: getItemByNameEngAndCharacterId возвращает null
        // (consumeAndRefresh после false не делает refresh, но безопасности ради).
        $logModel = new class extends \App\Models\CraftedItemsLogModel {
            public function __construct()
            {
                // skip parent ctor (avoid DB connection)
            }
            public function getItemByNameEngAndCharacterId($nameEng, $characterId)
            {
                return null;
            }
        };

        $proc = new ToolDurabilityProcessor($logModel, null, $toolManager);

        $availableTools = [
            'IronPickaxe' => ['id' => 42, 'crafted_item_id' => 7, 'quantity' => 1, 'durability_count' => 1],
        ];

        $ok = $proc->consumeAndRefresh('IronPickaxe', $availableTools, 999);

        $this->assertFalse($ok);
        $this->assertArrayNotHasKey('IronPickaxe', $availableTools);
        $this->assertSame(['IronPickaxe' => true], $proc->getBrokenTools());

        // Clear → пусто.
        $proc->clearBrokenTools();
        $this->assertSame([], $proc->getBrokenTools());
    }

    public function testConsumeAndRefreshDoesNotTrackWhenToolStillAlive(): void
    {
        // Мок ToolManager: updateToolDurability возвращает true → tool жив.
        $toolManager = new class extends ToolManager {
            /** @phpstan-ignore-next-line method.childParameterType */
            public function updateToolDurability($toolData)
            {
                return true;
            }
        };

        // Мок LogModel: getItemByNameEngAndCharacterId возвращает обновлённую запись.
        $logModel = new class extends \App\Models\CraftedItemsLogModel {
            public function __construct() { }
            public function getItemByNameEngAndCharacterId($nameEng, $characterId)
            {
                return ['id' => 42, 'quantity' => 1, 'durability_count' => 4];
            }
        };

        $proc = new ToolDurabilityProcessor($logModel, null, $toolManager);

        $availableTools = [
            'IronPickaxe' => ['id' => 42, 'quantity' => 1, 'durability_count' => 5],
        ];

        $ok = $proc->consumeAndRefresh('IronPickaxe', $availableTools, 999);

        $this->assertTrue($ok);
        $this->assertArrayHasKey('IronPickaxe', $availableTools);
        $this->assertSame([], $proc->getBrokenTools()); // не сломался
    }
}
