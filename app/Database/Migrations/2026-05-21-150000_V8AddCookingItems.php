<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V8 (ROADMAP-CRAFT-vNext, Фаза 2) — Campfire cooking: 5 блюд из фарм-урожая.
 *
 * Farm-to-table payoff для V6/V7: урожай (Ягоды/Грибы/Фрукты/Зерновые) → готовка
 * на костре → сытные блюда, восстанавливающие здоровье + ВЫНОСЛИВОСТЬ (еда = энергия,
 * акцент на выносливость, в отличие от медицины). type='drug' (как сезонные блюда
 * V1-V3 — варенье/рагу/квас уже type='drug'), heal через UsePharmacyAction
 * (data-driven, параллельная heal-миграция). crafting_location='Campfire' →
 * группа «🔥 Костёр» в craft-tree. Низкий гейт (готовить можно где угодно).
 *
 * Зеркало V3AddAutumnSeasonalItems (без required_season — готовка круглогодична).
 * Idempotent.
 */
class V8AddCookingItems extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            ['name_rus' => 'Грибная похлёбка', 'name_eng' => 'MushroomSoup',   'boost' => '[{"Здоровье": 25,"Выносливость": 35}]', 'price' => 120, 'description' => 'Горячая похлёбка из лесных грибов, сваренная на костре. Согревает и возвращает силы. Готовка на костре.'],
            ['name_rus' => 'Ягодный взвар',    'name_eng' => 'BerryBrew',      'boost' => '[{"Здоровье": 20,"Выносливость": 40}]', 'price' => 110, 'description' => 'Горячий взвар из лесных ягод. Бодрит, утоляет жажду и возвращает выносливость. Готовка на костре.'],
            ['name_rus' => 'Печёные фрукты',   'name_eng' => 'BakedFruit',     'boost' => '[{"Здоровье": 30,"Выносливость": 30}]', 'price' => 130, 'description' => 'Фрукты, запечённые на углях до карамельной сладости. Простое сытное лакомство. Готовка на костре.'],
            ['name_rus' => 'Зерновая каша',    'name_eng' => 'GrainPorridge',  'boost' => '[{"Здоровье": 35,"Выносливость": 35}]', 'price' => 150, 'description' => 'Густая каша из зерновых, томлёная в котелке на костре. Надолго насыщает. Готовка на костре.'],
            ['name_rus' => 'Сытное рагу',      'name_eng' => 'HeartyStew',     'boost' => '[{"Здоровье": 45,"Выносливость": 55}]', 'price' => 220, 'description' => 'Большое рагу из всего урожая — грибы, овощи и фрукты в одном котле. Главное блюдо после тяжёлого дня. Готовка на костре.'],
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
                'weight'             => 0.30,
                'price'              => $item['price'],
                'stack_size'         => 10,
                'required_level'     => 1,
                'required_skills'    => null,
                'required_resources' => null,
                'crafting_time'      => null,
                'crafting_location'  => 'Campfire',
                'description'        => $item['description'],
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        $tasks = [
            ['name' => 'craftMushroomSoup',  'name_rus' => 'Готовка: Грибная похлёбка', 'description' => 'Варка грибной похлёбки на костре.'],
            ['name' => 'craftBerryBrew',     'name_rus' => 'Готовка: Ягодный взвар',    'description' => 'Заваривание ягодного взвара на костре.'],
            ['name' => 'craftBakedFruit',    'name_rus' => 'Готовка: Печёные фрукты',   'description' => 'Запекание фруктов на углях.'],
            ['name' => 'craftGrainPorridge', 'name_rus' => 'Готовка: Зерновая каша',    'description' => 'Томление зерновой каши в котелке.'],
            ['name' => 'craftHeartyStew',    'name_rus' => 'Готовка: Сытное рагу',      'description' => 'Приготовление большого рагу из всего урожая.'],
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
            'craftMushroomSoup', 'craftBerryBrew', 'craftBakedFruit',
            'craftGrainPorridge', 'craftHeartyStew',
        ])->delete();
        $this->db->table('crafted_items')->whereIn('name_eng', [
            'MushroomSoup', 'BerryBrew', 'BakedFruit', 'GrainPorridge', 'HeartyStew',
        ])->delete();
    }
}
