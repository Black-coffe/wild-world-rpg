<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NPC;

use App\Models\CharacterFactionModel;
use App\Models\CharacterNpcRelationModel;
use App\Models\GameSettingsModel;
use App\Models\NpcModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\NPC\NpcRelationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-089 Фаза 2 — attitude/killswitch реактивности NPC.
 *
 * @internal
 */
final class NpcRelationServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string,int|bool> $values
     */
    private function service(bool $on, int $storedScore, array $values = []): NpcRelationService
    {
        $settingsModel = new class ($on, $values) extends GameSettingsModel {
            /** @param array<string,int|bool> $values */
            public function __construct(private bool $on, private array $values) {}

            public function findByKey(string $key): ?array
            {
                $map = array_merge([
                    'npc.reactivity_enabled'   => $this->on ? 1 : 0,
                    'npc.relation_hostile_at'  => -50,
                    'npc.relation_friendly_at' => 30,
                    'npc.relation_faction_same'  => 25,
                    'npc.relation_faction_other' => -15,
                ], $this->values);
                if (! array_key_exists($key, $map)) {
                    return null;
                }
                $val  = $map[$key];
                $type = is_bool($val) ? 'bool' : 'int';

                return [
                    'id' => 1, 'setting_key' => $key, 'value_type' => $type,
                    'value_bool' => is_bool($val) ? ($val ? 1 : 0) : null,
                    'value_int' => is_int($val) ? $val : null,
                    'hard_min' => null, 'hard_max' => null,
                ];
            }
        };

        $relations = new class ($storedScore) extends CharacterNpcRelationModel {
            public function __construct(private int $score) {}

            public function scoreFor(int $characterId, int $npcId): int
            {
                return $this->score;
            }
        };

        // NPC без фракции → faction-модификатор 0.
        $npcs = new class extends NpcModel {
            public function __construct() {}

            /**
             * @param int|array<int|string,mixed>|string|null $id
             * @return array<string,mixed>
             */
            public function find($id = null): array
            {
                return ['id' => $id, 'faction_id' => null];
            }
        };

        $factions = new class extends CharacterFactionModel {
            public function __construct() {}

            public function getFactionId(int $characterId): int
            {
                return 0;
            }
        };

        return new NpcRelationService(new GameSettingsService($settingsModel), $relations, $npcs, $factions);
    }

    public function testNeutralWhenReactivityOff(): void
    {
        // Даже при очень низком score — выключенная реактивность → neutral.
        $svc = $this->service(false, -999);
        $this->assertSame(NpcRelationService::NEUTRAL, $svc->attitude(1, 4));
        $this->assertFalse($svc->enabled());
    }

    public function testHostileBelowThreshold(): void
    {
        $svc = $this->service(true, -60);
        $this->assertSame(NpcRelationService::HOSTILE, $svc->attitude(1, 4));
    }

    public function testWaryWhenNegativeButAboveHostile(): void
    {
        $svc = $this->service(true, -20);
        $this->assertSame(NpcRelationService::WARY, $svc->attitude(1, 4));
    }

    public function testNeutralAroundZero(): void
    {
        $svc = $this->service(true, 5);
        $this->assertSame(NpcRelationService::NEUTRAL, $svc->attitude(1, 4));
    }

    public function testFriendlyAboveThreshold(): void
    {
        $svc = $this->service(true, 40);
        $this->assertSame(NpcRelationService::FRIENDLY, $svc->attitude(1, 4));
    }
}
