<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * s1-atomicity-02 (C8, C10) — `character_resources` получает `UNIQUE (id_characters, id_resources)`
 * вместо неуникального `idx_cr_char_resource` (`AddCriticalIndexes`). На индексе стоит атомарное
 * начисление `CharacterResourceModel` (`INSERT … ON DUPLICATE KEY UPDATE quantity = quantity + ?`).
 *
 * Перед индексом миграция считает дубли пары и при их наличии падает с таблицей, числом и до пяти
 * примерами. Строки не удаляются и не сливаются: решение о чужом инвентаре — за человеком.
 * `down()` возвращает `idx_cr_char_resource`.
 */
class S1CharacterResourcesUnique extends Migration
{
    private const TABLE     = 'character_resources';
    private const INDEX     = 'uq_cr_char_resource';
    private const OLD_INDEX = 'idx_cr_char_resource';

    public function up(): void
    {
        $table = $this->db->prefixTable(self::TABLE);

        $count = $this->db->query(
            "SELECT COUNT(*) AS n FROM (SELECT 1 FROM {$table} GROUP BY id_characters, id_resources HAVING COUNT(*) > 1) d"
        )->getRowArray();
        $pairs = (int) ($count['n'] ?? 0);

        if ($pairs > 0) {
            $examples = [];
            foreach ($this->db->query(
                "SELECT id_characters, id_resources, COUNT(*) AS n FROM {$table} GROUP BY id_characters, id_resources"
                . ' HAVING COUNT(*) > 1 ORDER BY n DESC, id_characters, id_resources LIMIT 5'
            )->getResultArray() as $row) {
                $examples[] = "id_characters={$row['id_characters']}, id_resources={$row['id_resources']} ×{$row['n']}";
            }

            throw new \RuntimeException(
                'S1: в ' . self::TABLE . " {$pairs} пар(ы) (id_characters, id_resources) с дублями — UNIQUE "
                . self::INDEX . ' не создан, строки не удалены. Примеры: ' . implode('; ', $examples)
                . '. Разберите дубли вручную и повторите миграцию.'
            );
        }

        $drop = $this->hasIndex($table, self::OLD_INDEX) ? 'DROP INDEX ' . self::OLD_INDEX . ', ' : '';
        $this->db->query("ALTER TABLE {$table} {$drop}ADD UNIQUE KEY " . self::INDEX . ' (id_characters, id_resources)');
    }

    public function down(): void
    {
        $table = $this->db->prefixTable(self::TABLE);
        $this->db->query(
            "ALTER TABLE {$table} DROP INDEX " . self::INDEX . ', ADD INDEX ' . self::OLD_INDEX . ' (id_characters, id_resources)'
        );
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index])->getResultArray() !== [];
    }
}
