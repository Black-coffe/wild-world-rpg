<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S26b (ROADMAP-CRAFT §9 Фаза 6 — продолжение S26; ADR-031) — WatchTower.
 *
 * Третья (последняя) defensive-структура: 🗼 Дозорная вышка. Два эффекта:
 *   - alert-range detection: пинг владельцу базы, когда чужак входит в радиус
 *     (TowerAlertService, hook в MarchingTaskHandler);
 *   - defender initiative bonus: защитник-владелец чаще бьёт первым у базы
 *     (DefenseStructureService → PvpRoundOrchestrator::determineInitiative,
 *      детерминированно, RNG-fence цел).
 * Combat-knobs (range / initiative / cooldown) — в GameSettings (соседняя
 * миграция). hp/tax — building-колонки.
 *
 * ENUM building_type уже содержит 'defensive' (S26) → ALTER не нужен.
 * Idempotent: INSERT с проверкой существования.
 */
class S26bAddWatchTower extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. buildings row (hp 300 / tax 700 — самые высокие из defensive-трио).
        $building = [
            'name_ru'             => 'Дозорная вышка',
            'name_en'             => 'WatchTower',
            'description'         => 'Наблюдательная вышка над базой. Предупреждает тебя, когда поблизости появляется чужой игрок, и даёт преимущество инициативы в PvP-бою, пока ты защищаешься у своей базы. Изнашивается при каждой отбитой атаке.',
            'building_type'       => 'defensive',
            'hp'                  => 300,
            'construction_time'   => 90,
            'tax'                 => 700,
            'level'               => 1,
            'usage'               => 'personal',
            'required_resources'  => json_encode(['Древесина' => 600, 'Редкие металлы' => 25, 'Проводка' => 15, 'Металл фрагменты' => 20], JSON_UNESCAPED_UNICODE),
            'min_character_level' => 12,
            'effects'             => json_encode(['effect' => 'defense_tower'], JSON_UNESCAPED_UNICODE),
            'days_until_disappearance' => 0,
        ];
        $exists = $this->db->table('buildings')->where('name_en', $building['name_en'])->get()->getRowArray();
        if (empty($exists)) {
            $this->db->table('buildings')->insert($building);
        }

        // 2. task row (buildWatchTower → generic_building handler).
        $taskExists = $this->db->table('tasks')->where('name', 'buildWatchTower')->get()->getRowArray();
        if (empty($taskExists)) {
            $this->db->table('tasks')->insert([
                'name'                       => 'buildWatchTower',
                'handler_key'                => 'generic_building',
                'name_rus'                   => 'Строительство дозорной вышки',
                'description'                => 'Возведение наблюдательной вышки над базой.',
                'min_duration'               => 90,
                'max_duration'               => 120,
                'type'                       => 'building',
                'difficulty_level'           => 3,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('tasks')->where('name', 'buildWatchTower')->delete();
        $this->db->table('buildings')->where('name_en', 'WatchTower')->delete();
        // ENUM не откатываем — им владеет S26-миграция (wall/fence ещё используют 'defensive').
    }
}
