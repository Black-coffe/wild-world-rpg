<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V10 (ROADMAP-CRAFT-vNext, Фаза 2, закрытие, ADR-035) — консервация: 2 shelf-stable
 * заготовки. Counter к свежести (V10): обычные блюда V8 со временем «черствеют»
 * (урезается buff «Сытость», но НЕ теряются и всегда лечат); консервы НЕ портятся
 * (всегда дают полную сытость). Дороже по ингредиентам — плата за shelf-stability.
 *
 * type='drug', crafting_location='Campfire' (как V8). heal через UsePharmacyAction,
 * satiety через FoodBuffService. Рецепты помечены preserved (perishable=false) →
 * GenericCraftCompletionHandler не ставит durability_time. Зеркало V8AddCookingItems.
 * Idempotent.
 */
class V10AddPreserveItems extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $items = [
            ['name_rus' => 'Тушёнка',  'name_eng' => 'StewPreserve', 'boost' => '[{"Здоровье": 40,"Выносливость": 40}]', 'price' => 320, 'description' => 'Мясо-овощная тушёнка в закатанной банке — сытная заготовка долгого хранения. Не портится. Готовка/консервация на костре.'],
            ['name_rus' => 'Сухпаёк',  'name_eng' => 'DryRation',    'boost' => '[{"Здоровье": 25,"Выносливость": 35}]', 'price' => 260, 'description' => 'Сушёные фрукты и ягоды в дорогу — лёгкий паёк, который не испортится. Готовка/консервация на костре.'],
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
                'weight'             => 0.40,
                'price'              => $item['price'],
                'stack_size'         => 20,
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
            ['name' => 'craftStewPreserve', 'name_rus' => 'Консервация: Тушёнка', 'description' => 'Закатка мясо-овощной тушёнки в банки.'],
            ['name' => 'craftDryRation',    'name_rus' => 'Консервация: Сухпаёк', 'description' => 'Сушка фруктов и ягод в дорожный паёк.'],
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
                'max_duration'               => 35,
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
        $this->db->table('tasks')->whereIn('name', ['craftStewPreserve', 'craftDryRation'])->delete();
        $this->db->table('crafted_items')->whereIn('name_eng', ['StewPreserve', 'DryRation'])->delete();
    }
}
