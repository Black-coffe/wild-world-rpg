<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V6 (ROADMAP-CRAFT-vNext, ADR-033) — 4 seed-ресурса для активной посадки.
 *
 * Семена хранятся в `character_resources` (consume-on-plant + seed-drop).
 * 1:1 соответствие урожаю: BerrySeeds→Berries, MushroomSeeds→Mushrooms,
 * FruitSeeds→Fruit, CropSeeds→Crops (см. FarmingService::CROP_MAP).
 *
 * Семена функциональны (не рыночный товар) → is_tradeable=0. type='seed'
 * (новый, не пересекается с gather-логикой — семена не лежат на клетках карты).
 *
 * Idempotent: insert только если name_en отсутствует.
 */
class V6AddSeedResources extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'name'        => 'Семена ягод',
                'name_en'     => 'BerrySeeds',
                'description' => 'Отложенный посевной материал ягодных кустов. Посади в теплице — через время соберёшь полную корзину ягод.',
                'icon_text'   => '🫐',
            ],
            [
                'name'        => 'Семена грибов',
                'name_en'     => 'MushroomSeeds',
                'description' => 'Грибница, собранная с прошлого урожая. Посади в теплице — даст урожай грибов.',
                'icon_text'   => '🍄',
            ],
            [
                'name'        => 'Семена фруктов',
                'name_en'     => 'FruitSeeds',
                'description' => 'Косточки и семечки спелых плодов. Посади в теплице — вырастишь фрукты.',
                'icon_text'   => '🍎',
            ],
            [
                'name'        => 'Семена овощей',
                'name_en'     => 'CropSeeds',
                'description' => 'Зерно и семена зерновых культур, отложенные на посев. Посади в теплице — соберёшь урожай овощей и зерна.',
                'icon_text'   => '🌾',
            ],
        ];

        $shared = [
            'biome_id'         => null,
            'is_tradeable'     => 0,
            'type'             => 'seed',
            'price'            => 0,
            'buy_price'        => null,
            'sell_price'       => null,
            'rarity'           => 2,
            'level_required'   => 1,
            'initial_quantity' => 0,
            'keyword'          => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('resources')->where('name_en', $row['name_en'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('resources')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('resources')->whereIn('name_en', [
            'BerrySeeds',
            'MushroomSeeds',
            'FruitSeeds',
            'CropSeeds',
        ])->delete();
    }
}
