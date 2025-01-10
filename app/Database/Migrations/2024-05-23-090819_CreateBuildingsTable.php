<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBuildingsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'name_ru' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
            ],
            'name_en' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'building_type' => [
                'type' => 'ENUM',
                'constraint' => ['military', 'residential', 'farming', 'resource', 'engineering'],
            ],
            'hp' => [
                'type' => 'INT',
                'constraint' => 11,
            ],
            'construction_time' => [
                'type' => 'INT',
                'constraint' => 11,
                'comment' => 'Время в минутах, необходимое для постройки',
            ],
            'tax' => [
                'type' => 'INT',
                'constraint' => 11,
                'comment' => 'Налог в золоте, списываемый в сутки',
            ],
            'level' => [
                'type' => 'INT',
                'constraint' => 2,
                'default' => 1,
            ],
            'usage' => [
                'type' => 'ENUM',
                'constraint' => ['personal', 'collective', 'all'],
            ],
            'required_resources' => [
                'type' => 'TEXT',
                'comment' => 'Необходимые ресурсы для постройки (в формате JSON)',
            ],
            'min_character_level' => [
                'type' => 'INT',
                'constraint' => 11,
                'comment' => 'Минимальный уровень персонажа для постройки',
            ],
            'effects' => [
                'type' => 'TEXT',
                'comment' => 'Особые эффекты или бонусы от постройки (в формате JSON)',
            ],
            'days_until_disappearance' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
                'comment' => 'Количество дней пока постройка не исчезнет у игрока',
            ],
            'usage_count' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
                'comment' => 'Количество использований данного строения',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('buildings');
    }

    public function down()
    {
        $this->forge->dropTable('buildings');
    }
}
