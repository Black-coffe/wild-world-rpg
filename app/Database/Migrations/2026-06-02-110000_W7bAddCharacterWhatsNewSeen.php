<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W7b (ADR-065 Part 2) — трекинг прочитанных тем каталога «📚 Что нового».
 *
 * `whats_new_seen` хранит JSON-массив topic_key прочитанных тем (lazy-init NULL).
 * Каталог использует его, чтобы маркировать непрочитанные темы значком 🆕 —
 * единственный reader колонки (не BUILT-BUT-DEAD).
 *
 * Тип TEXT (а не нативный JSON) — переносимость между версиями MariaDB без
 * авто-CHECK json_valid; записи всегда через json_encode (валидный JSON).
 *
 * Колонку нужно добавить в CharacterModel::$allowedFields, иначе CI4 отфильтрует
 * её при update() в пустоту (memory feedback_ci4_alter_column_needs_allowedfields).
 *
 * Forced-show вернувшимся игрокам (last_whats_new_shown_at + cooldown) отложен —
 * добавится отдельной миграцией, когда фича будет реализована (не плодим dead-колонку).
 */
class W7bAddCharacterWhatsNewSeen extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('whats_new_seen', 'characters')) {
            return;
        }

        $this->forge->addColumn('characters', [
            'whats_new_seen' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'daily_tips_enabled',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('whats_new_seen', 'characters')) {
            $this->forge->dropColumn('characters', 'whats_new_seen');
        }
    }
}
