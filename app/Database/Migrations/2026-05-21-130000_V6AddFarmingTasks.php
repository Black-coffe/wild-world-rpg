<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V6 (ADR-033) — task-rows для активного земледелия.
 *
 *  - 4 seed-краф-таска (handler_key=generic_craft): рецепты в Config\CraftRecipes
 *    с output_type='resource' → семя начисляется в character_resources.
 *  - 1 plantCrop-таск (handler_key=planting): посадка культуры. Реальное grow-время
 *    выставляет PlantCropActionStart из GameSettings (farming.grow.*); min/max_duration
 *    здесь — placeholder-fallback.
 *
 * handler_key выставлен явно (B6 DB-level priority, ADR-023). Idempotent.
 */
class V6AddFarmingTasks extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $craftTasks = [
            ['name' => 'craftBerrySeeds',    'name_rus' => 'Крафт: Семена ягод',    'description' => 'Отбор посевного материала из ягод.'],
            ['name' => 'craftMushroomSeeds', 'name_rus' => 'Крафт: Семена грибов',   'description' => 'Отбор грибницы из грибов.'],
            ['name' => 'craftFruitSeeds',    'name_rus' => 'Крафт: Семена фруктов',  'description' => 'Отбор косточек и семечек из фруктов.'],
            ['name' => 'craftCropSeeds',     'name_rus' => 'Крафт: Семена овощей',   'description' => 'Отбор семенного зерна из зерновых культур.'],
        ];

        foreach ($craftTasks as $taskRow) {
            $existing = $this->db->table('tasks')->where('name', $taskRow['name'])->get()->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('tasks')->insert([
                'name'                       => $taskRow['name'],
                'handler_key'                => 'generic_craft',
                'name_rus'                   => $taskRow['name_rus'],
                'description'                => $taskRow['description'],
                'min_duration'               => 5,
                'max_duration'               => 5,
                'type'                       => 'craft',
                'difficulty_level'           => 1,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 0,
                'interruptible'              => 1,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }

        // plantCrop — посадка культуры в теплице (handler_key=planting).
        $plant = $this->db->table('tasks')->where('name', 'plantCrop')->get()->getRowArray();
        if (empty($plant)) {
            $this->db->table('tasks')->insert([
                'name'                       => 'plantCrop',
                'handler_key'                => 'planting',
                'name_rus'                   => 'Посадка культуры',
                'description'                => 'Рост посаженной культуры в теплице. Время роста зависит от культуры (farming.grow.*).',
                'min_duration'               => 30,
                'max_duration'               => 30,
                'type'                       => 'planting',
                'difficulty_level'           => 1,
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
        $this->db->table('tasks')->whereIn('name', [
            'craftBerrySeeds',
            'craftMushroomSeeds',
            'craftFruitSeeds',
            'craftCropSeeds',
            'plantCrop',
        ])->delete();
    }
}
