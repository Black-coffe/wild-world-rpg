<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Attributes\HandlerKey;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\TaskHandlers\BaseTaskHandler;
use Config\Database;

/**
 * WB7 (ADR-137 «Узлы») — реген HP узлов между боями (РЕГЕН-ГЕЙТ кооператива).
 *
 * Главный математический гейт «нужна группа» БЕЗ party-движка: alive-узел восстанавливает
 * `combat.nodes.hp_regen_per_minute` HP/мин (к max_health), ПОКА его активно не бьют. Соло-DPS
 * на верхних тирах < реген → один игрок чип-и-беги не успевает (раны затягиваются быстрее); 3+
 * async-участника «Облавы» (WB8) суммарно обгоняют реген → узел падает.
 *
 * Реген НЕ идёт во время активного боя:
 *  - `updated_at`-пауза: пропускаем точки, по которым урон был в последние `regen_pause_minutes`
 *    (каждый чанк боя пишет updated_at; реген НЕ трогает updated_at → не лечит себя и не сбрасывает паузу);
 *  - исключение активного encounter'а (NOT EXISTS) — защита, если игрок открыл бой, но не тапает.
 *
 * Set-based UPDATE (1 запрос). Гейт killswitch `world.nodes.point_mode_enabled` (OFF → no-op;
 * к тому же при OFF alive-точек нет). Класс НЕ final.
 */
#[HandlerKey(
    key: 'node_health_regen',
    displayName: 'Узлы: реген HP между боями (WB7 реген-гейт)',
    description: 'everyMinute + killswitch world.nodes.point_mode_enabled: alive-узлы восстанавливают combat.nodes.hp_regen_per_minute к max_health вне активного боя (реген-гейт кооператива). ADR-137.',
)]
class NodeHealthRegenHandler extends BaseTaskHandler
{
    use GameSettingsReaderTrait;

    public function handle(array $task = []): void
    {
        $this->run();
    }

    public function run(): void
    {
        if (! $this->gsBool('world.nodes.point_mode_enabled', false)) {
            return; // dormant — узлов нет
        }
        $regen = max(0, $this->gsInt('combat.nodes.hp_regen_per_minute', 20));
        if ($regen <= 0) {
            return;
        }
        $pause  = max(0, $this->gsInt('combat.nodes.regen_pause_minutes', 3));
        $cutoff = date('Y-m-d H:i:s', time() - $pause * 60);

        // Set-based: лечим alive-узлы вне активного боя, по которым давно не было урона.
        // NOT EXISTS (null-safe) исключает узлы с активным encounter'ом. updated_at реген НЕ трогает.
        Database::connect()->query(
            'UPDATE boss_points bp '
            . 'SET bp.current_health = LEAST(bp.max_health, bp.current_health + ?) '
            . "WHERE bp.status = 'alive' AND bp.current_health < bp.max_health "
            . 'AND (bp.updated_at IS NULL OR bp.updated_at < ?) '
            . "AND NOT EXISTS (SELECT 1 FROM boss_encounters be WHERE be.boss_point_id = bp.id AND be.status = 'active')",
            [$regen, $cutoff]
        );
    }
}
