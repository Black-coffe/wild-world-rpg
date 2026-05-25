<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Website-в-CI4 (ADR-052) — таблица 301/302-редиректов для сохранения SEO.
 *
 * Проверяется в Errors::notFound (через set404Override) ПЕРЕД отдачей 404.
 * `from_path` нормализован: ведущий `/`, без хвостового `/`, URL-ДЕКОДИРОВАН
 *   (uriProtocol=REQUEST_URI не декодирует → кириллические страницы /главная/
 *   хранить как UTF-8 → нужен utf8mb4). `hits` — счётчик для аналитики «хвостов».
 *
 * Slug-сохраняющие переезды (htaccess + совпадающий маршрут) строки НЕ требуют —
 * только изменённые/кириллические/реструктуризированные/удалённые URL + перенос admin-путей.
 */
class CreateSiteRedirectsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'from_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'comment'    => 'Нормализован: ведущий /, без хвостового /, URL-декодирован (UTF-8)',
            ],
            'to_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'code' => [
                'type'       => 'SMALLINT',
                'null'       => false,
                'default'    => 301,
                'comment'    => '301 perm | 302 temp | 308',
            ],
            'hits' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
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
        $this->forge->addUniqueKey('from_path');
        $this->forge->createTable('site_redirects', true);

        $this->db->query('ALTER TABLE `site_redirects` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(): void
    {
        $this->forge->dropTable('site_redirects', true);
    }
}
