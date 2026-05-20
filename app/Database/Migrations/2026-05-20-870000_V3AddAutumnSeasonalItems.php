<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V3 (ADR-032, vNext) — Осенний сезон: 5 сезонных consumable (type='drug') + craft tasks.
 *
 * Тема «Осенняя жатва»: сытные урожайные припасы. Лечат health/tired через
 * UsePharmacyAction (data-driven heal GameSettings medical.<snake>.heal_*,
 * параллельная миграция). Гейт — required_season='autumn' (в CraftRecipes).
 * Низкий гейт (нет building/level).
 *
 * Активен ~22 июля 2026 (anchor 2026-05-20 + 63д Зима+Весна+Лето). Зеркало V1/V2.
 * Idempotent: skip если name_eng / task name уже есть.
 */
class V3AddAutumnSeasonalItems extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            ['name_rus' => 'Ягодное варенье',  'name_eng' => 'AutumnBerryJam',     'boost' => '[{"Здоровье": 55,"Выносливость": 15}]', 'price' => 200, 'description' => 'Густое варенье из осенних ягод на меду. Сладкое и сытное — хорошо восстанавливает здоровье. Сезонный рецепт «Осенней жатвы».'],
            ['name_rus' => 'Грибное рагу',     'name_eng' => 'AutumnMushroomStew', 'boost' => '[{"Здоровье": 35,"Выносливость": 35}]', 'price' => 250, 'description' => 'Сытное рагу из лесных грибов с овощами. Горячая осенняя еда, восстанавливает здоровье и силы. Сезонный рецепт «Осенней жатвы».'],
            ['name_rus' => 'Ореховая смесь',   'name_eng' => 'AutumnNutMix',       'boost' => '[{"Здоровье": 25,"Выносливость": 45}]', 'price' => 220, 'description' => 'Смесь лесных орехов с мёдом. Калорийный перекус, быстро возвращает силы в дорогу. Сезонный рецепт «Осенней жатвы».'],
            ['name_rus' => 'Сидр',             'name_eng' => 'AutumnCider',        'boost' => '[{"Здоровье": 30,"Выносливость": 40}]', 'price' => 240, 'description' => 'Яблочный сидр осеннего отжима. Освежает и подкрепляет, бодрит после работы в поле. Сезонный рецепт «Осенней жатвы».'],
            ['name_rus' => 'Овощные консервы', 'name_eng' => 'AutumnVegPreserves', 'boost' => '[{"Здоровье": 45,"Выносливость": 25}]', 'price' => 280, 'description' => 'Овощные консервы в банках — заготовка урожая на зиму. Сытно подкрепляет здоровье. Сезонный рецепт «Осенней жатвы».'],
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
            ['name' => 'craftAutumnBerryJam',     'name_rus' => 'Крафт: Ягодное варенье (сезон)',  'description' => 'Варка густого ягодного варенья на меду.'],
            ['name' => 'craftAutumnMushroomStew', 'name_rus' => 'Крафт: Грибное рагу (сезон)',     'description' => 'Приготовление сытного грибного рагу с овощами.'],
            ['name' => 'craftAutumnNutMix',       'name_rus' => 'Крафт: Ореховая смесь (сезон)',   'description' => 'Смешивание калорийной ореховой смеси с мёдом.'],
            ['name' => 'craftAutumnCider',        'name_rus' => 'Крафт: Сидр (сезон)',             'description' => 'Брожение яблочного сидра осеннего отжима.'],
            ['name' => 'craftAutumnVegPreserves', 'name_rus' => 'Крафт: Овощные консервы (сезон)', 'description' => 'Закатка овощных консервов на зиму.'],
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
            'craftAutumnBerryJam', 'craftAutumnMushroomStew', 'craftAutumnNutMix',
            'craftAutumnCider', 'craftAutumnVegPreserves',
        ])->delete();
        $this->db->table('crafted_items')->whereIn('name_eng', [
            'AutumnBerryJam', 'AutumnMushroomStew', 'AutumnNutMix',
            'AutumnCider', 'AutumnVegPreserves',
        ])->delete();
    }
}
