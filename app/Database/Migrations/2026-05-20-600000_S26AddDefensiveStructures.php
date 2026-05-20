<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S26 (v0.51.207, ROADMAP-CRAFT §9 Фаза 6 — старт; ADR-030) — defensive structures.
 *
 * WoodenWall (−% урона защитнику у базы) + BarbedFence (flat урон атакующему/раунд).
 * Эффект детерминированный, только когда защитник стоит на своей клетке со
 * структурами (см. DefenseStructureService); combat-knobs в GameSettings
 * (соседняя миграция). WatchTower → S26b.
 *
 * Storage: reuse building-системы — building_type='defensive' (нужен ALTER обоих
 * ENUM), build через GenericBuildingAction (Config\Buildings), completion через
 * generic_building → character_buildings row (hp из buildings.hp, decays в бою).
 *
 * Idempotent: ALTER MODIFY повторяем; INSERT — с проверкой существования.
 */
class S26AddDefensiveStructures extends Migration
{
    private const ENUM = "ENUM('military','residential','farming','resource','engineering','defensive')";

    public function up(): void
    {
        // 1. Расширяем ENUM building_type (+defensive) в обеих таблицах.
        $this->db->query('ALTER TABLE buildings MODIFY building_type ' . self::ENUM . ' NOT NULL');
        $this->db->query('ALTER TABLE character_buildings MODIFY building_type ' . self::ENUM . ' NOT NULL');

        $now = date('Y-m-d H:i:s');

        // 2. buildings rows (hp/tax — building-колонки; combat-балАнсы в GameSettings).
        $buildings = [
            [
                'name_ru'             => 'Деревянная стена',
                'name_en'             => 'WoodenWall',
                'description'         => 'Защитная деревянная стена вокруг базы. Снижает урон, который ты получаешь в PvP-бою, пока стоишь на своей базе. Изнашивается при каждой отбитой атаке.',
                'building_type'       => 'defensive',
                'hp'                  => 200,
                'construction_time'   => 60,
                'tax'                 => 200,
                'level'               => 1,
                'usage'               => 'personal',
                'required_resources'  => json_encode(['Древесина' => 800, 'Глина' => 200, 'Древесные доски' => 20, 'Металл фрагменты' => 10], JSON_UNESCAPED_UNICODE),
                'min_character_level' => 8,
                'effects'             => json_encode(['effect' => 'defense_wall'], JSON_UNESCAPED_UNICODE),
                'days_until_disappearance' => 0,
            ],
            [
                'name_ru'             => 'Колючая ограда',
                'name_en'             => 'BarbedFence',
                'description'         => 'Ограда из колючей проволоки. Каждый раунд PvP-боя у твоей базы наносит урон атакующему. Изнашивается при каждой отбитой атаке.',
                'building_type'       => 'defensive',
                'hp'                  => 80,
                'construction_time'   => 45,
                'tax'                 => 350,
                'level'               => 1,
                'usage'               => 'personal',
                'required_resources'  => json_encode(['Железная руда' => 60, 'Редкие металлы' => 15, 'Проводка' => 8, 'Металл фрагменты' => 15], JSON_UNESCAPED_UNICODE),
                'min_character_level' => 10,
                'effects'             => json_encode(['effect' => 'defense_fence'], JSON_UNESCAPED_UNICODE),
                'days_until_disappearance' => 0,
            ],
        ];
        foreach ($buildings as $b) {
            $exists = $this->db->table('buildings')->where('name_en', $b['name_en'])->get()->getRowArray();
            if (empty($exists)) {
                $this->db->table('buildings')->insert($b);
            }
        }

        // 3. tasks rows (build* → generic_building handler).
        $tasks = [
            ['name' => 'buildWoodenWall',  'name_rus' => 'Строительство деревянной стены', 'description' => 'Возведение защитной деревянной стены вокруг базы.', 'min_duration' => 60, 'max_duration' => 90],
            ['name' => 'buildBarbedFence', 'name_rus' => 'Строительство колючей ограды',    'description' => 'Возведение колючей ограды вокруг базы.',          'min_duration' => 45, 'max_duration' => 70],
        ];
        foreach ($tasks as $t) {
            $exists = $this->db->table('tasks')->where('name', $t['name'])->get()->getRowArray();
            if (empty($exists)) {
                $this->db->table('tasks')->insert([
                    'name'                       => $t['name'],
                    'handler_key'                => 'generic_building',
                    'name_rus'                   => $t['name_rus'],
                    'description'                => $t['description'],
                    'min_duration'               => $t['min_duration'],
                    'max_duration'               => $t['max_duration'],
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
    }

    public function down(): void
    {
        $this->db->table('tasks')->whereIn('name', ['buildWoodenWall', 'buildBarbedFence'])->delete();
        $this->db->table('buildings')->whereIn('name_en', ['WoodenWall', 'BarbedFence'])->delete();
        // ENUM откатываем к исходному набору (только если нет defensive-строк).
        $hasDefensive = (int) ($this->db->table('character_buildings')->where('building_type', 'defensive')->countAllResults());
        if ($hasDefensive === 0) {
            $orig = "ENUM('military','residential','farming','resource','engineering')";
            $this->db->query('ALTER TABLE character_buildings MODIFY building_type ' . $orig . ' NOT NULL');
            $this->db->query('ALTER TABLE buildings MODIFY building_type ' . $orig . ' NOT NULL');
        }
    }
}
