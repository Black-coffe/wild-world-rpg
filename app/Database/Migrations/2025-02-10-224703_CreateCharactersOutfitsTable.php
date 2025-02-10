<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharactersOutfitsTable extends Migration
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
                'null'       => false,
            ],
            'outfit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'quantity' => [
                'type'    => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'default' => 1,
            ],
            'current_durability' => [
                'type'    => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'default' => 0,
            ],
            'equipped' => [
                'type'    => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'slot' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
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

        // Первичный ключ
        $this->forge->addKey('id', true);

        // Индексы (необязательно, но полезно)
        $this->forge->addKey('character_id');
        $this->forge->addKey('outfit_id');

        // Создаём таблицу
        $this->forge->createTable('characters_outfits', true);

        // Добавляем внешние ключи (если ваша БД/версия MySQL поддерживает)
        // В CodeIgniter 4 для addForeignKey() обычно нужно вызвать отдельный $this->forge->addField(...) не требуется —
        // но можно делать через modifyColumn или raw SQL ниже, либо объявить сразу в up().
        // Пример (не все версии CI4 поддерживают $this->forge->addForeignKey):
        $db = \Config\Database::connect();

        $db->query("ALTER TABLE `characters_outfits`
            ADD CONSTRAINT `fk_characters_outfits_character_id`
            FOREIGN KEY (`character_id`) REFERENCES `characters`(`id`)
            ON DELETE CASCADE
            ON UPDATE CASCADE");

        $db->query("ALTER TABLE `characters_outfits`
            ADD CONSTRAINT `fk_characters_outfits_outfit_id`
            FOREIGN KEY (`outfit_id`) REFERENCES `outfits`(`id`)
            ON DELETE CASCADE
            ON UPDATE CASCADE");
    }

    public function down()
    {
        // Сначала желательно удалить внешние ключи, если используете raw SQL
        $db = \Config\Database::connect();
        $db->query("ALTER TABLE `characters_outfits` DROP FOREIGN KEY `fk_characters_outfits_character_id`;");
        $db->query("ALTER TABLE `characters_outfits` DROP FOREIGN KEY `fk_characters_outfits_outfit_id`;");

        // Теперь удаляем таблицу
        $this->forge->dropTable('characters_outfits', true);
    }
}
