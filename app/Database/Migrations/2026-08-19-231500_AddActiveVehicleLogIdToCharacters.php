<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * transport-03 (ADR-174 §2, план `docs/specs/transport-system/plan.md` → Contracts) —
 * `characters.active_vehicle_log_id`: nullable указатель на строку `crafted_items_log`
 * активной единицы транспорта. Инвариант «активна максимум одна машина» держится
 * СТРУКТУРНО (одно поле — одно значение), а не отдельным флагом на строке лога.
 *
 * Намеренно БЕЗ FK/каскада (Non-goal story): висячий указатель (строка лога удалена)
 * лечится чтением в `VehicleActivationService::resolveActive()`, а не схемой.
 *
 * ⚠ ОБЯЗАТЕЛЬНО в `$allowedFields` CharacterModel — иначе Model::update молча
 * отфильтрует поле (грабля disable_media/last_planted_crop/node_announce_enabled).
 *
 * WipeManifest: `characters` уже CHARACTER_RESET; колонка добавлена в
 * `characterResetValues` = null — это ПРОГРЕСС (какая машина активна сейчас), а не
 * преференс, поэтому сбрасывается при вайпе (как combat_drone_active_until).
 */
class AddActiveVehicleLogIdToCharacters extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('active_vehicle_log_id', 'characters')) {
            $this->forge->addColumn('characters', [
                'active_vehicle_log_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'node_announce_enabled',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('active_vehicle_log_id', 'characters')) {
            $this->forge->dropColumn('characters', 'active_vehicle_log_id');
        }
    }
}
