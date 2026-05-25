<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V16 (ROADMAP-vNext Фаза 4, ADR-047) — поля специализации персонажа.
 *
 * Workshop specialization: персонаж выбирает крафт-ветку (weaponsmith/medic/engineer),
 * крафт «своей» категории идёт быстрее (V16 — базовый перк; V17 углубит).
 *
 *  - `specialization`            — ветка (null = не выбрана; 0 регрессии для существующих).
 *  - `specialization_changed_at` — момент последней смены (для кулдауна respec).
 *
 * ⚠️ Оба поля ОБЯЗАНЫ быть в CharacterModel::$allowedFields (иначе Model::update
 * фильтрует их в пустой → DataException). Урок disable_media/last_planted_crop.
 * Idempotent (guard fieldExists).
 */
class V16AddCharacterSpecialization extends Migration
{
    public function up(): void
    {
        $cols = [];
        if (! $this->db->fieldExists('specialization', 'characters')) {
            $cols['specialization'] = [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'default'    => null,
                'after'      => 'intellect',
            ];
        }
        if (! $this->db->fieldExists('specialization_changed_at', 'characters')) {
            $cols['specialization_changed_at'] = [
                'type'  => 'DATETIME',
                'null'  => true,
                'default' => null,
            ];
        }
        if ($cols !== []) {
            $this->forge->addColumn('characters', $cols);
        }
    }

    public function down(): void
    {
        foreach (['specialization', 'specialization_changed_at'] as $col) {
            if ($this->db->fieldExists($col, 'characters')) {
                $this->forge->dropColumn('characters', $col);
            }
        }
    }
}
