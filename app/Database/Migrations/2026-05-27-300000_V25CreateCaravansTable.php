<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V25 (ADR-057) — таблица `caravans` для странствующих NPC-торговцев.
 * Каждый караван стоит на одной клетке карты ограниченное время, продаёт
 * 1 редкий ресурс со скидкой к рыночной цене. Игрок на клетке видит караван
 * и может купить.
 */
class V25CreateCaravansTable extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('caravans')) {
            return;
        }

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'cell_number'    => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'resource_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'quantity'       => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'price_per_unit' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'spawned_at'     => ['type' => 'DATETIME', 'null' => false],
            'expires_at'     => ['type' => 'DATETIME', 'null' => false],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'active'],
            'created_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('cell_number');
        $this->forge->addKey(['status', 'expires_at']);
        $this->forge->createTable('caravans');
    }

    public function down(): void
    {
        if ($this->db->tableExists('caravans')) {
            $this->forge->dropTable('caravans');
        }
    }
}
