<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharactersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 5,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'telegram_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'null'       => true,
            ],
            'level' => [
                'type'       => 'INT',
                'constraint' => 7,
                'default'    => 1,
            ],
            'experience' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0.01,
            ],
            'health' => [
                'type'       => 'DECIMAL',
                'constraint' => 7,2,
                'default'    => 100,
            ],
            'tired' => [
                'type'       => 'DECIMAL',
                'constraint' => 7,2,
                'default'    => 100,
            ],
            'strength' => [
                'type'       => 'DECIMAL',
                'constraint' => '7,2',
                'default'    => 0.01,
            ],
            'agility' => [
                'type'       => 'DECIMAL',
                'constraint' => '7,2',
                'default'    => 0.01,
            ],
            'intellect' => [
                'type'       => 'DECIMAL',
                'constraint' => '7,2',
                'default'    => 0.01,
            ],
            'gold' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'null'       => true,
                'default'    => null, // Или можно установить значение по умолчанию, если нужно, например, 'default' => 0,
            ],
            'cell_number' => [
                'type'       => 'INT',
                'constraint' => 5,
                'null'       => true,
            ],
            'trading_karma' => [
                'type' => 'FLOAT',
                'default' => 100.0,
                'null' => false,
            ],
            'insurance' => [
                'type' => 'BOOLEAN',
                'default' => false,
                'null' => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('telegram_user_id', 'telegram_users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('characters');
    }

    public function down()
    {
        $this->forge->dropTable('characters');
    }
}
