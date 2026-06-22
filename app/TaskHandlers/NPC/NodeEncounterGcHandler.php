<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Attributes\HandlerKey;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\TaskHandlers\BaseTaskHandler;
use Config\Database;

/**
 * WB6 (ADR-137 «Узлы») — GC зависших боёв с узлом.
 *
 * Игрок закрыл чат / ушёл, не завершив бой → active-encounter висит. Этот крон гасит active-бои,
 * не тронутые дольше `combat.nodes.encounter_ttl_minutes`, в `fled` (HP узла персистентен, остаётся
 * как есть — фундамент со-опа). НЕ killswitch-gated: чистит даже после выключения point-режима
 * (висящие бои не должны застревать). Идемпотентен (батч по last_action_at); dormant → 0 active → no-op.
 *
 * Класс НЕ final.
 */
#[HandlerKey(
    key: 'node_encounter_gc',
    displayName: 'Узлы: GC зависших боёв (WB6)',
    description: 'everyMinute: active-бои с узлом без действий дольше combat.nodes.encounter_ttl_minutes → fled (HP узла остаётся). НЕ killswitch-gated. ADR-137.',
)]
class NodeEncounterGcHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    public function handle(array $task = []): void
    {
        $this->run();
    }

    public function run(): void
    {
        $ttl    = max(1, $this->gsInt('combat.nodes.encounter_ttl_minutes', 30));
        $cutoff = date('Y-m-d H:i:s', time() - $ttl * 60);

        Database::connect()->table('boss_encounters')
            ->where('status', 'active')
            ->where('last_action_at <', $cutoff)
            ->update(['status' => 'fled']);
    }
}
