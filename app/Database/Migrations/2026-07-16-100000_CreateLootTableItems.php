<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-107 — data-driven лут-таблицы (оживление мёртвой колонки npcs.loot_table_id).
 *
 * До сих пор npcs.loot_table_id (501/502/601 у рейдеров, NULL у прочих) не
 * ссылалась НИ НА ЧТО — лут-движка в игре не было, награды PvE = generic
 * level-based RewardService. Таблица loot_table_items задаёт содержимое
 * лут-таблиц: NPC с loot_table_id и строками здесь дропает доп. трофеи при
 * победе игрока (движок LootTableService, killswitch combat.loot_tables.enabled).
 *
 * item_type: 'resource' (item_id → resources.id) / 'gold' (item_id NULL) /
 * 'weapon' (item_id → weapons.id). drop_chance — процент 0-100.
 *
 * WipeManifest: loot_table_items = KEEP (контент-определения, как crafted_items/
 * weapons — нет player-связи).
 */
class CreateLootTableItems extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'loot_table_id' => ['type' => 'INT', 'unsigned' => true],
            'item_type'     => ['type' => 'VARCHAR', 'constraint' => 20], // resource|gold|weapon
            'item_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'min_qty'       => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'max_qty'       => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'drop_chance'   => ['type' => 'INT', 'unsigned' => true, 'default' => 100], // %
            'sort_order'    => ['type' => 'INT', 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('loot_table_id');
        $this->forge->createTable('loot_table_items', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('loot_table_items', true);
    }
}
