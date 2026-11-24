<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * s1-atomicity-02 (C7, C10) — `quest_steps` получает `UNIQUE (quest_id, character_id)`: одна строка
 * на пару квест-персонаж, повторный старт (двойной тап, бот и веб одновременно) упирается в индекс,
 * а `QuestStartService::claimFirstStep()` пишет через `insertUnique()` и видит `Refused`.
 *
 * Перед индексом миграция считает дубли пары и при их наличии падает с таблицей, числом и до пяти
 * примерами. Строки не удаляются: какой из дублей настоящий, решает человек.
 */
class S1QuestStepsUnique extends Migration
{
    private const TABLE = 'quest_steps';
    private const INDEX = 'uq_qs_quest_character';

    public function up(): void
    {
        $table = $this->db->prefixTable(self::TABLE);

        $count = $this->db->query(
            "SELECT COUNT(*) AS n FROM (SELECT 1 FROM {$table} GROUP BY quest_id, character_id HAVING COUNT(*) > 1) d"
        )->getRowArray();
        $pairs = (int) ($count['n'] ?? 0);

        if ($pairs > 0) {
            $examples = [];
            foreach ($this->db->query(
                "SELECT quest_id, character_id, COUNT(*) AS n FROM {$table} GROUP BY quest_id, character_id"
                . ' HAVING COUNT(*) > 1 ORDER BY n DESC, quest_id, character_id LIMIT 5'
            )->getResultArray() as $row) {
                $examples[] = "quest_id={$row['quest_id']}, character_id={$row['character_id']} ×{$row['n']}";
            }

            throw new \RuntimeException(
                'S1: в ' . self::TABLE . " {$pairs} пар(ы) (quest_id, character_id) с дублями — UNIQUE "
                . self::INDEX . ' не создан, строки не удалены. Примеры: ' . implode('; ', $examples)
                . '. Разберите дубли вручную и повторите миграцию.'
            );
        }

        $this->db->query("ALTER TABLE {$table} ADD UNIQUE KEY " . self::INDEX . ' (quest_id, character_id)');
    }

    public function down(): void
    {
        $table = $this->db->prefixTable(self::TABLE);
        $this->db->query("ALTER TABLE {$table} DROP INDEX " . self::INDEX);
    }
}
