<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W27 (ADR-082) — per-character locale для локализации.
 *
 * characters.locale (ru/en, default 'ru' — игра ведётся на русском). Устанавливает
 * язык интерфейса игрока; LocaleService применяет его на каждое сообщение. Default
 * 'ru' → 0 изменений для всех существующих игроков.
 */
class W27AddLocaleToCharacters extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE characters ADD COLUMN locale VARCHAR(5) NOT NULL DEFAULT 'ru'"
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE characters DROP COLUMN IF EXISTS locale');
    }
}
