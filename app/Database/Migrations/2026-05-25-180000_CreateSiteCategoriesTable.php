<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Website-в-CI4 (ADR-052) — категории публичного сайта.
 *
 * Источник: WordPress-категории wildworld.fun (DevBlog, Информация, Местность,
 * Сырьё, Летопись Мира, NPC, Крафт). `wp_term_id` хранит исходный WP term id
 * для идемпотентного upsert импортёром; NULL — для категорий, заведённых вручную.
 *
 * utf8mb4 — кириллица + emoji в name/description (урок [[feedback_emoji_content_needs_utf8mb4]]).
 */
class CreateSiteCategoriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'wp_term_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'WP term id для идемпотентного импорта; NULL = ручная категория',
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => 160,
                'null'       => false,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 160,
                'null'       => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sort' => [
                'type'    => 'INT',
                'null'    => false,
                'default' => 0,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('wp_term_id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('sort');
        $this->forge->createTable('site_categories', true);

        $this->db->query('ALTER TABLE `site_categories` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(): void
    {
        $this->forge->dropTable('site_categories', true);
    }
}
