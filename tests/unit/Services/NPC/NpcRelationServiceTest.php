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
    private function service(bool $on, int $storedScore, array $values = [], ?int $npcFaction = null, int $playerFaction = 0): NpcRelationService
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
                    'npc.relation_ally_at'     => 80,
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

        // ADR-099: faction_id NPC задаётся параметром (null → нейтральный, модификатор 0).
        $npcs = new class ($npcFaction) extends NpcModel {
            public function __construct(private ?int $fac) {}

            /**
             * @param int|array<int|string,mixed>|string|null $id
             * @return array<string,mixed>
             */
            public function find($id = null): array
            {
                return ['id' => $id, 'faction_id' => $this->fac];
            }
        };

        $factions = new class ($playerFaction) extends CharacterFactionModel {
            public function __construct(private int $fac) {}

            public function getFactionId(int $characterId): int
            {
                return $this->fac;
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

    // ── ADR-099: фракционные рядовые — реакция «бесплатно» через faction_id ──

    public function testFactionNpcSameFactionLiftsAttitude(): void
    {
        // NPC фракции 1, игрок фракции 1, stored 10 → +25 = 35 ≥ friendly_at(30) → FRIENDLY.
        $svc = $this->service(true, 10, [], 1, 1);
        $this->assertSame(NpcRelationService::FRIENDLY, $svc->attitude(1, 4));
    }

    public function testFactionNpcOtherFactionLowersAttitude(): void
    {
        // NPC фракции 1, игрок фракции 2, stored 10 → −15 = −5 → WARY.
        $svc = $this->service(true, 10, [], 1, 2);
        $this->assertSame(NpcRelationService::WARY, $svc->attitude(1, 4));
    }

    public function testFactionModifierIgnoredWhenPlayerHasNoFaction(): void
    {
        // NPC фракции 1, игрок без фракции (0) → модификатора нет, stored 10 → NEUTRAL.
        $svc = $this->service(true, 10, [], 1, 0);
        $this->assertSame(NpcRelationService::NEUTRAL, $svc->attitude(1, 4));
    }

    /**
     * @dataProvider factionLabels
     */
    public function testFactionLabel(int $id, string $expected): void
    {
        $this->assertSame($expected, NpcRelationService::factionLabel($id));
    }

    /**
     * @return list<array{int,string}>
     */
    public static function factionLabels(): array
    {
        return [
            [1, 'Милитари'],
            [2, 'Партизаны'],
            [3, 'Инженеры'],
            [4, 'Фермеры'],
            [0, ''],
            [5, ''],   // «фракция не выбрана» (sentinel) → без метки
            [-1, ''],
        ];
    }

    // ── ADR-089 Фаза 5: репутация-тиры ──

    public function testStandingNeutralWhenReactivityOff(): void
    {
        $this->assertSame('neutral', $this->service(false, 90)->standing(1, 4)['tier']);
    }

    /**
     * @dataProvider standingTiers
     */
    public function testStandingTiers(int $score, string $expectedTier, string $expectedLabel): void
    {
        $st = $this->service(true, $score)->standing(1, 4);
        $this->assertSame($expectedTier, $st['tier']);
        $this->assertSame($expectedLabel, $st['label']);
    }

    /**
     * @return list<array{int,string,string}>
     */
    public static function standingTiers(): array
    {
        return [
            [-60, 'enemy', 'Враг'],
            [-20, 'wary', 'Чужак'],
            [5, 'neutral', 'Незнакомец'],
            [40, 'acquaintance', 'Знакомый'],
            [90, 'ally', 'Союзник'],
        ];
    }

    public function testBetrayalMultipliesPenaltyAgainstAlly(): void
    {
        // Союзник (score 90) + грабёж (relation_rob=-30) × betrayal_mult(2) = -60.
        $relations = new class extends CharacterNpcRelationModel {
            public ?int $captured = null;
            public function __construct() {}
            public function scoreFor(int $characterId, int $npcId): int { return 90; }
            public function adjust(int $characterId, int $npcId, int $delta): int { $this->captured = $delta; return $delta; }
        };
        $settingsModel = new class extends GameSettingsModel {
            public function __construct() {}
            public function findByKey(string $key): ?array
            {
                $map = ['npc.reactivity_enabled'=>1,'npc.relation_friendly_at'=>30,'npc.relation_rob'=>-30,'npc.relation_betrayal_mult'=>2];
                if (! array_key_exists($key, $map)) return null;
                return ['id'=>1,'setting_key'=>$key,'value_type'=>'int','value_int'=>$map[$key],'value_bool'=>$key==='npc.reactivity_enabled'?1:null,'hard_min'=>null,'hard_max'=>null];
            }
        };
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
            public function getFactionId(int $characterId): int { return 0; }
        };
        $svc = new NpcRelationService(new GameSettingsService($settingsModel), $relations, $npcs, $factions);
        $svc->registerAction(1, 4, 'rob');
        $this->assertSame(-60, $relations->captured);
    }
}
