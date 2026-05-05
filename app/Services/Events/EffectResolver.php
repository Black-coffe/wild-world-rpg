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
use Config\WorldEvents;
use InvalidArgumentException;

/**
 * F7.2 — резолвер `effect_kind` enum → конкретного Effect-класу.
 *
 * Використовуватиметься у F7.3 EventTickHandler dispatcher'і для виклику
 * правильного Effect-класу за конфігом події.
 *
 * Use:
 *     $effect = EffectResolver::resolve('damage_health'); // returns DamageHealthEffect instance
 *     $result = $effect->compute($char, $eventCfg, $activeEvent, $ctx);
 */
final class EffectResolver
{
    /**
     * Map effect_kind → клас.
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

    public static function resolve(string $effectKind): EventEffectInterface
    {
        if (!isset(self::KIND_MAP[$effectKind])) {
            $allowed = implode(', ', WorldEvents::VALID_EFFECT_KINDS);
            throw new InvalidArgumentException(
                "Unknown effect_kind: '{$effectKind}'. Allowed: {$allowed}"
            );
        }

        if (!isset(self::$instances[$effectKind])) {
            $class                          = self::KIND_MAP[$effectKind];
            self::$instances[$effectKind]   = new $class();
        }

        return self::$instances[$effectKind];
    }

    /**
     * Reset cache (для тестів).
     */
    public static function resetCache(): void
    {
        self::$instances = [];
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
