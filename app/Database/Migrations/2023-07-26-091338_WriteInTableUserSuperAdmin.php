<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class WriteInTableUserSuperAdmin extends Migration
{
    public function up()
    {
        // NOTE: This migration creates a default admin user.
        // IMPORTANT: After deployment, immediately change the admin password!
        // Default credentials should be updated via environment configuration.

        $adminEmail = env('admin.email', 'admin@example.com');
        $adminPassword = env('admin.password', 'ChangeThisPassword123!');

        $this->db->table('users')->insert([
            'name' => 'Administrator',
            'email' => $adminEmail,
            'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_admin' => 1,
            'photo_url' => NULL
        ]);
    }

    public function down()
    {
        $adminEmail = env('admin.email', 'admin@example.com');
        $this->db->table('users')
            ->delete(['email' => $adminEmail]);
    }
}
