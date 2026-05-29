<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W22 (ADR-077) — Housing W2: interior items (косметика, 0 механики).
 *
 * Расширяет W21-декор тремя «интерьерными» слотами базы:
 *   camp_hearth    — очаг/центр атмосферы (костёр/генератор/печь…).
 *   camp_furniture — обстановка (стулья/диван/верстак/ковёр…).
 *   camp_pet       — питомец-маскот (пёс/волк/кот/ворон…).
 *
 * Хранится готовая display-строка с emoji («🔥 Костёр») → utf8mb4.
 * NULL = слот не задан. Те же claimed_cells (одна active-строка на базу).
 */
class W22AddInteriorItemsToClaimedCells extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE claimed_cells
             ADD COLUMN camp_hearth VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
             ADD COLUMN camp_furniture VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
             ADD COLUMN camp_pet VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE claimed_cells
             DROP COLUMN IF EXISTS camp_hearth,
             DROP COLUMN IF EXISTS camp_furniture,
             DROP COLUMN IF EXISTS camp_pet"
        );
    }
}
