<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * web-accounts-p0-02 (ADR-188): персонаж без Telegram.
 *
 * Web-only персонаж несёт `characters.telegram_user_id = NULL` и не имеет строки в
 * `telegram_users`. Фоновый слой пишет его задачи, туман войны и лог действий, поэтому
 * Telegram-ключи этих таблиц становятся NULL-able:
 *   - `character_tasks.telegram_user_id`, `explored_cells.telegram_user_id` (FK на
 *     `telegram_users` остаётся — NULL FK не проверяет);
 *   - `action_log.chat_id`;
 *   - `character_resources.id_telegram_users` — колонка есть только на проде (дрейф, её нет
 *     ни в одной миграции), поэтому трогается, только если существует.
 *
 * Тип колонки берётся из `information_schema` как есть (`COLUMN_TYPE`) — меняется только
 * NULL-ность, знак/ширина/дрейф прода не переписываются. Для таблиц нет — пропуск.
 *
 * down(): возвращает NOT NULL трём колонкам из миграций; если в них уже есть NULL-строки
 * (живые web-only персонажи) — отказ с понятным сообщением, данные не удаляются.
 * `character_resources.id_telegram_users` не откатывается: исходная NULL-ность дрейф-колонки
 * неизвестна, NULL-able — надмножество.
 */
class NullableTelegramKeys extends Migration
{
    /** @var list<array{0:string,1:string}> таблица, колонка — есть в миграциях */
    private const COLUMNS = [
        ['character_tasks', 'telegram_user_id'],
        ['explored_cells', 'telegram_user_id'],
        ['action_log', 'chat_id'],
    ];

    private const DRIFT_COLUMN = ['character_resources', 'id_telegram_users'];

    public function up()
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $this->modifyNullability($table, $column, true);
        }
        $this->modifyNullability(self::DRIFT_COLUMN[0], self::DRIFT_COLUMN[1], true);
    }

    public function down()
    {
        foreach (self::COLUMNS as [$table, $column]) {
            if ($this->columnType($table, $column) === null) {
                continue;
            }
            $nulls = $this->db->query("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$column}` IS NULL")->getRowArray();
            if ((int) ($nulls['c'] ?? 0) > 0) {
                throw new \RuntimeException(
                    "NullableTelegramKeys::down(): {$table}.{$column} has NULL rows (web-only characters); "
                    . 'delete or backfill them before rolling back.'
                );
            }
        }
        foreach (self::COLUMNS as [$table, $column]) {
            $this->modifyNullability($table, $column, false);
        }
    }

    private function modifyNullability(string $table, string $column, bool $nullable): void
    {
        $type = $this->columnType($table, $column);
        if ($type === null) {
            return;
        }
        $spec = $nullable ? 'NULL DEFAULT NULL' : 'NOT NULL';
        // MySQL 8 отказывает в смене NULL-ности FK-колонки (`Cannot change column ... used in a
        // foreign key constraint`) при включённых проверках; тип не меняется, FK остаётся.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->db->query("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} {$spec}");
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** COLUMN_TYPE колонки (например `int unsigned`) или null, если таблицы/колонки нет. */
    private function columnType(string $table, string $column): ?string
    {
        $row = $this->db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->getRowArray();

        return is_array($row) && is_string($row['COLUMN_TYPE'] ?? null) ? $row['COLUMN_TYPE'] : null;
    }
}
