<?php

namespace App\Services\Events;

/**
 * F7.2 — інтерфейс для всіх 10 effect-стратегій.
 *
 * Принцип: **чисті компуторы** (pure-ish, можна mt_rand).
 * - НЕ читають DB.
 * - НЕ шлють Telegram.
 * - НЕ записують в моделі.
 *
 * Усе I/O — на dispatcher'і (F7.3 EventTickHandler) і accumulator'і (F7.4
 * EffectAccumulator). Це робить effect-класи легко тестованими і дозволяє
 * dispatcher'у батчити SQL.
 *
 * @see app/Services/Events/Effects/*  — реалізації
 * @see mmorpg-vault/lore/refactor/F7-Audit.md
 */
interface EventEffectInterface
{
    /**
     * Обчислити ефект для одного character'а на одному tick'у.
     *
     * @param array<string, mixed> $character    row з `characters` (id, biome_id, health, tired, gold, level, ...)
     * @param array<string, mixed> $eventConfig  запис з WorldEvents::$events для цієї події
     *                                           (effect_kind + effect_params + duration + ...)
     * @param array<string, mixed> $activeEvent  row з `active_events` (start_time, end_time, status)
     * @param array<string, mixed> $context      додатковий контекст для рішень:
     *                                           - 'player_state' : 'base_idle' | 'biome_idle' | 'biome_active'
     *                                           - 'on_base'      : bool (характер на базі)
     *                                           - 'is_gathering' : bool
     *                                           - 'is_exploring' : bool
     *                                           - 'biome'        : row з biomes (danger_level, survival_difficulty, biome_type)
     *                                           - 'tick_no'      : int (1-based, для one_shot_at_start розрізнення)
     *                                           - 'last_seen_hours_ago' : ?int (для NightAttacks sleeping skip)
     *                                           - 'now_time'     : string 'HH:MM' (для time_window)
     *                                           - 'has_protection_item' : bool (для F7.7)
     *
     * @return array{
     *     applied: bool,
     *     health_delta: float,
     *     tired_delta: float,
     *     gold_delta: int,
     *     attribute_deltas: array<string, float>,
     *     resource_grant_intents: list<array{keyword: ?string, biome_id: ?int, rarity: ?int, amount: int}>,
     *     resource_loss_percent: float,
     *     task_extension_intent: ?array{filter: list<string>, extra_minutes: int},
     *     reveal_cells_intent: ?array{count: int},
     *     gather_modifier: float,
     *     log_summary: string,
     *     magnitude: array<string, mixed>
     * }
     */
    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array;
}
