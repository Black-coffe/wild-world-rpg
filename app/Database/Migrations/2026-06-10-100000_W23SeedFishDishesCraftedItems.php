<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W23 (ADR-078) — Fishing: 3 рыбных блюда как crafted_items (type=drug, костёр).
 *
 * Audit-first: ресурс «Рыба» (id 18) + ловля (gather в Реках) УЖЕ существуют;
 * реальный gap — у рыбы не было кулинарного применения. Эти 3 блюда дают цель.
 *
 * Зеркало существующих cooking-блюд (MushroomSoup/StewPreserve): type='drug',
 * crafting_location='Campfire', required_resources=NULL (ингредиенты — в
 * Config\CraftRecipes). character_boost = display heal (реальный heal — GameSettings
 * medical.<snake>.heal_*). Рецепты гейтятся killswitch cooking.fish_dishes.enabled.
 */
class W23SeedFishDishesCraftedItems extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [
            [
                'name_rus'          => 'Уха',
                'name_eng'          => 'FishSoup',
                'type'              => 'drug',
                'direction_craft'   => 'treatment',
                'effect'            => 'boost',
                'durability_count'  => 1,
                'damage'            => 0,
                'armor'             => 0,
                'hp'                => 0,
                'character_boost'   => '[{"Здоровье": 35,"Выносливость": 45}]',
                'weight'            => 0.50,
                'price'             => 140.00,
                'stack_size'        => 20,
                'required_level'    => 1,
                'crafting_location' => 'Campfire',
                'description'       => 'Наваристая уха из свежей рыбы, сваренная на костре. Восстанавливает здоровье и особенно выносливость. Готовить можно где угодно.',
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [
                'name_rus'          => 'Жареная рыба',
                'name_eng'          => 'GrilledFish',
                'type'              => 'drug',
                'direction_craft'   => 'treatment',
                'effect'            => 'boost',
                'durability_count'  => 1,
                'damage'            => 0,
                'armor'             => 0,
                'hp'                => 0,
                'character_boost'   => '[{"Здоровье": 40,"Выносливость": 30}]',
                'weight'            => 0.40,
                'price'             => 120.00,
                'stack_size'        => 20,
                'required_level'    => 1,
                'crafting_location' => 'Campfire',
                'description'       => 'Рыба, зажаренная на углях. Сытный белковый перекус — быстро восстанавливает здоровье.',
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [
                'name_rus'          => 'Рыбные консервы',
                'name_eng'          => 'FishPreserve',
                'type'              => 'drug',
                'direction_craft'   => 'treatment',
                'effect'            => 'boost',
                'durability_count'  => 1,
                'damage'            => 0,
                'armor'             => 0,
                'hp'                => 0,
                'character_boost'   => '[{"Здоровье": 45,"Выносливость": 45}]',
                'weight'            => 0.45,
                'price'             => 340.00,
                'stack_size'        => 20,
                'required_level'    => 1,
                'crafting_location' => 'Campfire',
                'description'       => 'Рыба, закатанная в банку — заготовка долгого хранения. Не портится, выручит в долгом походе. Даёт самую долгую сытость среди рыбных блюд.',
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
        ];

        // Идемпотентность: вставляем только отсутствующие name_eng.
        foreach ($rows as $row) {
            $exists = $this->db->table('crafted_items')->where('name_eng', $row['name_eng'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('crafted_items')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('crafted_items')
            ->whereIn('name_eng', ['FishSoup', 'GrilledFish', 'FishPreserve'])
            ->delete();
    }
}
