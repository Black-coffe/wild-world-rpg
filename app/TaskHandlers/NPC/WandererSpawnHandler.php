<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Models\MapModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Services\NPC\NpcInteractionService;

/**
 * ADR-089 Фаза 1 — спавнер нейтральных NPC-странников (ai_behavior='passive').
 *
 * Cron (everyMinute, singleInstance). Гейт killswitch `npc.interaction_enabled` → dormant
 * = полный no-op (0 спавнов). При ON поддерживает целевую популяцию `npc.wanderer_population`
 * живых passive-спавнов на населённых ярусах (Y 401-900). Деспавн — при взаимодействии
 * (бой/грабёж убирают спавн). Транзиентны: npc_spawns = TRANSIENT в WipeManifest.
 */
class WandererSpawnHandler
{
    private NpcModel $npcs;
    private NpcSpawnModel $spawns;
    private MapModel $map;
    private NpcInteractionService $interaction;

    public function __construct()
    {
        $this->npcs        = new NpcModel();
        $this->spawns      = new NpcSpawnModel();
        $this->map         = new MapModel();
        $this->interaction = new NpcInteractionService();
    }

    public function run(): void
    {
        if (! $this->interaction->enabled()) {
            return; // dormant
        }

        $target = $this->targetPopulation();
        if ($target <= 0) {
            return;
        }

        // ID шаблонов нейтральных NPC (исключая именных — ADR-089 Ф5+, они не масс-спавнятся).
        $neutralIds = [];
        foreach ($this->npcs->where('ai_behavior', 'passive')->where('npc_type !=', 'named')->findAll() as $n) {
            if (! is_array($n)) {
                continue;
            }
            $idRaw = $n['id'] ?? null;
            if (is_numeric($idRaw)) {
                $neutralIds[] = (int) $idRaw;
            }
        }
        if ($neutralIds === []) {
            return;
        }

        $alive   = (int) $this->spawns->whereIn('npc_id', $neutralIds)->where('status', 'alive')->countAllResults();
        $deficit = $target - $alive;
        if ($deficit <= 0) {
            return;
        }
        $deficit = min($deficit, 10); // не более 10 за тик (плавный налив)

        $cells = $this->map
            ->where('coordinate_y >=', 401)
            ->where('coordinate_y <=', 900)
            ->whereIn('biome_id', [1, 2, 3, 5, 6, 7, 8, 9])
            ->orderBy('RAND()')
            ->findAll($deficit * 3); // запас на занятые клетки
        if ($cells === []) {
            return;
        }

        $spawned = 0;
        foreach ($cells as $cell) {
            if ($spawned >= $deficit) {
                break;
            }
            if (! is_array($cell)) {
                continue;
            }
            $cellRaw    = $cell['cell_number'] ?? null;
            $cellNumber = is_numeric($cellRaw) ? (int) $cellRaw : 0;
            if ($cellNumber <= 0) {
                continue;
            }
            // Не ставим второго NPC на занятую клетку.
            if ($this->spawns->where('cell_number', $cellNumber)->where('status', 'alive')->countAllResults() > 0) {
                continue;
            }

            $npcId      = $neutralIds[array_rand($neutralIds)];
            $template   = $this->npcs->find($npcId);
            $healthRaw  = is_array($template) ? ($template['health'] ?? null) : null;
            $health     = is_numeric($healthRaw) ? (float) $healthRaw : 100.0;
            $xRaw       = $cell['coordinate_x'] ?? null;
            $yRaw       = $cell['coordinate_y'] ?? null;

            $this->spawns->insert([
                'npc_id'         => $npcId,
                'cell_number'    => $cellNumber,
                'coordinate_x'   => is_numeric($xRaw) ? (int) $xRaw : 0,
                'coordinate_y'   => is_numeric($yRaw) ? (int) $yRaw : 0,
                'current_health' => $health,
                'spawned_at'     => date('Y-m-d H:i:s'),
                'status'         => 'alive',
            ]);
            $spawned++;
        }
    }

    private function targetPopulation(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('npc.wanderer_population', 25);

        return is_numeric($v) ? (int) $v : 25;
    }
}
