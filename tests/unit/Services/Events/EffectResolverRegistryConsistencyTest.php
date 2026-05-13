<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Events;

use App\Services\Events\EffectResolver;
use App\Services\Events\EventEffectInterface;
use App\Services\Handlers\HandlerRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\WorldEvents;

/**
 * Phase B2 (ADR-023, 2026-05-13) — anti-drift guard для пары
 * `EffectResolver::KIND_MAP` ↔ `HandlerRegistry` (`#[HandlerKey]` attributes
 * на effect-классах).
 *
 * Проверки:
 *  1. Для каждого `WorldEvents::VALID_EFFECT_KINDS` registry находит entry.
 *  2. Entry->class implements `EventEffectInterface`.
 *  3. Класс из registry === класс из legacy `KIND_MAP` (нет рассинхрона).
 *  4. После warm-up `EffectResolver::resolve($kind)` использует registry path
 *     (а не fallback на `KIND_MAP`) — `lastResolvedVia()` === 'registry'.
 *
 * Тест защищает от ситуации: разработчик добавил новый kind в
 * `VALID_EFFECT_KINDS`/`KIND_MAP`, забыл `#[HandlerKey]` → silent fallback на
 * KIND_MAP в проде, но registry path не работает. Этот тест сразу красный.
 *
 * @internal
 */
final class EffectResolverRegistryConsistencyTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EffectResolver::resetCache();
    }

    protected function tearDown(): void
    {
        EffectResolver::resetCache();
        parent::tearDown();
    }

    public function testRegistryServiceIsBound(): void
    {
        $registry = Services::handlerRegistry();
        $this->assertInstanceOf(HandlerRegistry::class, $registry);
    }

    public function testEveryValidKindHasRegistryEntry(): void
    {
        $registry = Services::handlerRegistry();

        $missing = [];
        foreach (WorldEvents::VALID_EFFECT_KINDS as $kind) {
            if ($registry->getByKey($kind) === null) {
                $missing[] = $kind;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Effect kinds без #[HandlerKey] атрибута: ' . implode(', ', $missing)
                . ' — добавь атрибут к соответствующему *Effect классу (ADR-023 B2).',
        );
    }

    public function testEveryRegistryEntryImplementsEventEffectInterface(): void
    {
        $registry = Services::handlerRegistry();

        foreach (WorldEvents::VALID_EFFECT_KINDS as $kind) {
            $entry = $registry->getByKey($kind);
            $this->assertNotNull($entry, "Registry entry for '{$kind}' missing");
            $this->assertContains(
                EventEffectInterface::class,
                $entry->interfaces,
                "Класс {$entry->class} для kind='{$kind}' должен implements EventEffectInterface",
            );
        }
    }

    public function testRegistryClassesMatchLegacyKindMap(): void
    {
        $registry = Services::handlerRegistry();

        // Зеркало KIND_MAP — обновлять синхронно с EffectResolver::KIND_MAP.
        $legacyMap = [
            'damage_health'       => \App\Services\Events\Effects\DamageHealthEffect::class,
            'damage_resources'    => \App\Services\Events\Effects\DamageResourcesEffect::class,
            'heal'                => \App\Services\Events\Effects\HealEffect::class,
            'attribute_boost'     => \App\Services\Events\Effects\AttributeBoostEffect::class,
            'reveal_cells'        => \App\Services\Events\Effects\RevealCellsEffect::class,
            'gold_grant'          => \App\Services\Events\Effects\GoldGrantEffect::class,
            'rare_resource_grant' => \App\Services\Events\Effects\RareResourceGrantEffect::class,
            'task_extend'         => \App\Services\Events\Effects\TaskExtendEffect::class,
            'gather_debuff'       => \App\Services\Events\Effects\GatherDebuffEffect::class,
            'noop'                => \App\Services\Events\Effects\NoOpEffect::class,
        ];

        foreach ($legacyMap as $kind => $expectedClass) {
            $entry = $registry->getByKey($kind);
            $this->assertNotNull($entry, "Registry миссит '{$kind}'");
            $this->assertSame(
                $expectedClass,
                $entry->class,
                "Drift: registry для '{$kind}' = {$entry->class}, KIND_MAP = {$expectedClass}",
            );
        }
    }

    public function testResolveUsesRegistryPathForAllKinds(): void
    {
        // Принудительно инжектируем shared registry, чтобы lastResolvedVia был
        // консистентен (lazy-load через service() тоже сработал бы, но
        // явное wiring делает тест детерминистическим).
        EffectResolver::setRegistry(Services::handlerRegistry());

        foreach (WorldEvents::VALID_EFFECT_KINDS as $kind) {
            EffectResolver::resetCache();
            EffectResolver::setRegistry(Services::handlerRegistry());

            $effect = EffectResolver::resolve($kind);

            $this->assertInstanceOf(
                EventEffectInterface::class,
                $effect,
                "resolve('{$kind}') должен вернуть EventEffectInterface",
            );
            $this->assertSame(
                'registry',
                EffectResolver::lastResolvedVia(),
                "resolve('{$kind}') должен пройти через registry path, а не KIND_MAP fallback. "
                    . 'Возможно #[HandlerKey] атрибут отсутствует или не подхватывается scanner.',
            );
        }
    }

    public function testFallbackToKindMapWhenRegistryEmpty(): void
    {
        // Подсовываем пустой registry (без entries) — должен сработать
        // legacy KIND_MAP fallback.
        $emptyRegistry = new HandlerRegistry(
            namespacePrefixes: ['App\\NonExistent\\'],
            scanner:           new \App\Services\Handlers\HandlerDiscoveryScanner(),
            cacheEnabled:      false,
        );
        EffectResolver::setRegistry($emptyRegistry);

        $effect = EffectResolver::resolve('damage_health');

        $this->assertInstanceOf(
            \App\Services\Events\Effects\DamageHealthEffect::class,
            $effect,
            'Empty registry → must fall back to KIND_MAP',
        );
        $this->assertSame('kind_map', EffectResolver::lastResolvedVia());
    }
}
