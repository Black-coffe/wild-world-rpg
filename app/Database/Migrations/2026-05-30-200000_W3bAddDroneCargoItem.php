<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W3b (ADR-060) — crafted_items + tasks для Cargo-drone build.
 *
 * Net-new drone-item (type='drones', name_eng='DroneCargo') параллельный
 * DroneScout. durability_count = battery_max (100) при крафте. Gate через
 * Config\CraftRecipes::required_building_levels (RoboticsWorkshop L2).
 *
 * Idempotent по name_eng / task.name.
 */
class W3bAddDroneCargoItem extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            [
                'name_rus'          => 'Карго-дрон',
                'name_eng'          => 'DroneCargo',
                'type'              => 'drones',
                'direction_craft'   => 'drones',
                'effect'            => 'cargo',
                'gathering_speed'   => 0,
                'durability_count'  => 100,
                'damage'            => 0,
                'weight'            => 5.00,
                'price'             => 35000.00,
                'required_level'    => 5,
                'crafting_time'     => 60,
                'crafting_location' => 'On base',
                'description'       => 'Грузовой квадрокоптер на базе разведывательной платформы — усиленная рама, дополнительный отсек на 30 кг полезной нагрузки. Перебрасывает добычу с любой клетки прямо на склад твоей базы за один вылет. Заряжается на базе около 3 часов.',
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
                'name'         => 'craftDroneCargo',
                'name_rus'     => 'Крафт карго-дрона',
                'description'  => 'Сборка грузового квадрокоптера (payload=30 kg, battery=100, recharge 3ч).',
                'min_duration' => 50,
                'max_duration' => 70,
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
                'difficulty_level'           => 5,
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
        $this->db->table('crafted_items')->where('name_eng', 'DroneCargo')->delete();
        $this->db->table('tasks')->where('name', 'craftDroneCargo')->delete();
    }
}
