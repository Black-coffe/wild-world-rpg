<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V2 (ADR-032, vNext) — Летний сезон: 5 сезонных consumable (type='drug') + craft tasks.
 *
 * Тема «Летняя жара»: прохладительные/освежающие припасы (контраст зимним
 * согревающим). Лечат health/tired через UsePharmacyAction (data-driven heal
 * GameSettings medical.<snake>.heal_*, параллельная миграция). Гейт —
 * required_season='summer' (в CraftRecipes). Низкий гейт (нет building/level).
 *
 * Активен ~1 июля 2026 (anchor 2026-05-20 + 42д Зима+Весна). Зеркало V1 spring.
 * Idempotent: skip если name_eng / task name уже есть.
 */
class V2AddSummerSeasonalItems extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            ['name_rus' => 'Холодный квас',  'name_eng' => 'SummerColdKvass',  'boost' => '[{"Здоровье": 25,"Выносливость": 45}]', 'price' => 200, 'description' => 'Холодный квас на ржаной закваске. Утоляет жажду и бодрит в летний зной, восстанавливает силы. Сезонный рецепт «Летней жары».'],
            ['name_rus' => 'Лесной морс',    'name_eng' => 'SummerBerryMors',  'boost' => '[{"Здоровье": 30,"Выносливость": 40}]', 'price' => 220, 'description' => 'Прохладный морс из лесных ягод. Освежает и восстанавливает здоровье и силы в самую жару. Сезонный рецепт «Летней жары».'],
            ['name_rus' => 'Фруктовая вода', 'name_eng' => 'SummerFruitWater', 'boost' => '[{"Здоровье": 35,"Выносливость": 35}]', 'price' => 250, 'description' => 'Фруктовая вода с дольками спелых плодов. Хорошо утоляет жажду и подкрепляет в жаркий день. Сезонный рецепт «Летней жары».'],
            ['name_rus' => 'Мятный отвар',   'name_eng' => 'SummerMintTea',    'boost' => '[{"Здоровье": 45,"Выносливость": 25}]', 'price' => 270, 'description' => 'Охлаждающий отвар дикой мяты. Снимает усталость от зноя, освежает и лечит. Сезонный рецепт «Летней жары».'],
            ['name_rus' => 'Алоэ-бальзам',   'name_eng' => 'SummerAloeBalm',   'boost' => '[{"Здоровье": 55,"Выносливость": 15}]', 'price' => 300, 'description' => 'Бальзам из сока алоэ. Лечит солнечные ожоги и тепловой удар, возвращает здоровье. Сезонный рецепт «Летней жары».'],
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
            ['name' => 'craftSummerColdKvass',  'name_rus' => 'Крафт: Холодный квас (сезон)',  'description' => 'Приготовление холодного хлебного кваса.'],
            ['name' => 'craftSummerBerryMors',  'name_rus' => 'Крафт: Лесной морс (сезон)',    'description' => 'Варка прохладного морса из лесных ягод.'],
            ['name' => 'craftSummerFruitWater', 'name_rus' => 'Крафт: Фруктовая вода (сезон)', 'description' => 'Настаивание фруктовой воды на спелых плодах.'],
            ['name' => 'craftSummerMintTea',    'name_rus' => 'Крафт: Мятный отвар (сезон)',   'description' => 'Заваривание охлаждающего мятного отвара.'],
            ['name' => 'craftSummerAloeBalm',   'name_rus' => 'Крафт: Алоэ-бальзам (сезон)',   'description' => 'Изготовление бальзама из сока алоэ от ожогов.'],
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
            'craftSummerColdKvass', 'craftSummerBerryMors', 'craftSummerFruitWater',
            'craftSummerMintTea', 'craftSummerAloeBalm',
        ])->delete();
        $this->db->table('crafted_items')->whereIn('name_eng', [
            'SummerColdKvass', 'SummerBerryMors', 'SummerFruitWater',
            'SummerMintTea', 'SummerAloeBalm',
        ])->delete();
    }
}
