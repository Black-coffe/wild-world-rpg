<?php

namespace Tests\Unit\Endgame;

use App\TaskHandlers\Other\WorldObjectGeneratorHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\EndgameScoring;

/**
 * S21 (ROADMAP-CRAFT §8) — anti-drift guard для strategic-объектов.
 *
 * Гарантирует, что цепочка «спавн → discovery → +500 фракции» остаётся
 * целостной: каждый spawnable strategic-объект имеет (а) рабочий spawn-case в
 * генераторе и (б) faction-routing в EndgameScoring. Иначе повторится мёртвая
 * цепочка S21 (объект спавнился бы, но discovery не давал бы очков, или наоборот).
 *
 * @internal
 */
final class StrategicObjectWiringTest extends CIUnitTestCase
{
    public function testStrategicDiscoveryAwardsFiveHundred(): void
    {
        $this->assertSame(500, (new EndgameScoring())->strategicObjectDiscoveryPoints);
    }

    public function testStrategicFactionMapMatchesCanon(): void
    {
        $map = (new EndgameScoring())->strategicObjectFactionMap;

        // 1:1 canon: Bunker→Militari(1), Technopark→Engineers(3), GhostCity→Partisans(2),
        // IslandFarm→Farmers(4) (S24).
        $this->assertSame(1, $map['Bunker'] ?? null);
        $this->assertSame(3, $map['Technopark'] ?? null);
        $this->assertSame(2, $map['GhostCity'] ?? null);
        $this->assertSame(4, $map['IslandFarm'] ?? null);
    }

    public function testEverySpawnableStrategicTypeIsSupportedByGenerator(): void
    {
        foreach (WorldObjectGeneratorHandler::STRATEGIC_SPAWN_TYPES as $type) {
            $this->assertContains(
                $type,
                WorldObjectGeneratorHandler::SUPPORTED_SPAWN_TYPES,
                "Strategic тип '{$type}' обязан иметь spawn-case (иначе default no-op → не заспавнится)."
            );
        }
    }

    public function testEverySpawnableStrategicTypeHasFactionRouting(): void
    {
        $map = (new EndgameScoring())->strategicObjectFactionMap;

        foreach (WorldObjectGeneratorHandler::STRATEGIC_SPAWN_TYPES as $type) {
            $this->assertArrayHasKey(
                $type,
                $map,
                "Strategic тип '{$type}' спавнится, но не даёт +500 фракции — разрыв цепочки."
            );
        }
    }
}
