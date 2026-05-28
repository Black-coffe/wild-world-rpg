<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W11 (vNext2, Фаза 3, ADR-067) — fix: branch_label → utf8mb4 (emoji-лейблы веток).
 *
 * Таблица `quests` создана в 2024 как utf8mb3 → новая колонка `branch_label` (миграция
 * W11AddQuestBranchColumns) унаследовала utf8mb3, и seed-вставка усекла emoji 🤝/🎒 в '?'
 * (кириллица проходит, emoji ломается — урок feedback_emoji_content_needs_utf8mb4).
 *
 * Конвертируем колонку в utf8mb4 + переписываем emoji-лейблы демо-веток. Идёт ПОСЛЕ
 * seed (120000): на свежем проде корректирует усечённую вставку, на testbot — уже
 * applied utf8mb3-данные. Idempotent (MODIFY + UPDATE по факту).
 */
class W11FixQuestBranchLabelUtf8mb4 extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE quests MODIFY branch_label VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');

        $fix = [
            'DemoPathSettlement' => '🤝 Помочь поселению',
            'DemoPathSalvage'    => '🎒 Забрать припасы',
        ];
        foreach ($fix as $titleEn => $label) {
            $this->db->table('quests')->where('title_en', $titleEn)->update(['branch_label' => $label]);
        }
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE quests MODIFY branch_label VARCHAR(100) NULL');
    }
}
