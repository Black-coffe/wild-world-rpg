<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W4 (ADR-063) — crafted_items + tasks для Repair-drone build.
 *
 * Net-new drone-item (type='drones', name_eng='DroneRepair') параллельный
 * DroneScout/DroneCargo. durability_count = battery_max (100) при крафте.
 * Gate через Config\CraftRecipes::required_building_levels (RoboticsWorkshop L3).
 *
 * Idempotent по name_eng / task.name.
 */
class W4AddDroneRepairItem extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            [
                'name_rus'          => 'Дрон-ремонтник',
                'name_eng'          => 'DroneRepair',
                'type'              => 'drones',
                'direction_craft'   => 'drones',
                'effect'            => 'repair',
                'gathering_speed'   => 0,
                'durability_count'  => 100,
                'damage'            => 0,
                'weight'            => 6.00,
                'price'             => 60000.00,
                'required_level'    => 8,
                'crafting_time'     => 75,
                'crafting_location' => 'On base',
                'description'       => 'Полевой дрон-ремонтник: паяльная станция, набор запчастей и манипулятор на slung-cage. Один взлёт чинит всех роботов на твоей базе разом за чистое золото — никаких ресурсов, только цена работы. Заряжается на базе около 4 часов.',
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

        $tasks = [
            [
                'name'         => 'craftDroneRepair',
                'name_rus'     => 'Крафт дрон-ремонтника',
                'description'  => 'Сборка полевого дрон-ремонтника (батарея 100, recharge 4 ч, gold-only batch ремонт всех роботов чара).',
                'min_duration' => 65,
                'max_duration' => 85,
            ],
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
                'difficulty_level'           => 7,
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
        $this->db->table('crafted_items')->where('name_eng', 'DroneRepair')->delete();
        $this->db->table('tasks')->where('name', 'craftDroneRepair')->delete();
    }
}
