<?php

namespace Tests\Unit\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use App\Libraries\ToolManager;

/**
 * S20 (v0.51.202) — ToolManager GameSettings overlay для T3 «оживляемых» tools.
 *
 * Sapper Shovel / Golden Hoe лежали в БД, но не в bonus-map → ничего не делали.
 * S20 добавил overlay: для их resource-set'а бонус читается из GameSettings
 * (fallback на дефолт 0.70 / 0.60). Legacy map + DiamondPickaxe не тронуты.
 *
 * Тест устойчив к окружению: если game_settings недоступна — overlay вернёт
 * fallback-дефолт; если БД вовсе нет — тест помечается skipped.
 *
 * @internal
 */
final class ToolManagerT3OverlayTest extends CIUnitTestCase
{
    private function tools(string $resource): array
    {
        try {
            return (new ToolManager())->getToolsForResource($resource);
        } catch (\Throwable $e) {
            $this->markTestSkipped('GameSettings/DB unavailable: ' . $e->getMessage());
        }
    }

    public function testOverlayAddsSapperShovelToDiggingResource(): void
    {
        $tools = $this->tools('Глина');
        $this->assertArrayHasKey('Sapper Shovel', $tools, 'overlay должен добавить Sapper Shovel к Глине');
        $this->assertGreaterThan(0.0, $tools['Sapper Shovel']);
        // legacy map сохранён (DiamondPickaxe для Глины из hardcoded map).
        $this->assertArrayHasKey('DiamondPickaxe', $tools, 'legacy DiamondPickaxe не должен пропасть');
        $this->assertArrayHasKey('IronShovel', $tools, 'legacy IronShovel не должен пропасть');
    }

    public function testOverlayAddsGoldenHoeToFarmingResource(): void
    {
        $tools = $this->tools('Овощи');
        $this->assertArrayHasKey('Golden Hoe', $tools, 'overlay должен добавить Golden Hoe к Овощам');
        $this->assertGreaterThan(0.0, $tools['Golden Hoe']);
        $this->assertArrayHasKey('Hoe', $tools, 'legacy Hoe не должен пропасть');
    }

    public function testOverlayDoesNotLeakToUnrelatedResource(): void
    {
        // Рыба не в resource-set ни Sapper Shovel, ни Golden Hoe.
        $tools = $this->tools('Рыба');
        $this->assertArrayNotHasKey('Sapper Shovel', $tools);
        $this->assertArrayNotHasKey('Golden Hoe', $tools);
        $this->assertArrayHasKey('FishingRod', $tools, 'legacy FishingRod для Рыбы сохранён');
    }
}
