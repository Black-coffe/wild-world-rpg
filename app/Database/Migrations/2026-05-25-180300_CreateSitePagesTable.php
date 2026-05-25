<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Website-в-CI4 (ADR-052) — статические страницы сайта (О проекте, Контакты и т.п.).
 *
 * Отдельно от постов: у страниц нет категорий/ленты/даты публикации, они адресуются
 * по slug напрямую (Front::page). utf8mb4 — кириллица + emoji.
 */
class CreateSitePagesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => false,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'content_html' => [
                'type' => 'MEDIUMTEXT',
                'null' => false,
            ],
            'meta_description' => [
                'type'       => 'VARCHAR',
                'constraint' => 320,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'published'],
                'null'       => false,
                'default'    => 'draft',
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
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('status');
        $this->forge->createTable('site_pages', true);

        $this->db->query('ALTER TABLE `site_pages` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(): void
    {
        $this->forge->dropTable('site_pages', true);
    }
}
