<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V1 (ADR-032, vNext) — Весенний сезон: 5 сезонных consumable (type='drug') + craft tasks.
 *
 * Тема «Весеннее пробуждение»: освежающие/бодрящие припасы (контраст зимним
 * согревающим). Лечат health/tired через UsePharmacyAction (data-driven heal
 * GameSettings medical.<snake>.heal_*, параллельная миграция). Гейт —
 * required_season='spring' (в CraftRecipes). Низкий гейт (нет building/level).
 *
 * Активен ~10 июня 2026 (anchor 2026-05-20 + 21д Зима). Зеркало S28 winter pilot.
 * Idempotent: skip если name_eng / task name уже есть.
 */
class V1AddSpringSeasonalItems extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            ['name_rus' => 'Чай из первой травы', 'name_eng' => 'SpringFirstHerbTea',      'boost' => '[{"Здоровье": 25,"Выносливость": 45}]', 'price' => 200, 'description' => 'Чай из первой весенней травы на родниковой воде. Бодрит и возвращает силы после долгой зимы. Сезонный рецепт «Весеннего пробуждения».'],
            ['name_rus' => 'Берёзовый сок',       'name_eng' => 'SpringBirchSap',          'boost' => '[{"Здоровье": 30,"Выносливость": 40}]', 'price' => 220, 'description' => 'Свежий берёзовый сок, собранный по весне. Освежает, утоляет жажду и восстанавливает здоровье и силы. Сезонный рецепт «Весеннего пробуждения».'],
            ['name_rus' => 'Настой первоцвета',   'name_eng' => 'SpringPrimroseInfusion',  'boost' => '[{"Здоровье": 55,"Выносливость": 15}]', 'price' => 300, 'description' => 'Настой первоцвета и молодых листьев. Лечит раны и придаёт бодрость — целебная сила первых цветов. Сезонный рецепт «Весеннего пробуждения».'],
            ['name_rus' => 'Весенняя зелень',     'name_eng' => 'SpringWildGreens',        'boost' => '[{"Здоровье": 35,"Выносливость": 30}]', 'price' => 250, 'description' => 'Салат из дикой весенней зелени — крапива, сныть, черемша. Лёгкая витаминная еда, восстанавливает здоровье и силы. Сезонный рецепт «Весеннего пробуждения».'],
            ['name_rus' => 'Отвар молодых побегов', 'name_eng' => 'SpringShootsDecoction', 'boost' => '[{"Здоровье": 45,"Выносливость": 25}]', 'price' => 270, 'description' => 'Отвар из молодых побегов и почек. Восстановительное средство, поднимает на ноги после зимнего истощения. Сезонный рецепт «Весеннего пробуждения».'],
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
            ['name' => 'craftSpringFirstHerbTea',     'name_rus' => 'Крафт: Чай из первой травы (сезон)',  'description' => 'Заваривание бодрящего чая из первой весенней травы.'],
            ['name' => 'craftSpringBirchSap',         'name_rus' => 'Крафт: Берёзовый сок (сезон)',        'description' => 'Сбор и розлив свежего берёзового сока.'],
            ['name' => 'craftSpringPrimroseInfusion', 'name_rus' => 'Крафт: Настой первоцвета (сезон)',    'description' => 'Приготовление целебного настоя первоцвета.'],
            ['name' => 'craftSpringWildGreens',       'name_rus' => 'Крафт: Весенняя зелень (сезон)',      'description' => 'Сбор салата из дикой весенней зелени.'],
            ['name' => 'craftSpringShootsDecoction',  'name_rus' => 'Крафт: Отвар молодых побегов (сезон)', 'description' => 'Варка восстановительного отвара из молодых побегов.'],
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
            'craftSpringFirstHerbTea', 'craftSpringBirchSap', 'craftSpringPrimroseInfusion',
            'craftSpringWildGreens', 'craftSpringShootsDecoction',
        ])->delete();
        $this->db->table('crafted_items')->whereIn('name_eng', [
            'SpringFirstHerbTea', 'SpringBirchSap', 'SpringPrimroseInfusion',
            'SpringWildGreens', 'SpringShootsDecoction',
        ])->delete();
    }
}
