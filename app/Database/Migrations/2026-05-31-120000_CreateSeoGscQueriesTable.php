<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * SEO-пайплайн (Шаг 6a) — снапшоты Search Analytics по поисковым запросам из Google Search Console.
 *
 * Каждый прогон `php spark seo:gsc-pull` пишет свежий батч строк с одним `captured_at`
 * за период [period_start; period_end] → копится история (видно динамику).
 * `query` utf8mb4 — кириллические поисковые запросы.
 */
class CreateSeoGscQueriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'query' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'clicks' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
            ],
            'impressions' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
            ],
            'ctr' => [
                'type'       => 'DECIMAL',
                'constraint' => '6,4',
                'null'       => false,
                'default'    => 0,
                'comment'    => 'Доля 0..1 (clicks/impressions)',
            ],
            'position' => [
                'type'       => 'DECIMAL',
                'constraint' => '6,2',
                'null'       => false,
                'default'    => 0,
                'comment'    => 'Средняя позиция в выдаче',
            ],
            'period_start' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'period_end' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'captured_at' => [
                'type'    => 'DATETIME',
                'null'    => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('captured_at');
        $this->forge->addKey('query');
        $this->forge->createTable('seo_gsc_queries', true);

        $this->db->query('ALTER TABLE `seo_gsc_queries` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(): void
    {
        $this->forge->dropTable('seo_gsc_queries', true);
    }
}
