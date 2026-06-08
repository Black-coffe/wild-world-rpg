<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 3 (рефайн) — ассортимент фракционных лавок оплотов («unique-stock»).
 *
 * Курируемый фикс-список СУЩЕСТВУЮЩИХ предметов (оружие/броня/крафт), которые продаёт лавка
 * конкретного поселения. item_type ∈ weapon|outfit|crafted; item_id → weapons.id|outfits.id|
 * crafted_items.id; base_price — базовая цена в золоте (faction-скидка/наценка применяется поверх
 * в {@see App\Services\Settlement\SettlementShopService}). Покупка = реюз grant-путей
 * GenericCraftCompletionHandler (characters_weapons / characters_outfits / crafted_items_log).
 *
 * WipeManifest: settlement_shop_items = KEEP (контент-определение, как settlement_npcs). Классиф.
 */
class CreateSettlementShopItemsTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('settlement_shop_items')) {
            return;
        }
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'settlement_id' => ['type' => 'INT', 'unsigned' => true, 'comment' => 'FK settlements.id'],
            'item_type'     => ['type' => 'VARCHAR', 'constraint' => 16, 'comment' => 'weapon|outfit|crafted'],
            'item_id'       => ['type' => 'INT', 'unsigned' => true, 'comment' => 'FK weapons.id|outfits.id|crafted_items.id'],
            'base_price'    => ['type' => 'INT', 'unsigned' => true, 'comment' => 'базовая цена в золоте (до faction-модификатора)'],
            'sort_order'    => ['type' => 'INT', 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['settlement_id', 'item_type', 'item_id']);
        $this->forge->addKey('settlement_id');
        $this->forge->createTable('settlement_shop_items');
    }

    public function down(): void
    {
        $this->forge->dropTable('settlement_shop_items', true);
    }
}
