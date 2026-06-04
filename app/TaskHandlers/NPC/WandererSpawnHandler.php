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

        // ID passive-шаблонов (исключая именных — ADR-089 Ф5+, они не масс-спавнятся). Один
        // запрос (сохраняет legacy-фильтр != 'named'), сплит в PHP: ADR-099 фракционные рядовые
        // (npc_type='faction') в отдельный под-пул, остальное passive-non-named = истинные нейтралы.
        $neutralIds = [];
        $factionIds = [];
        foreach ($this->npcs->where('ai_behavior', 'passive')->where('npc_type !=', 'named')->findAll() as $n) {
            if (! is_array($n)) {
                continue;
            }
            $idRaw = $n['id'] ?? null;
            if (! is_numeric($idRaw)) {
                continue;
            }
            $id = (int) $idRaw;
            if (($n['npc_type'] ?? '') === 'faction') {
                $factionIds[] = $id;
            } else {
                $neutralIds[] = $id;
            }
        }
        $poolAll = array_merge($neutralIds, $factionIds);
        if ($poolAll === []) {
            return;
        }

        $alive   = (int) $this->spawns->whereIn('npc_id', $poolAll)->where('status', 'alive')->countAllResults();
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

        $ratio   = $this->factionRatio();
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

            // ADR-099 — на каждый спавн выбираем под-пул по доле фракционных (ratio%).
            $useFaction = self::pickFaction((int) mt_rand(0, 99), $ratio, $factionIds !== [], $neutralIds !== []);
            $pool       = $useFaction ? $factionIds : $neutralIds;
            $npcId      = $pool[array_rand($pool)];
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

    /** ADR-099 — доля фракционных рядовых в спавне (%), 0-100. Default 0 → dormant. */
    private function factionRatio(): int
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())->get('npc.faction_wanderer_ratio', 0);
        $i = is_numeric($v) ? (int) $v : 0;

        return max(0, min(100, $i));
    }

    /**
     * ADR-099 — выбрать ли фракционный под-пул для очередного спавна (чистая, тестируемая).
     * $roll ∈ [0,99], $ratioPercent ∈ [0,100]. Нет фракционных → всегда нейтрал;
     * нет нейтралов → всегда фракционный; иначе фракционный при roll < ratio.
     */
    public static function pickFaction(int $roll, int $ratioPercent, bool $hasFaction, bool $hasNeutral): bool
    {
        if (! $hasFaction) {
            return false;
        }
        if (! $hasNeutral) {
            return true;
        }

        return $roll < max(0, min(100, $ratioPercent));
    }
}
