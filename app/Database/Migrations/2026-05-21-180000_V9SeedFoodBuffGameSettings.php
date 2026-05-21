<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V9 (vNext, Фаза 2, ADR-034) — food-buff GameSettings (admin-tunable, ADR-024).
 *
 * «Сытость» от приготовленной еды (V8): pure-bonus PvE-слой.
 *  - food.buffs.enabled — killswitch.
 *  - food.well_fed.craft_time_multiplier (<1.0) — крафт быстрее, пока сыт.
 *  - food.well_fed.gather_yield_multiplier (>1.0) — добыча щедрее, пока сыт.
 *  - food.<meal>.well_fed_minutes — длительность сытости от каждого блюда (лучше
 *    блюдо = дольше сыт). Eating ставит characters.well_fed_until = now + minutes.
 *
 * category=craft (домен готовки/производства). Idempotent.
 */
class V9SeedFoodBuffGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'food.buffs.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch buff\'а «Сытость» от еды. true — съеденное блюдо даёт временный бонус к продуктивности (крафт/добыча). false — еда только лечит (как до V9), buff не выдаётся.',
                'effect_text'        => 'FoodBuffService::isEnabled. false → UsePharmacyAction не ставит well_fed_until, хуки крафта/добычи игнорируют buff.',
                'above_effect_text'  => 'true: ось «еда→продуктивность» активна (farm→cook→work loop).',
                'below_effect_text'  => 'false: мгновенный откат food-buff слоя без редеплоя (heal-эффект еды не тронут).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'food.well_fed.craft_time_multiplier',
                'value_type'         => 'float',
                'value_float'        => 0.90,
                'default_value_text' => '0.90',
                'rationale_text'     => 'Множитель времени крафта, пока персонаж сыт (−10%). Сытый работник продуктивнее. Стакается с Workshop/Lab множителями (мультипликативно).',
                'effect_text'        => 'GenericCraftActionStart::calculateCraftingDuration × этот множитель, если now < well_fed_until. Детерминир., min 1 минута.',
                'above_effect_text'  => 'При 0.99 — ускорение незаметно (−1%), готовить ради buff\'а не стоит.',
                'below_effect_text'  => 'При 0.60 — еда даёт −40% к крафту, обесценивает прокачку Workshop/Lab.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.97',
                'hard_min'           => '0.30',
                'hard_max'           => '1.00',
            ],
            [
                'setting_key'        => 'food.well_fed.gather_yield_multiplier',
                'value_type'         => 'float',
                'value_float'        => 1.15,
                'default_value_text' => '1.15',
                'rationale_text'     => 'Множитель добычи ресурсов, пока персонаж сыт (+15%). Поел — добыл больше. Стимулирует готовить перед сбором.',
                'effect_text'        => 'GatherTaskHandler множит итоговую добычу на этот множитель, если now < well_fed_until. Детерминир.',
                'above_effect_text'  => 'При 1.50 (+50%) — еда слишком сильно бустит добычу, инфляция ресурсов.',
                'below_effect_text'  => 'При 1.02 (+2%) — прибавка незаметна, готовить ради добычи не стоит.',
                'recommended_min'    => '1.05',
                'recommended_max'    => '1.35',
                'hard_min'           => '1.00',
                'hard_max'           => '2.00',
            ],
        ];

        // Длительность сытости по блюдам (лучше блюдо = дольше сыт).
        $mealMinutes = [
            'mushroom_soup'  => 30,
            'berry_brew'     => 25,
            'baked_fruit'    => 25,
            'grain_porridge' => 40,
            'hearty_stew'    => 60,
        ];
        foreach ($mealMinutes as $snake => $minutes) {
            $rows[] = [
                'setting_key'        => "food.{$snake}.well_fed_minutes",
                'value_type'         => 'int',
                'value_int'          => $minutes,
                'default_value_text' => (string) $minutes,
                'rationale_text'     => "Сколько минут держится «Сытость» после блюда «{$snake}» ({$minutes} мин). Сытнее блюдо — дольше эффект; рагу (combo) — дольше всех.",
                'effect_text'        => "UsePharmacyAction ставит characters.well_fed_until = now + {$minutes} мин при употреблении этого блюда.",
                'above_effect_text'  => 'Выше — одно блюдо держит сытость слишком долго, постоянный buff почти даром.',
                'below_effect_text'  => 'Ниже/0 — блюдо почти не даёт сытости (или не даёт вовсе), buff не ощущается.',
                'recommended_min'    => '10',
                'recommended_max'    => '180',
                'hard_min'           => '0',
                'hard_max'           => '1440',
            ];
        }

        $defaults = [
            'category'        => 'craft',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $keys = [
            'food.buffs.enabled',
            'food.well_fed.craft_time_multiplier',
            'food.well_fed.gather_yield_multiplier',
        ];
        foreach (['mushroom_soup', 'berry_brew', 'baked_fruit', 'grain_porridge', 'hearty_stew'] as $snake) {
            $keys[] = "food.{$snake}.well_fed_minutes";
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
