<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-095 Фаза 1b — лимит построек на ячейку (admin-tunable). GameSettings
 * `buildings.cells.max_buildings_per_cell`.
 *
 * Отдельная миграция (1a-миграция 2026-06-25 уже применена на проде → правка её
 * не пересеется). Idempotent. Rich rationale (ADR-024). Не создаёт таблиц/player-
 * колонок → WipeManifest не трогаем (game_settings уже KEEP).
 */
class Adr095bSeedMaxBuildingsPerCell extends Migration
{
    public function up(): void
    {
        $key = 'buildings.cells.max_buildings_per_cell';
        $exists = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'buildings',
            'value_type'         => 'int',
            'value_int'          => 20,
            'value_float'        => null,
            'value_bool'         => null,
            'value_string'       => null,
            'default_value_text' => '20',
            'rationale_text'     => 'Максимум построек на одну ячейку (базу). 20 — на проде макс ~14/ячейку (avg 7), запас не ломает существующих игроков. Лимит из ТЗ «количество единиц построек на одну ячейку».',
            'effect_text'        => 'GenericBuildingAction блокирует старт постройки, если на активной базе уже built + in-flight ≥ это число.',
            'above_effect_text'  => 'Выше (30+): базы превращаются в плотные мега-кластеры → дисбаланс обороны/бафов, перегрузка экрана базы.',
            'below_effect_text'  => 'Ниже (≤ текущего макс 14): часть игроков мгновенно «над лимитом» и не сможет достраивать — держи ≥ реального максимума на проде.',
            'recommended_min'    => '10',
            'recommended_max'    => '30',
            'hard_min'           => '1',
            'hard_max'           => '100',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'buildings.cells.max_buildings_per_cell')
            ->delete();
    }
}
