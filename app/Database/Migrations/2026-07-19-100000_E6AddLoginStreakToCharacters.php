<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 3 — стрик входа (мягко, без FOMO).
 *
 *   • characters.login_streak (INT, default 0) — текущая серия дней подряд;
 *   • characters.login_streak_last_day (DATE, NULL) — дата последнего засчитанного входа.
 *
 * Награда выдаётся на ПЕРВОМ взаимодействии нового дня (LoginStreakService, хук в
 * webhook). Пропуск до grace_days дней НЕ обнуляет серию (мягко). WipeManifest:
 * обе колонки добавлены в characterResetValues (CHARACTER_RESET → 0/null после вайпа).
 */
class E6AddLoginStreakToCharacters extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('characters', [
            'login_streak' => [
                'type'       => 'INT',
                'null'       => false,
                'default'    => 0,
            ],
            'login_streak_last_day' => [
                'type' => 'DATE',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('characters', ['login_streak', 'login_streak_last_day']);
    }
}
