<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\Bases\CampCheckService;
use App\Services\World\NodeLevelCurve;
use CodeIgniter\Database\Migration;

/**
 * WB4 (ADR-137 «Узлы») — seed 24 курируемых точек-узлов + миграция 3 боссов ADR-106 в passive.
 *
 * Решение владельца (2026-06-22): 24 узла, разброс по всей карте, скопление центр+север
 * (север = низкий Y). Распределение: север (Y<350) 9 / центр (350-790) 11 / юг (≥820) 4.
 *
 * Размещение КУРИРУЕМОЕ (якоря заданы), но каждая точка ВАЛИДИРУЕТСЯ на реальной карте:
 *   - суша (biome_id ∈ 1,2,3,5,6,7,8,9; 4=вода исключена);
 *   - не край (20..979 по обеим осям);
 *   - не чужой claim (CampCheckService::isCellClaimedByAnyone — реюз);
 *   - не занята другой точкой (cell_number UNIQUE).
 * Если у якоря не нашлось валидной клетки — слот пропускается с записью в лог (НЕ молча).
 *
 * Уровень/HP — гео-кривая NodeLevelCurve (base_level = f(Y); HP = §2). status='cooldown',
 * respawn_at=null → NodeRespawnHandler (WB5) материализует npc_spawn при активации point-режима.
 * Идемпотентно (skip если точки уже наполнены).
 *
 * 🔎 АУДИТ-КОРРЕКЦИЯ (2026-06-22): боссы ADR-106 СЕЙЧАС ЖИВЫ на проде (`world.bosses.enabled`=1,
 * 3 aggressive-спавна, игроки бьют их авто-PvE). Перевод в `ai_behavior='passive'` СЕЙЧАС (при
 * point_mode OFF и без encounter-UI WB6) сделал бы их inert/неубиваемыми = живой регресс. Поэтому
 * WB4 создаёт ТОЛЬКО инертные данные boss_points; перевод боссов в passive + materialize точек
 * происходит ПРИ АКТИВАЦИИ (`world.nodes.point_mode_enabled`=ON, после WB6) — NodeRespawnHandler/WB16.
 * RaiderGradientHandler уже уступает при point_mode ON (этой сессией).
 *
 * WipeManifest: boss_points=SEED_RESET (классифицировано в Config\WipeManifest этой сессией).
 */
class Adr137SeedBossPoints extends Migration
{
    /** archetype key => npc_name_en (ADR-106). */
    private const ARCHETYPE_NPC = [
        'scar'      => 'boss_scar_butcher',
        'cerberus'  => 'boss_cerberus_prototype',
        'patriarch' => 'boss_pack_patriarch',
    ];

    /**
     * 24 курируемых якоря: [targetY, targetX, archetype]. Север=низкий Y.
     * 9 север (Y<350) / 11 центр (350-790) / 4 юг (≥820).
     *
     * @var list<array{int,int,string}>
     */
    private const ANCHORS = [
        // — Север (9): мутанты-Патриархи + машины-Церберы (пояс опасности) —
        [80, 200, 'patriarch'], [110, 500, 'cerberus'], [90, 800, 'patriarch'],
        [200, 350, 'cerberus'], [230, 650, 'patriarch'], [160, 120, 'cerberus'],
        [300, 880, 'patriarch'], [280, 480, 'cerberus'], [320, 250, 'patriarch'],
        // — Центр (11): смесь всех трёх —
        [400, 150, 'scar'], [420, 560, 'cerberus'], [450, 820, 'patriarch'],
        [520, 300, 'scar'], [560, 680, 'cerberus'], [600, 450, 'patriarch'],
        [640, 880, 'scar'], [680, 180, 'cerberus'], [700, 600, 'patriarch'],
        [740, 380, 'scar'], [780, 760, 'cerberus'],
        // — Юг (4): люди-изгои Шрамы (зона новичков, низкий уровень) —
        [850, 250, 'scar'], [880, 600, 'scar'], [910, 850, 'scar'], [945, 420, 'scar'],
    ];

    private const LAND_BIOMES = [1, 2, 3, 5, 6, 7, 8, 9];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // ⚠ НЕ трогаем ai_behavior боссов здесь: они ЖИВЫ на проде (см. докблок). Перевод в
        //   passive — при активации point-режима (после WB6), иначе живой регресс.

        // Идемпотентность: уже наполнено целевым числом — выходим.
        $target   = 24;
        $existing = (int) $this->db->table('boss_points')->countAllResults();
        if ($existing >= $target) {
            return;
        }

