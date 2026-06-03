<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Models\MapModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-089 Phase 6 (контент-пасс именных NPC) — фикс-ландмарк размещение именных NPC.
 *
 * Cron (everyMinute, singleInstance). Гейт killswitch `npc.named_placement_enabled` → dormant
 * = полный no-op. При ON держит РОВНО 1 живой спавн каждого именного NPC (npc_type='named')
 * на его стабильной home-клетке. home-клетка выбирается ОДИН раз (случайно в населённом ярусе
 * Y 401-900) и персистится в npcs.custom_settings.home_cell → к NPC можно вернуться. При убийстве
 * (consumeSpawn удаляет строку) на следующем тике именной NPC респавнится на той же home-клетке.
 *
 * Именные NPC = passive → попадают в passiveSpawnOnCell → встреча/дерево диалога работают
 * автоматически (NpcEncounterAction). Спавны TRANSIENT в WipeManifest (после вайпа крон
 * восстановит из персистнутых home-клеток).
 */
class NamedNpcPlacementHandler
{
    private NpcModel $npcs;
    private NpcSpawnModel $spawns;
    private MapModel $map;
    private GameSettingsService $settings;

    public function __construct()
    {
        $this->npcs     = new NpcModel();
        $this->spawns   = new NpcSpawnModel();
        $this->map      = new MapModel();
        $this->settings = new GameSettingsService();
    }

    public function run(): void
    {
        if (! $this->enabled()) {
            return; // dormant
        }

        $named = $this->npcs->where('npc_type', 'named')->findAll();
        foreach ($named as $npc) {
            if (! is_array($npc)) {
                continue;
            }
            $idRaw = $npc['id'] ?? null;
            $npcId = is_numeric($idRaw) ? (int) $idRaw : 0;
            if ($npcId <= 0) {
                continue;
            }

            // Уже есть живой спавн этого именного NPC — ничего не делаем (ровно 1 присутствует).
            $alive = (int) $this->spawns->where('npc_id', $npcId)->where('status', 'alive')->countAllResults();
            if ($alive > 0) {
                continue;
            }

            $home = $this->homeCellFor($npc);
            if ($home === null) {
                continue; // не удалось назначить клетку (нет подходящих) — попробуем на следующем тике
            }

            // Не ставим второго NPC на занятую клетку (другой спавн уже там).
            if ($this->spawns->where('cell_number', $home['cell'])->where('status', 'alive')->countAllResults() > 0) {
                continue;
            }

            $healthRaw = $npc['health'] ?? null;
            $health    = is_numeric($healthRaw) ? (float) $healthRaw : 100.0;

            $this->spawns->insert([
                'npc_id'         => $npcId,
                'cell_number'    => $home['cell'],
                'coordinate_x'   => $home['x'],
                'coordinate_y'   => $home['y'],
                'current_health' => $health,
                'spawned_at'     => date('Y-m-d H:i:s'),
                'status'         => 'alive',
            ]);
        }
    }

    /**
     * home-клетка именного NPC: читаем из custom_settings.home_cell; если нет — выбираем
     * случайную свободную клетку в населённом ярусе и персистим. Стабильна между тиками.
     *
     * @param array<int|string,mixed> $npc
     * @return array{cell:int, x:int, y:int}|null
     */
    private function homeCellFor(array $npc): ?array
    {
        $settings = $this->decodeSettings($npc['custom_settings'] ?? null);

        $cellRaw = $settings['home_cell'] ?? null;
        $cell    = is_numeric($cellRaw) ? (int) $cellRaw : 0;
        if ($cell > 0) {
            $xRaw = $settings['home_x'] ?? null;
            $yRaw = $settings['home_y'] ?? null;

            return [
                'cell' => $cell,
                'x'    => is_numeric($xRaw) ? (int) $xRaw : 0,
                'y'    => is_numeric($yRaw) ? (int) $yRaw : 0,
            ];
        }

        // Назначаем новую home-клетку: случайная проходимая клетка населённого яруса,
        // ещё не занятая home-клеткой другого именного NPC и без живого спавна.
        $candidates = $this->map
            ->where('coordinate_y >=', 401)
            ->where('coordinate_y <=', 900)
            ->whereIn('biome_id', [1, 2, 3, 5, 6, 7, 8, 9])
            ->orderBy('RAND()')
            ->findAll(30);

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $cRaw = $candidate['cell_number'] ?? null;
            $cNum = is_numeric($cRaw) ? (int) $cRaw : 0;
            if ($cNum <= 0) {
                continue;
            }
            if ($this->spawns->where('cell_number', $cNum)->where('status', 'alive')->countAllResults() > 0) {
                continue;
            }
            if ($this->isHomeCellOfAnotherNamed($cNum, is_numeric($npc['id'] ?? null) ? (int) $npc['id'] : 0)) {
                continue;
            }

            $xRaw = $candidate['coordinate_x'] ?? null;
            $yRaw = $candidate['coordinate_y'] ?? null;
            $x    = is_numeric($xRaw) ? (int) $xRaw : 0;
            $y    = is_numeric($yRaw) ? (int) $yRaw : 0;

            $settings['home_cell'] = $cNum;
            $settings['home_x']    = $x;
            $settings['home_y']    = $y;
            $this->npcs->db->table('npcs')
                ->where('id', is_numeric($npc['id'] ?? null) ? (int) $npc['id'] : 0)
                ->update(['custom_settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);

            return ['cell' => $cNum, 'x' => $x, 'y' => $y];
        }

        return null;
    }

    /** Является ли клетка home-клеткой другого именного NPC (избегаем коллизий). */
    private function isHomeCellOfAnotherNamed(int $cellNumber, int $selfNpcId): bool
    {
        $named = $this->npcs->where('npc_type', 'named')->findAll();
        foreach ($named as $npc) {
            if (! is_array($npc)) {
                continue;
            }
            $idRaw = $npc['id'] ?? null;
            $id    = is_numeric($idRaw) ? (int) $idRaw : 0;
            if ($id === $selfNpcId) {
                continue;
            }
            $s    = $this->decodeSettings($npc['custom_settings'] ?? null);
            $hRaw = $s['home_cell'] ?? null;
            if (is_numeric($hRaw) && (int) $hRaw === $cellNumber) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function decodeSettings($raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function enabled(): bool
    {
        $v = $this->settings->get('npc.named_placement_enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}
