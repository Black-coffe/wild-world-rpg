<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWeaponsTable extends Migration
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
            // Название на русском (или локализованном языке) с пробелами
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => false,
            ],
            // Англ. название (slug / слитное)
            'name_en' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
            ],

            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'weapon_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,  // Например, 'Melee', 'Ranged', 'Energy', 'Explosive', ...
                'null'       => false,
            ],
            'damage_value' => [
                'type'    => 'FLOAT',
                'default' => 0,
            ],
            'damage_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,  // 'Physical', 'Piercing', 'Blunt', 'Energy', ...
                'null'       => false,
            ],
            'range_value' => [
                'type'    => 'FLOAT',
                'default' => 1,      // 1 = ближний бой, 2-3=средняя и т.д.
            ],
            'attack_speed' => [
                'type'    => 'FLOAT',
                'default' => 1.0,
            ],
            // Спецэффект в формате JSON или текст
            'special_effect' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'effect_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            // Ограничение тоже в формате JSON
            'limitation' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'limitation_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            // Требования к силе/ловкости/интеллекту/уровню
            'required_strength' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'required_agility' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'required_intellect' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'required_level' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'rarity' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,  // 'Common', 'Uncommon', 'Rare', 'Epic', 'Legendary'
                'default'    => 'Common',
            ],
            // Прочность
            'durability' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'durability_max' => [
                'type'    => 'INT',
                'default' => 100,
            ],
            'weight' => [
                'type'    => 'FLOAT',
                'default' => 0,
            ],
            'price' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'ammo_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100, // Например, 'патроны 9мм', 'Энергоячейки', и т.п.
                'null'       => true,
            ],
            // created_at / updated_at (при желании)
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);   // Primary Key
        $this->forge->createTable('weapons');
    }

    public function down()
    {
        $this->forge->dropTable('weapons', true);
    }
}
