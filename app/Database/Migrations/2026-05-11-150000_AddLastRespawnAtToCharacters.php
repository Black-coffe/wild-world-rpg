<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Death-validation batch 4 (карточка `inbox/2026-05-11-validation-card-death-notifications.md`):
 * «чистый респаун». Столбец `characters.last_respawn_at` фиксирует момент последнего
 * возрождения персонажа — нужен для grace-окна иммунитета к damage-событиям (~N минут
 * после смерти, чтобы то же событие не накидывалось мгновенно; см. `GameBalance::$respawnGraceMinutes`).
 *
 * НЕ реюзаем `updated_at` — тот меняется каждый тик любым handler'ом.
 */
class AddLastRespawnAtToCharacters extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('characters', [
            'last_respawn_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('characters', 'last_respawn_at');
    }
}
