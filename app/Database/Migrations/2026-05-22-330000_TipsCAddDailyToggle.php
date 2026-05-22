<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-038 Фаза C1 — тумблер «Совет дня» (per-character opt-out).
 *
 * `characters.daily_tips_enabled` TINYINT(1) DEFAULT 1 (opt-out: по умолчанию ВКЛ).
 * Управляется в /settings ({@see \App\Controllers\Telegram\Commands\Actions\SettingsAction}).
 * Авто-рассылка {@see \App\TaskHandlers\Tips\DailyTipBroadcastHandler} шлёт только тем, у кого =1.
 *
 * 🔴 Поле добавлено в CharacterModel::$allowedFields в этом же батче — иначе CI4
 *    фильтрует update в пустой → DataException (урок feedback_ci4_alter_column_needs_allowedfields).
 *
 * Idempotent: добавляет колонку только если её ещё нет.
 */
class TipsCAddDailyToggle extends Migration
{
    public function up(): void
    {
        $fields = $this->db->getFieldNames('characters');
        if (in_array('daily_tips_enabled', $fields, true)) {
            return;
        }

        $this->forge->addColumn('characters', [
            'daily_tips_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
                'after'      => 'disable_media',
            ],
        ]);
    }

    public function down(): void
    {
        $fields = $this->db->getFieldNames('characters');
        if (in_array('daily_tips_enabled', $fields, true)) {
            $this->forge->dropColumn('characters', 'daily_tips_enabled');
        }
    }
}
