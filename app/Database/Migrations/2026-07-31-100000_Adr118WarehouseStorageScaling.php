<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-118 (E18) — пороговые уровни ёмкости Склада (Warehouse), чтобы апгрейд L4-10 давал прирост.
 *
 * Аудит BUILDING_BUFFS_AUDIT: 7 зданий уже интерполируют L4-L9 (ADR-042), но Warehouse имел только
 * l1/l2/l3 → L4-L10 плато (cascade к L3=700кг). Сеем пороги l5/l8/l10 → видимые скачки ёмкости на
 * L5/L8/L10. Seed game_settings (KEEP). Idempotent.
 */
class Adr118WarehouseStorageScaling extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            ['building.warehouse.l5.storage_bonus_kg', 1100],
            ['building.warehouse.l8.storage_bonus_kg', 1700],
            ['building.warehouse.l10.storage_bonus_kg', 2500],
        ];
        foreach ($rows as $r) {
            if ($this->db->table('game_settings')->where('setting_key', $r[0])->countAllResults() === 0) {
                $this->db->table('game_settings')->insert([
                    'setting_key'        => $r[0],
                    'category'           => 'buildings',
                    'value_type'         => 'int',
                    'value_int'          => $r[1],
                    'default_value_text' => (string) $r[1],
                    'rationale_text'     => 'Пороговая ёмкость Склада на этом уровне (ADR-118, E18). Раньше Warehouse имел только l1/l2/l3 → L4-10 плато. Эти пороги дают прирост ёмкости на L5/L8/L10 (видимый смысл апгрейда).',
                    'effect_text'        => 'Бонус ёмкости склада (кг) на достижении этого уровня постройки.',
                    'above_effect_text'  => 'Выше: больше ёмкости — слабее давление веса (анти-синк).',
                    'below_effect_text'  => 'Ниже: меньше выигрыш от апгрейда склада.',
                    'recommended_min'    => '300', 'recommended_max' => '5000', 'hard_min' => '0', 'hard_max' => '100000',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'building.warehouse.l5.storage_bonus_kg',
            'building.warehouse.l8.storage_bonus_kg',
            'building.warehouse.l10.storage_bonus_kg',
        ])->delete();
    }
}
