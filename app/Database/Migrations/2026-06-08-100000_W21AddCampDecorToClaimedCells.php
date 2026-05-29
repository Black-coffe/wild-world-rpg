<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W21 (ADR-076) — Housing customisation: добавляет поля декора в claimed_cells.
 *
 * camp_name — имя лагеря из пресета (VARCHAR 50, NULL = не задано).
 * camp_flag — emoji-флаг (VARCHAR 10 utf8mb4, NULL = не задано).
 */
class W21AddCampDecorToClaimedCells extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE claimed_cells
             ADD COLUMN camp_name VARCHAR(50) NULL DEFAULT NULL,
             ADD COLUMN camp_flag VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE claimed_cells
             DROP COLUMN IF EXISTS camp_name,
             DROP COLUMN IF EXISTS camp_flag"
        );
    }
}
