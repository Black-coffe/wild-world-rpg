<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W5 (ADR-064) — crafted_items + tasks для Combat-drone build.
 *
 * Net-new drone-item (type='drones', name_eng='DroneCombat') параллельный
 * DroneScout / DroneCargo / DroneRepair. durability_count = battery_max (100)
 * при крафте. Gate через Config\CraftRecipes::required_building_levels
 * (RoboticsWorkshop L4 — capstone Drone-family progression L1→L2→L3→L4).
 *
 * Idempotent по name_eng / task.name.
 */
class W5AddDroneCombatItem extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            [
                'name_rus'          => 'Боевой дрон',
                'name_eng'          => 'DroneCombat',
                'type'              => 'drones',
                'direction_craft'   => 'drones',
                'effect'            => 'combat',
                'gathering_speed'   => 0,
                'durability_count'  => 100,
                'damage'            => 0,
                'weight'            => 7.00,
                'price'             => 90000.00,
                'required_level'    => 10,
                'crafting_time'     => 90,
                'crafting_location' => 'On base',
                'description'       => 'Бронированный квадрокоптер с slung tear-gas canisters и вращающимся лезвием защиты. Активируется по запросу — даёт защитнику преимущество в инициативе на 30 минут. Заряжается на базе около 6 часов. Не атакует — только прикрывает владельца в момент входящего удара.',
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
                'name'         => 'craftDroneCombat',
                'name_rus'     => 'Крафт боевого дрона',
                'description'  => 'Сборка бронированного боевого дрона (батарея 100, recharge 6 ч, +12 процентов инициативы защитнику на 30 мин по запросу).',
                'min_duration' => 80,
                'max_duration' => 100,
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
                'difficulty_level'           => 8,
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
        $this->db->table('crafted_items')->where('name_eng', 'DroneCombat')->delete();
        $this->db->table('tasks')->where('name', 'craftDroneCombat')->delete();
    }
}
