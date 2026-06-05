<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Models\SettlementModel;
use App\Models\SettlementNpcModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-101 Фаза 0 — размещение жителей поселений на их якорных клетках.
 *
 * Cron (everyMinute). Гейт killswitch `settlements.enabled` → dormant = полный no-op. При ON держит
 * РОВНО 1 живой спавн каждого жителя (settlement_npcs) на settlements.anchor_cell поселения. В
 * отличие от NamedNpcPlacementHandler: (1) координаты КУРИРУЕМЫЕ (из settlements, не рандом),
 * (2) НЕСКОЛЬКО жителей на одной якорной клетке (гейт «1 спавн на клетку» не применяем).
 *
 * Спавны жителей — passive → встреча/диалог работают через NpcEncounterAction. Спавны TRANSIENT
 * (после вайпа крон восстановит из ростера). Фаза 0: settlements пуст → крон no-op.
 *
 * ⚠️ Модели свежими на операцию (CI4 builder-state quirk, memory feedback_ci4_model_builder_state_quirk).
 */
class SettlementPlacementHandler
{
    private GameSettingsService $settings;

    public function __construct()
    {
        $this->settings = new GameSettingsService();
    }

    public function run(): void
    {
        if (! $this->enabled()) {
            return; // dormant
        }

        $settlements = (new SettlementModel())->allActive();
        if ($settlements === []) {
            return;
        }

        foreach ($settlements as $s) {
            $sid    = is_numeric($s['id'] ?? null) ? (int) $s['id'] : 0;
            $anchor = is_numeric($s['anchor_cell'] ?? null) ? (int) $s['anchor_cell'] : 0;
            if ($sid <= 0 || $anchor <= 0) {
                continue; // поселение без якоря — пропускаем
            }
            $x = is_numeric($s['coordinate_x'] ?? null) ? (int) $s['coordinate_x'] : 0;
            $y = is_numeric($s['coordinate_y'] ?? null) ? (int) $s['coordinate_y'] : 0;

            foreach ((new SettlementNpcModel())->forSettlement($sid) as $resident) {
                $npcId = is_numeric($resident['npc_id'] ?? null) ? (int) $resident['npc_id'] : 0;
                if ($npcId <= 0) {
                    continue;
                }

                // Уже есть живой спавн этого жителя — ничего не делаем (ровно 1 присутствует).
                if ((new NpcSpawnModel())->where('npc_id', $npcId)->where('status', 'alive')->countAllResults() > 0) {
                    continue;
                }

                $npc = (new NpcModel())->find($npcId);
                if (! is_array($npc)) {
                    continue;
                }
                $healthRaw = $npc['health'] ?? null;

                (new NpcSpawnModel())->insert([
                    'npc_id'         => $npcId,
                    'cell_number'    => $anchor,
                    'coordinate_x'   => $x,
                    'coordinate_y'   => $y,
                    'current_health' => is_numeric($healthRaw) ? (float) $healthRaw : 100.0,
                    'spawned_at'     => date('Y-m-d H:i:s'),
                    'status'         => 'alive',
                ]);
            }
        }
    }

    private function enabled(): bool
    {
        $v = $this->settings->get('settlements.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}
