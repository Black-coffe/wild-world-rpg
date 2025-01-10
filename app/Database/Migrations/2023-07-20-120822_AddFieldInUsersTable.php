<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFieldsInUsersTable extends Migration
{
    public function up()
    {
        $fields = [
            'is_admin' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'null' => false,
                'default' => 0,
            ],
            'photo_url' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true,
            ]
        ];

        $this->forge->addColumn('users', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'is_admin');
        $this->forge->dropColumn('users', 'photo_url');
    }
}
