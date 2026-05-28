<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W11 (vNext2, Фаза 3, ADR-067) — branching engine: 2 колонки на `quests`.
 *
 *   branch_group VARCHAR(64) NULL  — квесты с одинаковым non-null branch_group +
 *       общим prerequisite_quest = взаимоисключающий набор выбора. NULL = обычный
 *       линейный квест (0 регрессии для всех существующих квестов).
 *   branch_label VARCHAR(100) NULL — текст кнопки выбора (fallback на title_ru).
 *
 * Состояние выбора выводится из существующей quest_steps — без новой таблицы.
 * Idempotent (проверка наличия колонки).
 */
class W11AddQuestBranchColumns extends Migration
{
    public function up(): void
    {
        $fields = [];
        if (! $this->db->fieldExists('branch_group', 'quests')) {
            $fields['branch_group'] = [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'objective_qty',
            ];
        }
        if (! $this->db->fieldExists('branch_label', 'quests')) {
            $fields['branch_label'] = [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'branch_group',
            ];
        }
        if ($fields !== []) {
            $this->forge->addColumn('quests', $fields);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('branch_label', 'quests')) {
            $this->forge->dropColumn('quests', 'branch_label');
        }
        if ($this->db->fieldExists('branch_group', 'quests')) {
            $this->forge->dropColumn('quests', 'branch_group');
        }
    }
}
