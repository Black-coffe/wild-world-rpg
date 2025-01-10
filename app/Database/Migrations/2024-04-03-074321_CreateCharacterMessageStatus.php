<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCharacterMessageStatus extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 5,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'character_id' => [
                'type' => 'INT',
            ],
            'character_data_id' => [
                'type' => 'INT',
            ],
            'status' => [
                'type' => 'ENUM("pending", "done")',
                'default' => 'pending',
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('character_message_status');
    }

    public function down()
    {
        $this->forge->dropTable('character_message_status');
    }
}
