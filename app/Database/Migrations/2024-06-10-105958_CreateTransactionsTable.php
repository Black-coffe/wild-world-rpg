<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateTransactionsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true
            ],
            'character_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false
            ],
            'crafted_item_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false
            ],
            'type' => [
                'type' => 'ENUM',
                'constraint' => ['buy', 'sell'],
                'null' => false
            ],
            'quantity' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => false
            ],
            'price' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => false
            ],
            'transaction_date' => [
                'type' => 'DATETIME',
                'default' => new RawSql('CURRENT_TIMESTAMP'),
                'null' => false
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('crafted_item_id', 'crafted_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('transactions');
    }

    public function down()
    {
        $this->forge->dropTable('transactions');
    }
}