        // 3) Резолв npc_id боссов-архетипов по npc_name_en.
        $npcIdByArch = [];
        foreach (self::ARCHETYPE_NPC as $arch => $nameEn) {
            $row = $this->db->table('npcs')->select('id')->where('npc_name_en', $nameEn)->get()->getRowArray();
            $idRaw = $row['id'] ?? null;
            $npcIdByArch[$arch] = is_numeric($idRaw) ? (int) $idRaw : null;
        }

        $curve     = new NodeLevelCurve();
        $campCheck = new CampCheckService();
        $usedCells = [];
        $placed    = 0;

        foreach (self::ANCHORS as [$targetY, $targetX, $arch]) {
            if ($existing + $placed >= $target) {
                break;
            }
            $cell = $this->findValidCell($campCheck, $usedCells, $targetY, $targetX);
            if ($cell === null) {
                log_message('warning', "[SeedBossPoints] нет валидной клетки у якоря Y={$targetY} X={$targetX} ({$arch}) — слот пропущен");
                continue;
            }

            $usedCells[$cell['cell_number']] = true;
            $y         = $cell['coordinate_y'];
            $baseLevel = $curve->baseLevelForY($y);
            $maxHealth = $curve->healthForLevel($baseLevel);

            $this->db->table('boss_points')->insert([
                'cell_number'    => $cell['cell_number'],
                'coordinate_x'   => $cell['coordinate_x'],
                'coordinate_y'   => $y,
                'biome_id'       => $cell['biome_id'],
                'y_band'         => $curve->yBand($y),
                'base_level'     => $baseLevel,
                'current_level'  => $baseLevel,
                'current_health' => $maxHealth,
                'max_health'     => $maxHealth,
                'status'         => 'cooldown',   // WB5 материализует npc_spawn при активации
                'respawn_at'     => null,
                'kill_count'     => 0,
                'current_npc_id' => $npcIdByArch[$arch] ?? null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $placed++;
        }

        log_message('info', "[SeedBossPoints] размещено точек: {$placed} (цель {$target}, было {$existing})");
    }

    /**
     * Ищет валидную клетку у якоря: суша, не край, не claim, не занята.
     * Кандидаты — в широтном окне ±55 от targetY, отсортированы по близости к targetX.
     *
     * @param array<int,bool> $usedCells
     * @return array{cell_number:int,coordinate_x:int,coordinate_y:int,biome_id:int|null}|null
     */
    private function findValidCell(CampCheckService $campCheck, array $usedCells, int $targetY, int $targetX): ?array
    {
        $yFrom = max(20, $targetY - 55);
        $yTo   = min(979, $targetY + 55);

        $cands = $this->db->table('map')
            ->select('cell_number, coordinate_x, coordinate_y, biome_id')
            ->where('coordinate_y >=', $yFrom)
            ->where('coordinate_y <=', $yTo)
            ->where('coordinate_x >=', 20)
            ->where('coordinate_x <=', 979)
            ->whereIn('biome_id', self::LAND_BIOMES)
            ->orderBy('ABS(coordinate_x - ' . $targetX . ')', 'ASC', false)
            ->limit(120)
            ->get()
            ->getResultArray();

        foreach ($cands as $c) {
            $cellRaw = $c['cell_number'] ?? null;
            $cell    = is_numeric($cellRaw) ? (int) $cellRaw : 0;
            if ($cell <= 0 || isset($usedCells[$cell])) {
                continue;
            }
            // не занята другой точкой (на случай частичного прогона)
            $dupe = $this->db->table('boss_points')->where('cell_number', $cell)->countAllResults();
            if ($dupe > 0) {
                continue;
            }
            if ($campCheck->isCellClaimedByAnyone($cell)) {
                continue;
            }
            $xRaw = $c['coordinate_x'] ?? null;
            $yRaw = $c['coordinate_y'] ?? null;
            $bRaw = $c['biome_id'] ?? null;

            return [
                'cell_number'  => $cell,
                'coordinate_x' => is_numeric($xRaw) ? (int) $xRaw : 0,
                'coordinate_y' => is_numeric($yRaw) ? (int) $yRaw : 0,
                'biome_id'     => is_numeric($bRaw) ? (int) $bRaw : null,
            ];
        }

        return null;
    }

    public function down(): void
    {
        // Откат: убрать точки. ai_behavior боссов WB4 не менял → откатывать нечего.
        $this->db->table('boss_points')->truncate();
    }
}
