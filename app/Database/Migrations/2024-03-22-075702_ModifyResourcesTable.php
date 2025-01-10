<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ModifyResourcesTable extends Migration
{
    public function up()
    {
        // Изменение структуры таблицы 'resources'
        $this->forge->modifyColumn('resources', [
            'biome_id' => [
                'type' => 'TEXT',
                'null' => true,
                // Убедитесь, что ваше приложение корректно обрабатывает сериализацию и десериализацию JSON строки для этого поля
            ],
            'type' => [
                'type' => 'TEXT',
                // Также нужно будет обрабатывать JSON строку для этого поля
            ],
        ]);
    }

    public function down()
    {
        //
    }
}
