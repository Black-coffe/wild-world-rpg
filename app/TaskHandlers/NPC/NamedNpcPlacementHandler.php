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
 *
 * ⚠️ Модели создаются СВЕЖИМИ на каждую операцию (не переиспользуем поля instance): CI4 Model
 * накапливает builder-state между where()/find()/findAll() в одном инстансе → интермиттентно
 * пустые результаты (memory feedback_ci4_model_builder_state_quirk). Именной список грузим один
 * раз в память и передаём в хелперы, без повторных запросов npcs.
 */
class NamedNpcPlacementHandler
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

        $named = (new NpcModel())->where('npc_type', 'named')->findAll();
        if ($named === []) {
            return;
        }

        // Уже занятые home-клетки (из персиста) — чтобы новые назначения не пересекались.
        $takenHomes = [];
        foreach ($named as $n) {
            if (! is_array($n)) {
                continue;
            }
            $home = $this->decodeSettings($n['custom_settings'] ?? null)['home_cell'] ?? null;
            if (is_numeric($home)) {
                $takenHomes[(int) $home] = true;
            }
        }

        foreach ($named as $npc) {
            if (! is_array($npc)) {
                continue;
            }
            $npcId = is_numeric($npc['id'] ?? null) ? (int) $npc['id'] : 0;
            if ($npcId <= 0) {
                continue;
            }

            // Уже есть живой спавн этого именного NPC — ничего не делаем (ровно 1 присутствует).
            if ((new NpcSpawnModel())->where('npc_id', $npcId)->where('status', 'alive')->countAllResults() > 0) {
                continue;
            }

            $home = $this->homeCellFor($npc, $takenHomes);
            if ($home === null) {
                continue; // не удалось назначить клетку — попробуем на следующем тике
            }
            $takenHomes[$home['cell']] = true;

            // Не ставим второго NPC на занятую клетку (другой спавн уже там).
            if ((new NpcSpawnModel())->where('cell_number', $home['cell'])->where('status', 'alive')->countAllResults() > 0) {
                continue;
            }

            $healthRaw = $npc['health'] ?? null;
            (new NpcSpawnModel())->insert([
                'npc_id'         => $npcId,
                'cell_number'    => $home['cell'],
                'coordinate_x'   => $home['x'],
                'coordinate_y'   => $home['y'],
                'current_health' => is_numeric($healthRaw) ? (float) $healthRaw : 100.0,
                'spawned_at'     => date('Y-m-d H:i:s'),
                'status'         => 'alive',
            ]);
        }
    }

    /**
     * home-клетка именного NPC: из custom_settings.home_cell; если нет — случайная свободная
     * клетка населённого яруса (персистим). Стабильна между тиками.
     *
     * @param array<int|string,mixed> $npc
     * @param array<int,bool>         $takenHomes  занятые home-клетки других именных
     * @return array{cell:int, x:int, y:int}|null
     */
    private function homeCellFor(array $npc, array $takenHomes): ?array
    {
        $npcId    = is_numeric($npc['id'] ?? null) ? (int) $npc['id'] : 0;
        $settings = $this->decodeSettings($npc['custom_settings'] ?? null);

        $cellRaw = $settings['home_cell'] ?? null;
        if (is_numeric($cellRaw) && (int) $cellRaw > 0) {
            $xRaw = $settings['home_x'] ?? null;
            $yRaw = $settings['home_y'] ?? null;

            return [
                'cell' => (int) $cellRaw,
                'x'    => is_numeric($xRaw) ? (int) $xRaw : 0,
                'y'    => is_numeric($yRaw) ? (int) $yRaw : 0,
            ];
        }

        // Назначаем новую home-клетку: случайная проходимая клетка населённого яруса,
        // не занятая home-клеткой другого именного и без живого спавна.
        $candidates = (new MapModel())
            ->where('coordinate_y >=', 401)
            ->where('coordinate_y <=', 900)
            ->whereIn('biome_id', [1, 2, 3, 5, 6, 7, 8, 9])
            ->orderBy('RAND()')
            ->findAll(30);

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $cNum = is_numeric($candidate['cell_number'] ?? null) ? (int) $candidate['cell_number'] : 0;
            if ($cNum <= 0 || isset($takenHomes[$cNum])) {
                continue;
            }
            if ((new NpcSpawnModel())->where('cell_number', $cNum)->where('status', 'alive')->countAllResults() > 0) {
                continue;
            }

            $x = is_numeric($candidate['coordinate_x'] ?? null) ? (int) $candidate['coordinate_x'] : 0;
            $y = is_numeric($candidate['coordinate_y'] ?? null) ? (int) $candidate['coordinate_y'] : 0;

            $settings['home_cell'] = $cNum;
            $settings['home_x']    = $x;
            $settings['home_y']    = $y;
            (new NpcModel())->builder()
                ->where('id', $npcId)
                ->update(['custom_settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);

            return ['cell' => $cNum, 'x' => $x, 'y' => $y];
        }

        return null;
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
