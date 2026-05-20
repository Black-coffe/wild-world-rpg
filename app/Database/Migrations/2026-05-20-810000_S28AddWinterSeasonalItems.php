<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S28 (ADR-032) — Зимний pilot: 5 сезонных consumable (type='drug') + craft tasks.
 *
 * Тема «Зима выживания»: согревающие припасы. Лечат health/tired через
 * UsePharmacyAction (data-driven heal GameSettings medical.<snake>.heal_*,
 * параллельная миграция). Гейт — required_season='winter' (в CraftRecipes).
 * Низкий гейт (нет building/level) — максимум вовлечения в событие.
 *
 * Idempotent: skip если name_eng / task name уже есть.
 */
class S28AddWinterSeasonalItems extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            ['name_rus' => 'Горячий травяной отвар', 'name_eng' => 'WinterHerbalBrew',  'boost' => '[{"Здоровье": 40,"Выносливость": 20}]', 'price' => 200, 'description' => 'Горячий травяной отвар на талой воде. Согревает изнутри, восстанавливает силы в зимнюю стужу. Сезонный рецепт «Зимы выживания».'],
            ['name_rus' => 'Медовый сбитень',        'name_eng' => 'WinterHoneyMead',   'boost' => '[{"Здоровье": 25,"Выносливость": 45}]', 'price' => 220, 'description' => 'Медовый сбитень — горячий напиток на меду и пряностях. Бодрит и прогоняет усталость от мороза. Сезонный рецепт «Зимы выживания».'],
            ['name_rus' => 'Согревающая мазь',       'name_eng' => 'WinterWarmingBalm', 'boost' => '[{"Здоровье": 60,"Выносливость": 0}]',  'price' => 300, 'description' => 'Согревающая мазь из животного жира и трав. Лечит обморожения и раны, возвращает здоровье. Сезонный рецепт «Зимы выживания».'],
            ['name_rus' => 'Походная похлёбка',      'name_eng' => 'WinterCampStew',    'boost' => '[{"Здоровье": 35,"Выносливость": 35}]', 'price' => 250, 'description' => 'Густая походная похлёбка на костре. Сытная и горячая — восстанавливает и здоровье, и силы. Сезонный рецепт «Зимы выживания».'],
            ['name_rus' => 'Зимние заготовки',       'name_eng' => 'WinterPreserves',   'boost' => '[{"Здоровье": 50,"Выносливость": 25}]', 'price' => 280, 'description' => 'Зимние заготовки — копчёности и соленья в банках. Запас на холода, хорошо подкрепляет. Сезонный рецепт «Зимы выживания».'],
        ];

        foreach ($items as $item) {
            $existing = $this->db->table('crafted_items')->where('name_eng', $item['name_eng'])->get()->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('crafted_items')->insert([
                'name_rus'           => $item['name_rus'],
                'name_eng'           => $item['name_eng'],
                'type'               => 'drug',
                'direction_craft'    => 'treatment',
                'effect'             => 'boost',
                'durability_count'   => 1,
                'durability_time'    => null,
                'damage'             => 0,
                'armor'              => 0,
                'hp'                 => 0,
                'character_boost'    => $item['boost'],
                'weight'             => 0.20,
                'price'              => $item['price'],
                'stack_size'         => 10,
                'required_level'     => 1,
                'required_skills'    => null,
                'required_resources' => null,
                'crafting_time'      => null,
                'crafting_location'  => 'Workbench General',
                'description'        => $item['description'],
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        $tasks = [
            ['name' => 'craftWinterHerbalBrew',  'name_rus' => 'Крафт: Горячий травяной отвар (сезон)', 'description' => 'Заваривание согревающего травяного отвара.'],
            ['name' => 'craftWinterHoneyMead',   'name_rus' => 'Крафт: Медовый сбитень (сезон)',        'description' => 'Приготовление горячего медового сбитня.'],
            ['name' => 'craftWinterWarmingBalm', 'name_rus' => 'Крафт: Согревающая мазь (сезон)',       'description' => 'Изготовление согревающей мази от обморожений.'],
            ['name' => 'craftWinterCampStew',    'name_rus' => 'Крафт: Походная похлёбка (сезон)',       'description' => 'Варка сытной походной похлёбки.'],
            ['name' => 'craftWinterPreserves',   'name_rus' => 'Крафт: Зимние заготовки (сезон)',        'description' => 'Заготовка копчёностей и солений на зиму.'],
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
                'min_duration'               => 20,
                'max_duration'               => 40,
                'type'                       => 'craft',
                'difficulty_level'           => 2,
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
            'craftWinterHerbalBrew', 'craftWinterHoneyMead', 'craftWinterWarmingBalm',
            'craftWinterCampStew', 'craftWinterPreserves',
        ])->delete();
        $this->db->table('crafted_items')->whereIn('name_eng', [
            'WinterHerbalBrew', 'WinterHoneyMead', 'WinterWarmingBalm',
            'WinterCampStew', 'WinterPreserves',
        ])->delete();
    }
}
