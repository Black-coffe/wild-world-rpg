<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-165 — продажа экипировки торговцу: место для неё в журнале сделок.
 *
 * `transactions` заводилась под ОДИН вид товара: `crafted_item_id` NOT NULL с FK на
 * `crafted_items`. Экипировка живёт в `weapons` / `outfits`, поэтому её продажа в этот
 * журнал физически не помещалась.
 *
 * Записать её туда обязательно, и не ради отчётности: {@see \App\Services\Economy\VendorDailyLimitService}
 * считает суточный лимит выкупа как `SUM(price)` по `transactions` за скользящие сутки.
 * Отдельная таблица для экипировки означала бы, что новый канал продаж проходит МИМО
 * лимита — то есть даёт обход потолка, ради которого ADR-157 и вводился.
 *
 * Что делаем:
 *  - `crafted_item_id` → NULL-able (FK сохраняется, NULL его не нарушает);
 *  - `item_kind` — вид товара строки: 'craft' (легаси и весь существующий крафт),
 *    'weapon', 'outfit'. VARCHAR, а не ENUM: под STRICT неизвестное значение ENUM
 *    роняет INSERT целиком, а вид товара будет пополняться.
 *  - `item_ref_id` — id в `weapons` / `outfits` для строк экипировки (у 'craft' — NULL,
 *    ссылка остаётся в `crafted_item_id`). Отдельным полем, потому что FK на
 *    `crafted_items` не может указывать в две другие таблицы.
 *
 * Обратная совместимость: все существующие строки получают `item_kind = 'craft'`, то
 * есть читатели журнала (`DashboardAnalyticsService`, `CraftingEconomyService`,
 * `PlayerEconomyService`, `AchievementService`) видят ровно прежнюю картину — ни один
 * из них не джойнит `crafted_items`, поэтому NULL в ссылке ничего не ломает.
 *
 * WipeManifest не трогаем: таблица уже классифицирована как PLAYER_DATA
 * (`link => character_id`), новых таблиц миграция не создаёт.
 */
class Adr165GearSaleTransactions extends Migration
{
    public function up(): void
    {
        // Идемпотентность: миграция могла частично примениться на testbot.
        if (! $this->hasColumn('item_kind')) {
            $this->forge->addColumn('transactions', [
                'item_kind' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => false,
                    'default'    => 'craft',
                    'after'      => 'crafted_item_id',
                ],
            ]);
        }

        if (! $this->hasColumn('item_ref_id')) {
            $this->forge->addColumn('transactions', [
                'item_ref_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                    'after'      => 'item_kind',
                ],
            ]);
        }

        // NOT NULL → NULL. Через сырой SQL: forge->modifyColumn на колонке под FK
        // пересобирает определение и на части версий MySQL спотыкается о constraint.
        $this->db->query('ALTER TABLE `transactions` MODIFY `crafted_item_id` INT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Строки экипировки не имеют crafted_item_id — при откате они бы нарушили NOT NULL.
        $this->db->query("DELETE FROM `transactions` WHERE `item_kind` <> 'craft'");
        $this->db->query('ALTER TABLE `transactions` MODIFY `crafted_item_id` INT UNSIGNED NOT NULL');

        if ($this->hasColumn('item_ref_id')) {
            $this->forge->dropColumn('transactions', 'item_ref_id');
        }
        if ($this->hasColumn('item_kind')) {
            $this->forge->dropColumn('transactions', 'item_kind');
        }
    }

    private function hasColumn(string $column): bool
    {
        return in_array($column, $this->db->getFieldNames('transactions'), true);
    }
}
