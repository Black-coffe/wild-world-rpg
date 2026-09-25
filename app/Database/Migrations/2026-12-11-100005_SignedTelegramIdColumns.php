<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * web-bridge-p1-01 (ADR-189 §3) — колонки, куда доходит виртуальный (отрицательный) Telegram-id,
 * становятся SIGNED.
 *
 * Проверка story 01 (information_schema локальной БД + миграции): `action_log.chat_id` и
 * `player_action_log.telegram_user_id` — `bigint unsigned`. Под STRICT_TRANS_TABLES запись
 * `−(2^52 + account_id)` в них — ошибка «Out of range value». Остальные колонки пути уже годятся:
 * `telegram_users.telegram_id` и `player_action_log.chat_id` — `bigint` (signed),
 * `character_tasks`/`explored_cells.telegram_user_id` хранят внутренний `telegram_users.id` (> 0).
 *
 * Меняется только знак: ширина (BIGINT) и NULL-ность берутся из `information_schema` как есть.
 * Нет таблицы/колонки или колонка уже signed — пропуск (идемпотентно).
 *
 * down(): возвращает UNSIGNED; если в колонке уже есть отрицательные значения — отказ с понятным
 * сообщением, данные не удаляются.
 */
class SignedTelegramIdColumns extends Migration
{
    /** @var list<array{0:string,1:string}> */
    private const COLUMNS = [
        ['action_log', 'chat_id'],
        ['player_action_log', 'telegram_user_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $this->modifySign($table, $column, false);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            if ($this->column($table, $column) === null) {
                continue;
            }
            $neg = $this->db->query("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$column}` < 0")->getRowArray();
            if ((int) ($neg['c'] ?? 0) > 0) {
                throw new \RuntimeException(
                    "SignedTelegramIdColumns::down(): {$table}.{$column} has negative (virtual) ids; "
                    . 'delete them before rolling back.'
                );
            }
        }
        foreach (self::COLUMNS as [$table, $column]) {
            $this->modifySign($table, $column, true);
        }
    }

    private function modifySign(string $table, string $column, bool $unsigned): void
    {
        $col = $this->column($table, $column);
        if ($col === null) {
            return;
        }
        [$type, $nullable] = $col;
        $base     = trim((string) preg_replace('/\s+unsigned\b/i', '', $type));
        $target   = $unsigned ? $base . ' unsigned' : $base;
        if (strcasecmp($target, $type) === 0) {
            return;
        }
        $spec = $nullable ? 'NULL DEFAULT NULL' : 'NOT NULL';
        $this->db->query("ALTER TABLE `{$table}` MODIFY `{$column}` {$target} {$spec}");
    }

    /** @return array{0:string,1:bool}|null COLUMN_TYPE и NULL-ность, или null — нет таблицы/колонки */
    private function column(string $table, string $column): ?array
    {
        $row = $this->db->query(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->getRowArray();

        if (! is_array($row) || ! is_string($row['COLUMN_TYPE'] ?? null)) {
            return null;
        }

        return [$row['COLUMN_TYPE'], ($row['IS_NULLABLE'] ?? '') === 'YES'];
    }
}
