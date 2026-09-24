<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * web-bridge-p1-01 (ADR-189 §3) — виртуальные строки `telegram_users` для P0 web-only персонажей.
 *
 * Персонаж с `telegram_user_id IS NULL` и аккаунтом получает строку `telegram_users` с
 * `telegram_id = −(2^52 + account_id)` (= `App\Services\Web\VirtualChat::idForAccount()`), и
 * `characters.telegram_user_id` указывает на неё. Его NULL-ключи `character_tasks.telegram_user_id`
 * и `explored_cells.telegram_user_id` заполняются тем же id — после этого создатели задач
 * (`$user['id']`) и FK тумана войны согласованы для веб-действий. Новые персонажи сайта получают
 * строку сразу в `CharacterProvisioningService`.
 *
 * Персонаж без аккаунта (виртуальный id считать не из чего) не трогается.
 * Идемпотентно: строка ищется по `telegram_id`, заполняются только NULL-ключи.
 *
 * down(): только виртуальный диапазон (|telegram_id| ∈ [2^52, 2^53)) — настоящие Telegram-строки
 * не трогаются. Ключи, указывающие на виртуальные строки, сначала обнуляются (FK задач и тумана —
 * CASCADE: удаление строки без этого стёрло бы прогресс), затем строки удаляются. Строки, созданные
 * провижинингом после этой миграции, от строк бэкфилла не отличимы и откатываются вместе с ними —
 * откат миграции идёт вместе с откатом кода.
 */
class BackfillVirtualTelegramUsers extends Migration
{
    /** 2^52 — копия `VirtualChat::VIRTUAL_BASE` (миграция не зависит от кода приложения). */
    private const BASE = 4503599627370496;

    /** 2^53. */
    private const LIMIT = 9007199254740992;

    public function up(): void
    {
        $base = self::BASE;
        $now  = date('Y-m-d H:i:s');

        $this->db->query(
            "INSERT INTO telegram_users (telegram_id, first_name, created_at, updated_at)
             SELECT -({$base} + c.account_id), LEFT(MIN(c.name), 100), ?, ?
               FROM characters c
              WHERE c.telegram_user_id IS NULL
                AND c.account_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM telegram_users t WHERE t.telegram_id = -({$base} + c.account_id))
              GROUP BY c.account_id",
            [$now, $now]
        );

        $this->db->query(
            "UPDATE characters c
               JOIN telegram_users t ON t.telegram_id = -({$base} + c.account_id)
                SET c.telegram_user_id = t.id
              WHERE c.telegram_user_id IS NULL
                AND c.account_id IS NOT NULL"
        );

        foreach (['character_tasks', 'explored_cells'] as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            $this->db->query(
                "UPDATE `{$table}` x
                   JOIN characters c ON c.id = x.character_id
                   JOIN telegram_users t ON t.id = c.telegram_user_id
                    SET x.telegram_user_id = c.telegram_user_id
                  WHERE x.telegram_user_id IS NULL
                    AND {$this->virtualClause('t.telegram_id')}"
            );
        }
    }

    public function down(): void
    {
        $virtual = "SELECT id FROM telegram_users WHERE {$this->virtualClause('telegram_id')}";

        foreach (['character_tasks', 'explored_cells'] as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            $this->db->query(
                "UPDATE `{$table}` SET telegram_user_id = NULL
                  WHERE telegram_user_id IN (SELECT id FROM ({$virtual}) v)"
            );
        }
        $this->db->query(
            "UPDATE characters SET telegram_user_id = NULL
              WHERE telegram_user_id IN (SELECT id FROM ({$virtual}) v)"
        );
        $this->db->query("DELETE FROM telegram_users WHERE {$this->virtualClause('telegram_id')}");
    }

    private function virtualClause(string $column): string
    {
        return $column . ' <= -' . self::BASE . ' AND ' . $column . ' > -' . self::LIMIT;
    }
}
