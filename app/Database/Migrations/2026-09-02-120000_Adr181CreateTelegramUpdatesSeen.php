<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-181 — дедупликация входящих Telegram-апдейтов по `update_id`. До этой миграции
 * защиты не было вовсе: `update_id` не встречался в `app/`, а встроенный дедуп Longman
 * мёртв (`enableMySql()` не вызывается, `$pdo` всегда null).
 *
 * Таблица `telegram_updates_seen` (TRANSIENT — Config\WipeManifest, без player-связи):
 * `update_id` — сам PRIMARY KEY, суррогатного `id` нет намеренно — уникальность и есть
 * смысл строки, а `update_id` монотонно растёт (вставки идут в конец индекса). Решение
 * «дубль или нет» принимается BotController'ом по исходу INSERT (1062 → дубль), не по
 * предварительному SELECT.
 *
 * Ретенция — отдельная spark-команда `telegram-updates:cleanup` (Config\Tasks, ночное
 * окно рядом с player-actions:cleanup/community:cleanup), не чистка на горячем пути тапа.
 */
class Adr181CreateTelegramUpdatesSeen extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'update_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
        ]);

        $this->forge->addPrimaryKey('update_id');
        $this->forge->addKey('created_at');
        $this->forge->createTable('telegram_updates_seen', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('telegram_updates_seen', true);
    }
}
