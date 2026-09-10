<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * health-warning-backoff, story-03 (docs/specs/health-warning-backoff/brief.md, «Добавлено
 * владельцем по ходу круга» — тумблер отключения предупреждений о низком здоровье в Настройках).
 *
 * `characters.health_warnings_enabled` — opt-out, как у «Совета дня» (`daily_tips_enabled`):
 * по умолчанию включено (1). Игрок, выключивший тумблер, не получает НИКАКИХ предупреждений
 * о низком здоровье от {@see \App\TaskHandlers\LowHealthWarningHandler}, включая критические —
 * экран настроек (story-03, `SettingsAction`) обязан прямо сказать об этой цене.
 *
 * `characters` уже классифицирована в `Config\WipeManifest` как `CHARACTER_RESET` — новая
 * колонка это преференция персонажа, сбрасывается вместе с ним, манифест не трогаем.
 * Идемпотентно (fieldExists), рядом с существующими колонками затухания (story-01).
 */
class HealthWarnMuteColumn extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('health_warnings_enabled', 'characters')) {
            return;
        }

        $this->forge->addColumn('characters', [
            'health_warnings_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
                'after'      => 'low_health_warns_day',
                'comment'    => 'health-warning-backoff-03 — opt-out предупреждений о низком HP (1 = получает)',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('health_warnings_enabled', 'characters')) {
            $this->forge->dropColumn('characters', 'health_warnings_enabled');
        }
    }
}
