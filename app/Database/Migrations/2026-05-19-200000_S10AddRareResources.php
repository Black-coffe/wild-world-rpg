<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S10 (v0.51.192) — 4 rare biome-locked resources для T3 крафта (Фаза 4, S16+).
 *
 * Reuse F7.2 `RareResourceGrantEffect` (event-driven loot, biome+keyword+rarity
 * фильтры). Не дублирует gather-side mechanism: ресурсы НЕ через `GatherTaskHandler`
 * (resource.biome_id используется ТОЛЬКО `IntentApplier::applyResourceGrant()`
 * для exact-match в pool resolution).
 *
 * Соответствие keyword'ам в S10-events (см. parallel migration
 * `2026-05-19-210000_S10AddRareDropEvents.php`):
 *   - SpentFuelRods            → biome_id=8 (Volcanic) ← VolcanicFuelCache event
 *   - PreCollapseElectronics   → biome_id=7 (Caves)    ← PreCollapseVaultOpening
 *   - IndustrialPlastic        → biome_id=5 (Tropical) ← IndustrialDumpFind
 *   - MedicalCompound          → biome_id=2 (Mountains)← MountainArmyDepot
 *
 * rarity=1 (semantically «rare»; в БД 1 = rare, 9-10 = common — обратная
 * семантика подтверждена audit'ом prod 2026-05-19). Конкретно у нас сейчас 7
 * rarity-1 resources на проде (Минералы, Янтарь, Нефть, Древние реликвии,
 * Сердце Дракона, Древняя Книга, Корона подземного короля).
 *
 * Idempotent: INSERT IGNORE по UNIQUE(name).
 */
class S10AddRareResources extends Migration
{
    /**
     * @var array<int, array{name:string,name_en:string,description:string,biome_id:string,price:int,level_required:int,keyword:string,type:string,icon_text:string}>
     */
    private const RESOURCES = [
        [
            'name'           => 'Отработанные ТВЭЛы',
            'name_en'        => 'SpentFuelRods',
            'description'    => 'Свинцово-обёрнутый комплект отработанных топливных элементов из вулканической пещеры. Опасны без защиты, но крайне ценны для T3 крафта.',
            'biome_id'       => '8',
            'price'          => 250,
            'level_required' => 18,
            'keyword'        => 'fuel_rods',
            'type'           => 'rare',
            'icon_text'      => '☢️',
        ],
        [
            'name'           => 'Доколлапсная электроника',
            'name_en'        => 'PreCollapseElectronics',
            'description'    => 'Целая печатная плата из довоенной эпохи, сохранённая в подземном хранилище. Кустарной замены не существует.',
            'biome_id'       => '7',
            'price'          => 300,
            'level_required' => 20,
            'keyword'        => 'pre_collapse_electronics',
            'type'           => 'rare',
            'icon_text'      => '💾',
        ],
        [
            'name'           => 'Промышленный пластик',
            'name_en'        => 'IndustrialPlastic',
            'description'    => 'Плотные листы и рулоны заводского пластика из старых тропических руин. Чистого пластика после Коллапса не делают.',
            'biome_id'       => '5',
            'price'          => 180,
            'level_required' => 15,
            'keyword'        => 'industrial_plastic',
            'type'           => 'rare',
            'icon_text'      => '🧴',
        ],
        [
            'name'           => 'Медицинское сырьё',
            'name_en'        => 'MedicalCompound',
            'description'    => 'Запечатанная ампула медицинского реагента из заброшенного армейского склада в горах. Основа высокотехнологичных аптечек.',
            'biome_id'       => '2',
            'price'          => 220,
            'level_required' => 18,
            'keyword'        => 'medical_compound',
            'type'           => 'rare',
            'icon_text'      => '💉',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::RESOURCES as $row) {
            $existing = $this->db->table('resources')
                ->where('name', $row['name'])
                ->get()
                ->getRowArray();

            if (! empty($existing)) {
                continue; // idempotent
            }

            $this->db->table('resources')->insert([
                'name'             => $row['name'],
                'name_en'          => $row['name_en'],
                'description'      => $row['description'],
                'biome_id'         => $row['biome_id'],
                'is_tradeable'     => 1,
                'type'             => $row['type'],
                'price'            => $row['price'],
                'buy_price'        => $row['price'] * 1.1,
                'sell_price'       => $row['price'] * 0.9,
                'rarity'           => 1,
                'level_required'   => $row['level_required'],
                'initial_quantity' => 0,
                'keyword'          => $row['keyword'],
                'icon_text'        => $row['icon_text'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::RESOURCES as $row) {
            $this->db->table('resources')->where('name', $row['name'])->delete();
        }
    }
}
