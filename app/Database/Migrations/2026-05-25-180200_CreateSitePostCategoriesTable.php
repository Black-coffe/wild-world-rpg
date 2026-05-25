<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Website-в-CI4 (ADR-052) — связь постов и категорий (M:N).
 *
 * Один WP-пост числится в нескольких категориях → many-to-many. Композитный
 * первичный ключ (post_id, category_id) = естественная дедупликация. FK не ставим
 * (часть кодовой базы без FK; дедуп через PK достаточен и развязывает порядок миграций).
 */
class CreateSitePostCategoriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'post_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
            'category_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
            ],
        ]);

        $this->forge->addPrimaryKey(['post_id', 'category_id']);
        $this->forge->addKey('category_id');
        $this->forge->createTable('site_post_categories', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('site_post_categories', true);
    }
}
