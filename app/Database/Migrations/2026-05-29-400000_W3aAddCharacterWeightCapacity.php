<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W3a (ADR-059) — foundation для Cargo drone. Добавляет soft-cap веса инвентаря
 * персонажа. На ship default = 9999 (effectively off): механика weight-cap
 * выключается через `inventory.weight_cap_enabled` GameSetting; включаем после
 * balance-pass (W3b или W8). Aдитивная миграция, idempotent.
 *
 * ⚠️ Поле ОБЯЗАНО быть в CharacterModel::$allowedFields (иначе Model::update
 * фильтрует его в пустой → DataException). Урок disable_media/last_planted_crop.
 *
 * Резолюции ADR-059 §«7 резолюций»:
 *  - Q1: kg/целые единица веса.
 *  - Q3: default=9999 (effectively off) на ship; включаем когда GameSettings ready.
 */
class W3aAddCharacterWeightCapacity extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('weight_capacity', 'characters')) {
            $this->forge->addColumn('characters', [
                'weight_capacity' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => false,
                    'default'    => 9999,
                    'after'      => 'gold',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('weight_capacity', 'characters')) {
            $this->forge->dropColumn('characters', 'weight_capacity');
        }
    }
}
