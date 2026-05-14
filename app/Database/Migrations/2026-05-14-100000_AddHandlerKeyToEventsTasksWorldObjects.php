<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-023 Phase B6 — додаємо колонку `handler_key VARCHAR(64) NULL` у 3 таблиці:
 *   - `events.handler_key`        → дублює effect_kind (з WorldEvents.php config)
 *   - `tasks.handler_key`         → дублює Worker::$taskHandlerKeyMap[tasks.name]
 *   - `world_objects.handler_key` → дублює ObjectDiscoveryService::$objectHandlerKeyMap[name_en]
 *
 * **NULL-default**: під час dual-write окна (B6-B7) NULL означає «використовуй
 * legacy lookup». Якщо handler_key встановлений (заповнений backfill або админом
 * через dropdown) — дispatcher використовує його прямо через `HandlerRegistry::getByKey()`.
 *
 * Backfill робиться окремою spark-командою `php spark handlers:backfill` після
 * apply цієї міграції (тримаємо schema-change і data-change separate для clean rollback).
 *
 * Indexes — додаємо щоб admin-таблиці могли швидко фільтрувати/сортувати по handler_key
 * (вistsing data щe NULL, але прийдуть бекfill-ом).
 */
class AddHandlerKeyToEventsTasksWorldObjects extends Migration
{
    public function up(): void
    {
        // events.handler_key — після name_english (dispatch key старий)
        $this->forge->addColumn('events', [
            'handler_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'name_english',
            ],
        ]);
        $this->db->query('ALTER TABLE `events` ADD INDEX `events_handler_key_idx` (`handler_key`)');

        // tasks.handler_key — після name (dispatch key старий)
        $this->forge->addColumn('tasks', [
            'handler_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'name',
            ],
        ]);
        $this->db->query('ALTER TABLE `tasks` ADD INDEX `tasks_handler_key_idx` (`handler_key`)');

        // world_objects.handler_key — після name_en (dispatch key старий)
        $this->forge->addColumn('world_objects', [
            'handler_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'name_en',
            ],
        ]);
        $this->db->query('ALTER TABLE `world_objects` ADD INDEX `world_objects_handler_key_idx` (`handler_key`)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `events` DROP INDEX `events_handler_key_idx`');
        $this->forge->dropColumn('events', 'handler_key');

        $this->db->query('ALTER TABLE `tasks` DROP INDEX `tasks_handler_key_idx`');
        $this->forge->dropColumn('tasks', 'handler_key');

        $this->db->query('ALTER TABLE `world_objects` DROP INDEX `world_objects_handler_key_idx`');
        $this->forge->dropColumn('world_objects', 'handler_key');
    }
}
