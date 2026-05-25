<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V18 (ADR-049) — 2 T2-робота: crafted_items (RobotScout/RobotIndustrial,
 * type='robots') + craft tasks (handler_key=generic_craft).
 *
 * Зеркалит T1-роботов (id 81 RobotExplorer / 82 RobotGatherer). effect сохраняет
 * семью ('explorer'/'gatherer') для консистентности; рантайм-различие — через
 * RobotService по name_eng. Гейт уровня мастерской — в Config\CraftRecipes
 * (required_building_levels), не здесь. Idempotent (по name_eng / name).
 */
class V18AddRoboticsT2Items extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // ── crafted_items (T2-роботы) ────────────────────────────────────────
        $items = [
            [
                'name_rus'          => 'Робот-разведчик',
                'name_eng'          => 'RobotScout',
                'type'              => 'robots',
                'direction_craft'   => 'robots',
                'effect'            => 'explorer',
                'gathering_speed'   => 1,
                'durability_count'  => 1200,
                'damage'            => 0,
                'weight'            => 130.00,
                'price'             => 150000.00,
                'required_level'    => 5,
                'crafting_time'     => 60,
                'crafting_location' => 'On base',
                'description'       => 'Продвинутый робот-разведчик: за один запуск открывает значительно больше клеток карты, чем обычный исследователь. Требует Мастерскую робототехники 2 уровня.',
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [
                'name_rus'          => 'Робот-промышленник',
                'name_eng'          => 'RobotIndustrial',
                'type'              => 'robots',
                'direction_craft'   => 'robots',
                'effect'            => 'gatherer',
                'gathering_speed'   => 1,
                'durability_count'  => 1200,
                'damage'            => 0,
                'weight'            => 160.00,
                'price'             => 160000.00,
                'required_level'    => 5,
                'crafting_time'     => 65,
                'crafting_location' => 'On base',
                'description'       => 'Промышленный робот-добытчик: приносит больше ресурсов и обходит больше клеток вокруг базы, чем обычный добытчик. Требует Мастерскую робототехники 2 уровня.',
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
        ];

        foreach ($items as $item) {
            $existing = $this->db->table('crafted_items')->where('name_eng', $item['name_eng'])->get()->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('crafted_items')->insert($item);
        }

        // ── tasks (craft-задачи T2) ──────────────────────────────────────────
        $tasks = [
            ['name' => 'craftRobotScout',      'name_rus' => 'Крафт робота-разведчика',     'description' => 'Сборка продвинутого робота-разведчика (открывает больше клеток карты).', 'min_duration' => 45, 'max_duration' => 70],
            ['name' => 'craftRobotIndustrial', 'name_rus' => 'Крафт робота-промышленника',  'description' => 'Сборка промышленного робота-добытчика (больше ресурсов и охват).',       'min_duration' => 46, 'max_duration' => 72],
        ];

        foreach ($tasks as $taskRow) {
            $existing = $this->db->table('tasks')->where('name', $taskRow['name'])->get()->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('tasks')->insert([
                'name'                       => $taskRow['name'],
                'handler_key'                => 'generic_craft',
                'name_rus'                   => $taskRow['name_rus'],
                'description'                => $taskRow['description'],
                'min_duration'               => $taskRow['min_duration'],
                'max_duration'               => $taskRow['max_duration'],
                'type'                       => 'craft',
                'difficulty_level'           => 6,
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
        $this->db->table('crafted_items')->whereIn('name_eng', ['RobotScout', 'RobotIndustrial'])->delete();
        $this->db->table('tasks')->whereIn('name', ['craftRobotScout', 'craftRobotIndustrial'])->delete();
    }
}
