<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W17 (vNext2, Фаза 4, ADR-071) — PvP duels: флаг «открыт к дуэлям».
 *
 * `characters.duels_open` (bool, default 0): игрок opt-in к получению дуэлей (тумблер в
 * ⚙️ Настройках). 0 = закрыт (текущее поведение, дуэлей нет). Idempotent.
 */
class W17AddCharacterDuelsOpen extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('duels_open', 'characters')) {
            $this->forge->addColumn('characters', [
                'duels_open' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 0,
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('duels_open', 'characters')) {
            $this->forge->dropColumn('characters', 'duels_open');
        }
    }
}
