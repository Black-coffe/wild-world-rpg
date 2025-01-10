<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class WriteInTableUserSuperAdmin extends Migration
{
    public function up()
    {
        $this->db->table('users')->insert([
            'name' => 'Administrator',
            'email' => 'super@admin.com',
            'password_hash' => '$2y$10$nCM15SZW.rmvNHTTebzD..shN4ZcHwa75S9zCLwT1sZv/FAedccYG',
            'created_at' => '2023-07-26 11:58:49',
            'updated_at' => '2023-07-26 11:58:49',
            'is_admin' => 1,
            'photo_url' => NULL
        ]);
    }

    public function down()
    {
        $this->db->table('users')
            ->delete(['email' => 'super@admin.com']);
    }
}
