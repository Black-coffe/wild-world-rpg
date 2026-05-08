<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.109 — Strategic objects content phase.
 *
 * Додає 3 strategic-class world_objects з canon (wildworld.fun
 * "Концепція и развитие игры" 2025-01-29):
 *   - Bunker (Бункер) — military fortified loot, level 15+, biomes 6/8/9
 *   - Technopark (Технопарк) — high-tech facility, level 20+, biomes 1/7
 *   - GhostCity (Город-призрак) — abandoned settlement, level 12+, biomes 2/6/9
 *
 * Pattern: same schema as truck/warehouse/toolkit (status='inactive' за default —
 * admin активує у /admin/world-objects коли проспавнить локації).
 *
 * Loot balanced на rich-side щоб виправдати "strategic" descriptor.
 */
class AddStrategicObjects extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('world_objects')->insertBatch([
            [
                'name'             => 'Бункер',
                'name_en'          => 'Bunker',
                'description'      => 'Заброшенный военный бункер. Толстые бетонные стены, ржавые двери. Внутри ещё могут оставаться оружие, медикаменты и редкие материалы — если ты сможешь пробиться внутрь.',
                'biome_id'         => json_encode(['6', '8', '9']),
                'max_count'        => 50,
                'discovery_tools'  => json_encode([['IronPickaxe' => '1', 'DiamondPickaxe' => '1']]),
                'contents'         => json_encode([[
                    'resources' => [
                        'Sulfur'      => '40',
                        'RareMetals'  => '50',
                        'Oil'         => '60',
                        'Ironstone'   => '120',
                        'Stones'      => '80',
                    ],
                    'crafted_items' => [
                        'PipeGun'        => '2',
                        'MetalSpear'     => '3',
                        'BasicMedKit'    => '5',
                        'Bandage'        => '8',
                        'Antiseptic'     => '4',
                    ],
                ]]),
                'respawn_time'     => 24,
                'status'           => 'inactive',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [
                'name'             => 'Технопарк',
                'name_en'          => 'Technopark',
                'description'      => 'Развалины высокотехнологичной исследовательской лаборатории. Поломанные дроны, разбитая электроника, останки робототехники. Для входа нужны серьёзные инструменты.',
                'biome_id'         => json_encode(['1', '7']),
                'max_count'        => 30,
                'discovery_tools'  => json_encode([['DiamondPickaxe' => '1', 'IronPickaxe' => '1', 'Hoe' => '1']]),
                'contents'         => json_encode([[
                    'resources' => [
                        'RareMetals'  => '80',
                        'Wiring'      => '40',
                        'Crystals'    => '12',
                        'Minerals'    => '30',
                        'RareOre'     => '25',
                    ],
                    'crafted_items' => [
                        'ElectronicComponents' => '15',
                        'GlassBags'            => '8',
                        'Wiring'               => '20',
                        'WorkbenchOne'         => '1',
                        'TireIron'             => '3',
                    ],
                ]]),
                'respawn_time'     => 36,
                'status'           => 'inactive',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [
                'name'             => 'Город-призрак',
                'name_en'          => 'GhostCity',
                'description'      => 'Заброшенный город — следы прежней цивилизации. Пустые улицы, разрушенные здания, иногда — забытые припасы и материалы. Базовых инструментов хватит чтобы покопаться в развалинах.',
                'biome_id'         => json_encode(['2', '6', '9']),
                'max_count'        => 80,
                'discovery_tools'  => json_encode([['IronPickaxe' => '1']]),
                'contents'         => json_encode([[
                    'resources' => [
                        'Wood'        => '150',
                        'Pebble'      => '180',
                        'Stones'      => '120',
                        'Sand'        => '100',
                        'Crops'       => '60',
                        'Clay'        => '50',
                        'Ironstone'   => '40',
                    ],
                    'crafted_items' => [
                        'LumberjackAxe'  => '2',
                        'IronShovel'     => '2',
                        'FoldingKnife'   => '6',
                        'Hoe'            => '2',
                        'Bandage'        => '4',
                    ],
                ]]),
                'respawn_time'     => 18,
                'status'           => 'inactive',
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
        ]);
    }

    public function down(): void
    {
        $this->db->table('world_objects')
            ->whereIn('name_en', ['Bunker', 'Technopark', 'GhostCity'])
            ->delete();
    }
}
