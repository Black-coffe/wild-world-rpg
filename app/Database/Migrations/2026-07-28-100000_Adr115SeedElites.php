<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-115 (E15) — элитные NPC (L30/40/50) + их лут-таблицы.
 *
 * Элиты — осмысленный PvE-вызов между стандартными рейдерами (L15-25) и боссами (L30-35),
 * спавнятся в средней полосе Y300-600 (EliteSpawnHandler). Трофеи = «Древние реликвии» (id 61) —
 * компонент грандмастер-модернизации E14 (ADR-114): сквозная нить охота→реликвии→крафт.
 *
 * npcs = KEEP, loot_table_items = KEEP (контент-определения) → WipeManifest не затрагивается.
 * Лут выдаётся автоматически (PvEService::attack → LootTableService при loot_table_id, killswitch
 * combat.loot_tables.enabled=ON на проде). Idempotent (по npc_name_en / loot_table_id).
 */
class Adr115SeedElites extends Migration
{
    /** Лут-таблицы элиток (вне диапазона боссов 701-703 и рейдеров 501-601). */
    private const LOOT = [
        801 => [ // L30 Бронированный мародёр
            ['resource', 61, 1, 2, 50],   // Древние реликвии (E14-компонент)
            ['gold', null, 2000, 4000, 100],
        ],
        802 => [ // L40 Налётчик Пустоты
            ['resource', 61, 2, 3, 65],
            ['gold', null, 4000, 7000, 100],
        ],
        803 => [ // L50 Стальной жнец
            ['resource', 61, 3, 5, 80],
            ['gold', null, 6000, 10000, 100],
        ],
    ];

    public function up(): void
    {
        $now    = date('Y-m-d H:i:s');
        $elites = [
            // name_en, name_ru, level, hp, str, agi, int, dmg, atkspd, armor, aggr, loot
            ['elite_armored_marauder', 'Бронированный мародёр', 30, 320, 18, 8, 6, 16, 0.8, 9, 6, 801,
                'Закованный в листы брони ветеран пустоши. Толстая шкура держит удар — пробить её под силу лишь опытному бойцу.'],
            ['elite_void_raider', 'Налётчик Пустоты', 40, 420, 22, 12, 8, 20, 1.0, 7, 7, 802,
                'Быстрый и беспощадный охотник средней полосы. Бьёт первым и насмерть; промедление стоит жизни.'],
            ['elite_steel_reaper', 'Стальной жнец', 50, 560, 26, 14, 10, 24, 1.0, 11, 8, 803,
                'Легенда глубокой средней полосы. Соединяет броню мародёра и ярость налётчика — добыча достойна риска.'],
        ];

        foreach ($elites as $e) {
            if ($this->db->table('npcs')->where('npc_name_en', $e[0])->countAllResults() > 0) {
                continue;
            }
            $this->db->table('npcs')->insert([
                'npc_name_en'      => $e[0],
                'npc_name_ru'      => $e[1],
                'npc_type'         => 'elite',
                'description'      => $e[12],
                'level'            => $e[2],
                'health'           => $e[3],
                'strength'         => $e[4],
                'agility'          => $e[5],
                'intellect'        => $e[6],
                'damage_type'      => 'Physical',
                'damage_value'     => $e[7],
                'attack_speed'     => $e[8],
                'armor'            => $e[9],
                'aggression_range' => $e[10],
                'is_boss'          => 0,
                'faction_id'       => null,
                'loot_table_id'    => $e[11],
                'ai_behavior'      => 'aggressive',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        foreach (self::LOOT as $tableId => $items) {
            if ($this->db->table('loot_table_items')->where('loot_table_id', $tableId)->countAllResults() > 0) {
                continue;
            }
            $sort = 0;
            foreach ($items as $it) {
                $this->db->table('loot_table_items')->insert([
                    'loot_table_id' => $tableId,
                    'item_type'     => $it[0],
                    'item_id'       => $it[1],
                    'min_qty'       => $it[2],
                    'max_qty'       => $it[3],
                    'drop_chance'   => $it[4],
                    'sort_order'    => $sort++,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('npcs')->whereIn('npc_name_en', [
            'elite_armored_marauder', 'elite_void_raider', 'elite_steel_reaper',
        ])->delete();
        $this->db->table('loot_table_items')->whereIn('loot_table_id', [801, 802, 803])->delete();
    }
}
