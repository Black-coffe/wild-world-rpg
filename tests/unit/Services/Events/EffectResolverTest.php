<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\Effects\AttributeBoostEffect;
use App\Services\Events\Effects\DamageHealthEffect;
use App\Services\Events\Effects\DamageResourcesEffect;
use App\Services\Events\Effects\GatherDebuffEffect;
use App\Services\Events\Effects\GoldGrantEffect;
use App\Services\Events\Effects\HealEffect;
use App\Services\Events\Effects\NoOpEffect;
use App\Services\Events\Effects\RareResourceGrantEffect;
use App\Services\Events\Effects\RevealCellsEffect;
use App\Services\Events\Effects\TaskExtendEffect;
use App\Services\Events\EffectResolver;
use App\Services\Events\EventEffectInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WorldEvents;
use InvalidArgumentException;

/**
 * F7.2 — тести на EffectResolver: правильна map kind→class + всі enum'и мають mapping.
 * @internal
 */
final class EffectResolverTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EffectResolver::resetCache();
    }

    public function testAllValidKindsHaveMapping(): void
    {
        $unmapped = EffectResolver::unmappedKinds();
        $this->assertEmpty($unmapped,
            "Effect kinds без mapping: " . implode(', ', $unmapped));
    }

    public function testResolverReturnsImplementationOfInterface(): void
    {
        foreach (WorldEvents::VALID_EFFECT_KINDS as $kind) {
            $effect = EffectResolver::resolve($kind);
            $this->assertInstanceOf(EventEffectInterface::class, $effect,
                "{$kind} має повертати EventEffectInterface");
        }
    }

    public function testResolverThrowsOnUnknownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EffectResolver::resolve('nonexistent_kind');
    }

    public function testKindMappingsAreCorrect(): void
    {
        $expected = [
            'damage_health'       => DamageHealthEffect::class,
            'damage_resources'    => DamageResourcesEffect::class,
            'heal'                => HealEffect::class,
            'attribute_boost'     => AttributeBoostEffect::class,
            'reveal_cells'        => RevealCellsEffect::class,
            'gold_grant'          => GoldGrantEffect::class,
            'rare_resource_grant' => RareResourceGrantEffect::class,
            'task_extend'         => TaskExtendEffect::class,
            'gather_debuff'       => GatherDebuffEffect::class,
            'noop'                => NoOpEffect::class,
        ];

        foreach ($expected as $kind => $class) {
            $effect = EffectResolver::resolve($kind);
            $this->assertInstanceOf($class, $effect, "{$kind} має повертати {$class}");
        }
    }

    public function testResolverCachesInstances(): void
    {
        $a = EffectResolver::resolve('damage_health');
        $b = EffectResolver::resolve('damage_health');
        $this->assertSame($a, $b, 'Resolver має reuse інстанси (effects stateless)');
    }
}
