<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-107 — уникальный лут боссов глубокого севера (наполнение loot_table_items).
 *
 * Лут-таблицы 701/702/703 (новые id, существующие 501/502/601 рейдеров остаются
 * пустыми = no-op) + привязка npcs.loot_table_id боссам ADR-106. Тематика:
 *  • 701 Шрам (мясник-изгой): Древние реликвии + Мед. сырьё + золото + 12% Коса Жатвы (L16 Legendary);
 *  • 702 Цербер (машина): Доколлапсная электроника + Промпластик + ТВЭЛы + золото + 12% Ионный дестабилизатор (L20 Legendary);
 *  • 703 Патриарх (волк-мутант): Сердце Дракона + Мед. сырьё + 15% Корона подземного короля + золото
 *    + 10% Экзо-railgun «Бегемот» (L24 Legendary — снаряжение погибших охотников из логова).
 *
 * Все ресурсы — rarity 1 (редчайшие). item_id фиксированы (контент сеялся
 * миграциями, id стабильны между средами; верифицируется Tier-3). Идемпотентно
 * (по наличию строк лут-таблицы). WipeManifest: loot_table_items/npcs = KEEP.
 */
class SeedBossLootTables extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [loot_table_id, item_type, item_id, min, max, chance%, sort]
        $items = [
            // 701 — Шрам, Мясник Пустоши
            [701, 'resource', 61, 3, 6, 100, 1],   // Древние реликвии
            [701, 'resource', 77, 2, 5, 100, 2],   // Медицинское сырьё
            [701, 'gold',     null, 5000, 10000, 100, 3],
            [701, 'weapon',   24, 1, 1, 12, 4],    // Коса Жатвы (L16 Legendary)
            // 702 — Прототип «Цербер»
            [702, 'resource', 75, 3, 6, 100, 1],   // Доколлапсная электроника
            [702, 'resource', 76, 3, 8, 100, 2],   // Промышленный пластик
            [702, 'resource', 74, 1, 3, 100, 3],   // Отработанные ТВЭЛы
            [702, 'gold',     null, 6000, 12000, 100, 4],
            [702, 'weapon',   17, 1, 1, 12, 5],    // Ионный дестабилизатор (L20 Legendary)
            // 703 — Патриарх Стаи
            [703, 'resource', 69, 1, 2, 100, 1],   // Сердце Дракона
            [703, 'resource', 77, 3, 6, 100, 2],   // Медицинское сырьё
            [703, 'resource', 71, 1, 1, 15, 3],    // Корона подземного короля
            [703, 'gold',     null, 8000, 15000, 100, 4],
            [703, 'weapon',   19, 1, 1, 10, 5],    // Экзо-railgun «Бегемот» (L24 Legendary)
        ];

        $tables = array_unique(array_column($items, 0));
        foreach ($tables as $tableId) {
            $exists = $this->db->table('loot_table_items')->where('loot_table_id', $tableId)->countAllResults();
            if ($exists > 0) {
                continue;
            }
            foreach ($items as $i) {
                if ($i[0] !== $tableId) {
                    continue;
                }
                $this->db->table('loot_table_items')->insert([
                    'loot_table_id' => $i[0],
                    'item_type'     => $i[1],
                    'item_id'       => $i[2],
                    'min_qty'       => $i[3],
                    'max_qty'       => $i[4],
                    'drop_chance'   => $i[5],
                    'sort_order'    => $i[6],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }

        // Привязка лут-таблиц боссам ADR-106 (идемпотентный UPDATE по npc_name_en).
        $map = [
            'boss_scar_butcher'       => 701,
            'boss_cerberus_prototype' => 702,
            'boss_pack_patriarch'     => 703,
        ];
        foreach ($map as $en => $tableId) {
            $this->db->table('npcs')->where('npc_name_en', $en)->update(['loot_table_id' => $tableId]);
        }
    }

    public function down(): void
    {
        $this->db->table('loot_table_items')->whereIn('loot_table_id', [701, 702, 703])->delete();
        $this->db->table('npcs')
            ->whereIn('npc_name_en', ['boss_scar_butcher', 'boss_cerberus_prototype', 'boss_pack_patriarch'])
            ->update(['loot_table_id' => null]);
    }
}
