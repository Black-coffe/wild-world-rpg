<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\Migration;

/**
 * s1-atomicity-01 (C1, C10) — «одна 🔒-задача в работе» держит БД, а не только гейт ADR-167.
 *
 * `is_exclusive` — копия `tasks.parallel_execution_allowed = 0` на момент вставки (A1); её ставит
 * `ActiveTasksService::insertExclusiveTaskRow()`. `exclusive_slot` равна `character_id` только у
 * строки `in_work` с `is_exclusive = 1`, остальные статусы дают NULL, а NULL в UNIQUE не
 * конфликтует — по прецеденту ADR-186 (`pvp_standoffs.open_defender_id`).
 *
 * Порядок `up()`: флаг → бэкфилл для строк в любом статусе → подсчёт нарушений (персонажи с 2+
 * `in_work` 🔒) → при нарушениях флаг снимается обратно и летит `RuntimeException`; строки не
 * удаляются и не правятся → иначе генерируемая колонка и UNIQUE.
 *
 * Тип `exclusive_slot` = тип `character_tasks.character_id` (`INT UNSIGNED`,
 * `CreateCharacterTasksTable`). Определения колонок тестовые фикстуры копируют дословно (C9).
 */
class S1CharacterTasksExclusiveSlot extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('is_exclusive', 'character_tasks')) {
            $this->db->query(
                'ALTER TABLE `character_tasks` ADD COLUMN `is_exclusive` TINYINT(1) NOT NULL DEFAULT 0'
            );
        }

        $this->db->query(
            'UPDATE `character_tasks` ct INNER JOIN `tasks` t ON t.id = ct.task_id '
            . 'SET ct.is_exclusive = 1 WHERE t.parallel_execution_allowed = 0'
        );

        $result = $this->db->query(
            "SELECT character_id FROM `character_tasks` WHERE status = 'in_work' AND is_exclusive = 1 "
            . 'GROUP BY character_id HAVING COUNT(*) > 1 ORDER BY character_id'
        );
        $violators = [];
        if ($result instanceof BaseResult) {
            foreach ($result->getResultArray() as $row) {
                $violators[] = is_numeric($row['character_id'] ?? null) ? (string) $row['character_id'] : '?';
            }
        }

        if ($violators !== []) {
            // Возвращаем таблицу к виду до миграции: повторный запуск после ручного разбора начнёт с нуля.
            $this->db->query('ALTER TABLE `character_tasks` DROP COLUMN `is_exclusive`');

            throw new \RuntimeException(
                'character_tasks: ' . count($violators) . ' персонаж(ей) с 2+ in_work 🔒-задачами '
                . '(tasks.parallel_execution_allowed = 0), например character_id: '
                . implode(', ', array_slice($violators, 0, 5))
                . '. UNIQUE uq_ct_exclusive_slot не создан, строки не тронуты — разберите дубли вручную.'
            );
        }

        // VIRTUAL, не STORED: у `character_id` FK ON DELETE/UPDATE CASCADE (`CreateCharacterTasksTable`),
        // а STORED-колонку от столбца с каскадным ключом MySQL отвергает (1215). UNIQUE по VIRTUAL
        // InnoDB держит так же — индекс хранит вычисленное значение (s1-atomicity-09).
        $this->db->query(
            'ALTER TABLE `character_tasks` ADD COLUMN `exclusive_slot` INT UNSIGNED '
            . "GENERATED ALWAYS AS (IF(`status` = 'in_work' AND `is_exclusive` = 1, `character_id`, NULL)) VIRTUAL"
        );
        $this->db->query(
            'ALTER TABLE `character_tasks` ADD UNIQUE KEY `uq_ct_exclusive_slot` (`exclusive_slot`)'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `character_tasks` DROP INDEX `uq_ct_exclusive_slot`');
        $this->db->query('ALTER TABLE `character_tasks` DROP COLUMN `exclusive_slot`');
        $this->db->query('ALTER TABLE `character_tasks` DROP COLUMN `is_exclusive`');
    }
}
