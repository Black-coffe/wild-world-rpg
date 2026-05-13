<?php

declare(strict_types=1);

namespace App\Services\Events;

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
use App\Services\Handlers\HandlerRegistry;
use Config\WorldEvents;
use InvalidArgumentException;
use Throwable;

/**
 * F7.2 — резолвер `effect_kind` enum → конкретного Effect-класу.
 *
 * Використовуватиметься у F7.3 EventTickHandler dispatcher'і для виклику
 * правильного Effect-класу за конфігом події.
 *
 * Phase B2 (ADR-023, 2026-05-13): спочатку шукає клас через
 * {@see HandlerRegistry} (auto-discovery з `#[HandlerKey]` атрибутом),
 * fallback на статичний `KIND_MAP` для backward-compat у dual-write
 * вікні B2–B6.
 *
 * Use:
 *     $effect = EffectResolver::resolve('damage_health'); // returns DamageHealthEffect instance
 *     $result = $effect->compute($char, $eventCfg, $activeEvent, $ctx);
 */
final class EffectResolver
{
    /**
     * Legacy map effect_kind → клас. Fallback-шлях для dual-write вікна
     * (B2–B6 за ADR-023). Залишається до Phase B7 cleanup ADR.
     *
     * @var array<string, class-string<EventEffectInterface>>
     */
    private const KIND_MAP = [
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

    /**
     * Cache instances (всі ефект-класи stateless, можна reuse).
     *
     * @var array<string, EventEffectInterface>
     */
    private static array $instances = [];

    /**
     * Cached registry instance (resolved lazily on first lookup). `null`
     * означає «не пробували» або «service() недоступний у test/CLI bootstrap».
     */
    private static ?HandlerRegistry $registry = null;

    /**
     * Чи пробували ми вже отримати registry (true після першої спроби —
     * не намагаємось повторно якщо service() недоступний).
     */
    private static bool $registryResolveAttempted = false;

    /**
     * Шлях резолва останнього успішного lookup'у: 'registry' | 'kind_map' | null.
     * Використовується у тестах і smoke-перевірках (B2.5).
     */
    private static ?string $lastResolvedVia = null;

    public static function resolve(string $effectKind): EventEffectInterface
    {
        if (isset(self::$instances[$effectKind])) {
            return self::$instances[$effectKind];
        }

        $class = self::resolveClassName($effectKind);
        self::$instances[$effectKind] = new $class();

        return self::$instances[$effectKind];
    }

    /**
     * Lookup класу: спочатку реєстр (Phase B2 шлях), потім KIND_MAP fallback.
     *
     * @return class-string<EventEffectInterface>
     */
    private static function resolveClassName(string $effectKind): string
    {
        $registry = self::getRegistry();
        if ($registry !== null) {
            $entry = $registry->getByKey($effectKind);
            if ($entry !== null && is_subclass_of($entry->class, EventEffectInterface::class)) {
                self::$lastResolvedVia = 'registry';
                log_message('debug', "EffectResolver: resolved '{$effectKind}' via HandlerRegistry → {$entry->class}");
                return $entry->class;
            }
        }

        if (isset(self::KIND_MAP[$effectKind])) {
            self::$lastResolvedVia = 'kind_map';
            return self::KIND_MAP[$effectKind];
        }

        $allowed = implode(', ', WorldEvents::VALID_EFFECT_KINDS);
        throw new InvalidArgumentException(
            "Unknown effect_kind: '{$effectKind}'. Allowed: {$allowed}"
        );
    }

    /**
     * Lazy-доступ до registry через service() helper. Якщо service container
     * недоступний (rannі CLI bootstrap, мінімальні unit-тести без CI4
     * environment) — повертаємо null, далі піде KIND_MAP fallback.
     */
    private static function getRegistry(): ?HandlerRegistry
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        if (self::$registryResolveAttempted) {
            return null;
        }
        self::$registryResolveAttempted = true;

        if (!function_exists('service')) {
            return null;
        }

        try {
            $candidate = service('handlerRegistry');
        } catch (Throwable) {
            return null;
        }

        if ($candidate instanceof HandlerRegistry) {
            self::$registry = $candidate;
            return $candidate;
        }

        return null;
    }

    /**
     * Інжектовка registry (тести + smoke override). Передача `null` скидає
     * до lazy-default поведінки.
     */
    public static function setRegistry(?HandlerRegistry $registry): void
    {
        self::$registry                 = $registry;
        self::$registryResolveAttempted = $registry !== null;
        self::$instances                = []; // зміна registry = старі instances могли вказувати на legacy classes.
    }

    /**
     * Який шлях вирішив останній resolve(): 'registry' | 'kind_map' | null
     * (null = ще не було жодного успішного resolve).
     */
    public static function lastResolvedVia(): ?string
    {
        return self::$lastResolvedVia;
    }

    /**
     * Reset cache (для тестів).
     */
    public static function resetCache(): void
    {
        self::$instances                = [];
        self::$registry                 = null;
        self::$registryResolveAttempted = false;
        self::$lastResolvedVia          = null;
    }

    /**
     * Перевірити, що всі VALID_EFFECT_KINDS мають mapping.
     * Використовується тестом WorldEventsTest для конзистентності.
     *
     * @return list<string> kinds без mapping (порожній список = ОК).
     */
    public static function unmappedKinds(): array
    {
        $unmapped = [];
        foreach (WorldEvents::VALID_EFFECT_KINDS as $kind) {
            if (!isset(self::KIND_MAP[$kind])) {
                $unmapped[] = $kind;
            }
        }
        return $unmapped;
    }
}
