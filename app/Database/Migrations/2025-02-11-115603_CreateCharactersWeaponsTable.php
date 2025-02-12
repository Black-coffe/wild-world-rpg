<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharactersWeaponsTable extends Migration
{
    public function up()
    {
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
            ],
            'weapon_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'quantity' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 1,
            ],
            'current_durability' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'equipped' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'slot' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            // Если хотите хранить «ammo_in_mag» (кол-во патронов в магазине), можете раскомментировать:
            // 'ammo_in_mag' => [
            //     'type'       => 'INT',
            //     'constraint' => 11,
            //     'unsigned'   => true,
            //     'default'    => 0,
            // ],

            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
            ],
        ]);

        $this->forge->addKey('id', true);  // PRIMARY KEY
        // Создаём индексы (не обязательно, но удобно)
        $this->forge->addKey('character_id');
        $this->forge->addKey('weapon_id');

        // (Опционально) Если хотите сразу прописать FK:
        // $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');
        // $this->forge->addForeignKey('weapon_id', 'weapons', 'id', 'CASCADE', 'CASCADE');

        // Наконец, создаём таблицу
        $this->forge->createTable('characters_weapons', true);
    }

    public function down()
    {
        // При откате миграции удаляем таблицу
        $this->forge->dropTable('characters_weapons', true);
    }
}
