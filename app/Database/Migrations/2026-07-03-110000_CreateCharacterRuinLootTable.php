<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 4 — кулдаун повторяемого лута охраняемых руин (per character × ruin).
 *
 * WipeManifest: PLAYER_DATA (прогресс игрока, link=character_id). Полный вайп → DELETE всех;
 * сброс одного чара → DELETE WHERE character_id.
 */
class CreateCharacterRuinLootTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'character_id'   => ['type' => 'INT', 'unsigned' => true],
            'settlement_id'  => ['type' => 'INT', 'unsigned' => true],
            'last_looted_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['character_id', 'settlement_id']);
        $this->forge->addKey('character_id');
        $this->forge->createTable('character_ruin_loot', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('character_ruin_loot', true);
    }
}
