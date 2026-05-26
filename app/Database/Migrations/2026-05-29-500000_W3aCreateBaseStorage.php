<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W3a (ADR-059) — таблица `base_storage`: ресурсы, доставленные cargo-дроном
 * на базу персонажа (или иными future-механиками). Чистая сепарация carried
 * (`character_resources`) vs base (`base_storage`) — резолюция Q2 ADR-059.
 *
 * Игрок забирает из storage в карман через retrieve UI (резолюция Q5:
 * selective per-resource + «Забрать всё» комбо-кнопка).
 *
 * Cargo-log для arrival-history (откуда привезено, когда) — `arrived_from_cell`
 * + `created_at`. Aдитивная, idempotent.
 */
class W3aCreateBaseStorage extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('base_storage')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'character_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'resource_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'quantity' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
                'default'    => 0,
            ],
            'arrived_from_cell' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'default'    => null,
                'comment'    => 'cell_number откуда дрон забрал партию (arrival-history)',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('character_id');
        $this->forge->addKey(['character_id', 'resource_id']);
        $this->forge->createTable('base_storage');
    }

    public function down(): void
    {
        if ($this->db->tableExists('base_storage')) {
            $this->forge->dropTable('base_storage');
        }
    }
}
