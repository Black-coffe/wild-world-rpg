<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W1 (ADR-058) — crafted_items + tasks для Drone-recon foundation.
 *
 * Net-new drone-item (`type='drones'`, `name_eng='DroneScout'`) и craft-task
 * (`handler_key='generic_craft'`). durability_count = battery_max (100) → новый
 * скрафченный дрон получает полный заряд (как у роботов).
 *
 * Gate: RoboticsWorkshop L1 (через `Config\CraftRecipes::required_building_levels`,
 * S16/ADR-026). MVP gate низкий: дрон — foundation Фазы 1 vNext2, не тяжёлый
 * эндгейм. W2-W4 (Cargo/Repair/Combat drones) могут гейтить L2/L3.
 *
 * Idempotent (по name_eng / task.name). Recipe в CraftRecipes — отдельным
 * патчем, не в этой миграции (миграция трогает только DB-таблицы).
 */
class W1AddDroneScoutItem extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // ── crafted_items (DroneScout) ───────────────────────────────────────
        $items = [
            [
                'name_rus'          => 'Дрон-разведчик',
                'name_eng'          => 'DroneScout',
                'type'              => 'drones',
                'direction_craft'   => 'drones',
                'effect'            => 'scout',
                'gathering_speed'   => 0,
                // durability_count = battery_max (см. drone.scout.battery_max GameSetting).
                // crafted_items_log.durability_count при крафте = это значение.
                // RecceDroneAction (W2) drain'ает per-launch, DroneRechargeCron (W2) восстанавливает on_base.
                'durability_count'  => 100,
                'damage'            => 0,
                'weight'            => 3.50,
                'price'             => 25000.00,
                'required_level'    => 3,
                'crafting_time'     => 40,
                'crafting_location' => 'On base',
                'description'       => 'Ручной разведывательный квадрокоптер из проводов, salvaged motors и колец wire. Запускается с любой клетки игрока, мгновенно открывает зону вокруг (радиус 10 клеток). Заряжается только на базе (около 2 часов до полного заряда). Один запуск = одна разрядка батареи.',
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

        // ── tasks (craft-задача для GenericCraftActionStart) ─────────────────
        $tasks = [
            [
                'name'         => 'craftDroneScout',
                'name_rus'     => 'Крафт дрона-разведчика',
                'description'  => 'Сборка ручного разведывательного квадрокоптера (radius=10 scan, battery=100, recharge 2ч).',
                'min_duration' => 30,
                'max_duration' => 50,
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
                'difficulty_level'           => 4,
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
        $this->db->table('crafted_items')->where('name_eng', 'DroneScout')->delete();
        $this->db->table('tasks')->where('name', 'craftDroneScout')->delete();
    }
}
