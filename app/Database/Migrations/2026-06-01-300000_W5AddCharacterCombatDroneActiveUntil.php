<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W5 (ADR-064) — ALTER characters ADD combat_drone_active_until DATETIME NULL.
 *
 * Lazy-expiry pattern (зеркало characters.well_fed_until / cell_until_explored).
 * NULL = нет активного combat-drone. NOW > combat_drone_active_until = expired,
 * нужна повторная активация (drain battery).
 *
 * CharacterModel::$allowedFields += combat_drone_active_until — обязательно для
 * UPDATE через model'ом (memory feedback_ci4_alter_column_needs_allowedfields).
 *
 * Idempotent через SHOW COLUMNS.
 */
class W5AddCharacterCombatDroneActiveUntil extends Migration
{
    public function up(): void
    {
        if ($this->columnExists('characters', 'combat_drone_active_until')) {
            return;
        }

        $this->forge->addColumn('characters', [
            'combat_drone_active_until' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'gold',
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->columnExists('characters', 'combat_drone_active_until')) {
            return;
        }
        $this->forge->dropColumn('characters', 'combat_drone_active_until');
    }

    private function columnExists(string $table, string $column): bool
    {
        $fields = $this->db->getFieldNames($table);
        return is_array($fields) && in_array($column, $fields, true);
    }
}
