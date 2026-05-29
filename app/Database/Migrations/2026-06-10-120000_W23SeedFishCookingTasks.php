<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W23 (ADR-078) — task-реестр для рыбных блюд.
 *
 * GenericCraftActionStart ищет tasks.name = recipe.task_name (иначе «Задача не найдена»).
 * Зеркало V8AddCookingItems: handler_key='generic_craft', type='craft', 10-20 мин.
 * Idempotent. Поймано Tier-3 (craftFishSoup не найдена в базе).
 */
class W23SeedFishCookingTasks extends Migration
{
    public function up(): void
    {
        $now   = date('Y-m-d H:i:s');
        $tasks = [
            ['name' => 'craftFishSoup',     'name_rus' => 'Готовка: Уха',             'description' => 'Варка ухи из свежей рыбы на костре.'],
            ['name' => 'craftGrilledFish',  'name_rus' => 'Готовка: Жареная рыба',    'description' => 'Жарка рыбы на углях.'],
            ['name' => 'craftFishPreserve', 'name_rus' => 'Консервация: Рыбные консервы', 'description' => 'Закатка рыбных консервов долгого хранения.'],
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
                'min_duration'               => 10,
                'max_duration'               => 20,
                'type'                       => 'craft',
                'difficulty_level'           => 1,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 0,
                'interruptible'              => 1,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('tasks')->whereIn('name', [
            'craftFishSoup', 'craftGrilledFish', 'craftFishPreserve',
        ])->delete();
    }
}
