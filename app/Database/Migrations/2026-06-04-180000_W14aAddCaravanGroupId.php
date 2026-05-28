<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W14a (vNext2, Фаза 3, ADR-068) — multi-resource caravan: колонка `caravan_group_id`.
 *
 * Связывает несколько caravan-строк (2-4 ресурса) в один «богатый караван» (общий
 * cell + expiry). NULL = одиночный offer (текущее поведение V25/W5, 0 регрессии).
 * «Простая колонка чище junction» (линия ADR-036/037/067). Idempotent.
 */
class W14aAddCaravanGroupId extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('caravan_group_id', 'caravans')) {
            $this->forge->addColumn('caravans', [
                'caravan_group_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                    'after'      => 'cell_number',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('caravan_group_id', 'caravans')) {
            $this->forge->dropColumn('caravans', 'caravan_group_id');
        }
    }
}
