<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Website-в-CI4 (ADR-050) — посты публичного сайта (блог/devblog/лор).
 *
 * `wp_post_id` — UNIQUE-ключ идемпотентного upsert при импорте из WP REST API.
 * `slug` сохраняется из WP как есть (latin/SEO) → 301-преемственность через .htaccess.
 * `content_html` — raw `content.rendered` (источник доверенный: свой WP + админ-only),
 *   MEDIUMTEXT под ~12k+ HTML на пост.
 * `canon_reviewed` — 0 при импорте; флаг ревизии под текущий ЛОР (ADR-010), оператор
 *   (Claude) переписывает тело против GAME_DESCRIPTION.md и ставит 1. При ре-импорте НЕ сбрасывается.
 *
 * utf8mb4 — кириллица + emoji.
 */
class CreateSitePostsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'wp_post_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'WP post id — идемпотентный upsert; NULL = создан вручную',
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
            'excerpt' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'content_html' => [
                'type' => 'MEDIUMTEXT',
                'null' => false,
            ],
            'featured_image' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'comment'    => 'Относительный путь, напр. uploads/site/<slug>.jpg',
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
            'published_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'canon_reviewed' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'comment'    => 'Сверено с каноном (ADR-010): 0=нет, 1=да',
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
        $this->forge->addUniqueKey('wp_post_id');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey(['status', 'published_at']);
        $this->forge->addKey('canon_reviewed');
        $this->forge->createTable('site_posts', true);

        $this->db->query('ALTER TABLE `site_posts` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(): void
    {
        $this->forge->dropTable('site_posts', true);
    }
}
