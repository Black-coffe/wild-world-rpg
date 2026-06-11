<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-116 (E16) — 2 эксклюзивных ресурса пояса Y300-600 + killswitch.
 *
 * Добываются ТОЛЬКО в средней полосе (min_y=300/max_y=600) — мехнический повод идти на север
 * («вторая родина» подросшего игрока). Заготовка под грандмастер-РЕЦЕПТЫ E14 (Ф2). resources=KEEP.
 * Killswitch `world.belt_resources.enabled` (OFF→dormant: GatherCellResourceQuery скрывает
 * широтно-гейтнутые ресурсы). Idempotent (по name).
 */
class Adr116SeedBeltResources extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $res = [
            // name, name_en, biome_id, level, rarity, price, weight, icon, desc
            ['Пепел Предтеч', 'ForerunnerAsh', '1,2,3,4,5,6', 25, 1, 80, 0.30, '🌫',
                'Серый прах сгоревших машин Предтеч. Оседает лишь в средней полосе — выше его сдувает северный ветер, ниже смывают дожди. Ценное сырьё для продвинутого крафта.'],
            ['Кристалл Разлома', 'RiftCrystal', '2,3,7,8', 30, 1, 120, 0.40, '🔷',
                'Энергокристалл, наросший в аномалиях средней полосы. Холоден на ощупь, но внутри тлеет древняя энергия. Незаменим для грандмастерских модификаций.'],
        ];

        foreach ($res as $r) {
            if ($this->db->table('resources')->where('name', $r[0])->countAllResults() > 0) {
                continue;
            }
            $this->db->table('resources')->insert([
                'name'             => $r[0],
                'name_en'          => $r[1],
                'description'      => $r[8],
                'biome_id'         => $r[2],
                'is_tradeable'     => 1,
                'type'             => 'crafting',
                'price'            => $r[5],
                'buy_price'        => $r[5] * 1.05,
                'sell_price'       => $r[5] * 0.95,
                'rarity'           => $r[4],
                'weight'           => $r[6],
                'level_required'   => $r[3],
                'min_y'            => 300,
                'max_y'            => 600,
                'initial_quantity' => 5000,
                'icon_text'        => $r[7],
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        if ($this->db->table('game_settings')->where('setting_key', 'world.belt_resources.enabled')->countAllResults() === 0) {
            $this->db->table('game_settings')->insert([
                'setting_key'        => 'world.belt_resources.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch эксклюзивных ресурсов пояса Y300-600 (E16, ADR-116). ON: ресурсы с широтными границами (Пепел Предтеч, Кристалл Разлома) добываются в средней полосе → повод идти на север + сырьё для грандмастер-рецептов E14 (Ф2). OFF: GatherCellResourceQuery скрывает широтно-гейтнутые ресурсы (dormant).',
                'effect_text'        => 'ON: ресурсы с min_y/max_y возвращаются в добыче при coordinate_y в диапазоне. OFF: исключаются (min_y IS NULL фильтр).',
                'above_effect_text'  => 'true: средняя полоса получает эксклюзивную добычу — миграционный стимул. Включать после Tier-3 и эконом-проверки.',
                'below_effect_text'  => 'false: широтно-эксклюзивных ресурсов нет (только обычная биом-добыча).',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('resources')->whereIn('name', ['Пепел Предтеч', 'Кристалл Разлома'])->delete();
        $this->db->table('game_settings')->where('setting_key', 'world.belt_resources.enabled')->delete();
    }
}
