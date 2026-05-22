<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-041 — 2 GameSettings для ремонта и per-level scaling оборонных зданий
 * (constitutional admin-tunable, ADR-024). category=combat. Idempotent.
 *
 *  - defense.scaling.per_level_percent: на сколько % за уровень растёт боевой эффект
 *    (редукция/контрурон/инициатива) И maxHp оборонной структуры. Формула множителя
 *    levelMult = 1 + p%×(level−1) → ×1.0 при L1 (no-op для существующих фикстур).
 *  - defense.repair.cost_fraction: доля build-ресурсов за ПОЛНЫЙ ремонт; реальная
 *    стоимость = ceil(ресурсы × cost_fraction × доля_потерянного_hp). Мгновенный ремонт.
 */
class ADR041SeedDefenseScalingRepairGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'defense.scaling.per_level_percent',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'На сколько % за каждый уровень оборонной структуры растёт её боевой эффект (снижение урона / контрурон / инициатива) И максимальный hp. Множитель levelMult = 1 + 0.20×(level−1): L1 ×1.0 (база), L2 ×1.2, L3 ×1.4, L10 ×2.8. 20% — апгрейд заметен, но не ломает (снижение урона всё равно под cap 40%).',
                'effect_text'        => 'DefenseStructureService умножает per-structure вклад и maxHp на levelMult(level). При L1 множитель ×1.0 — изменений нет (no-op). Cap defense.total_damage_reduction_max_percent применяется ПОСЛЕ scaling.',
                'above_effect_text'  => 'При 50%+ высокоуровневая оборона очень крепка (maxHp и контрурон/инициатива большие; снижение урона упирается в cap) → перекос к defender на эндгейме.',
                'below_effect_text'  => 'При 0 апгрейд снова ничего не даёт (множитель всегда ×1.0) — возвращается проблема «апгрейд обороны бесполезен».',
                'recommended_min'    => '5',
                'recommended_max'    => '30',
                'hard_min'           => '0',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'defense.repair.cost_fraction',
                'value_type'         => 'float',
                'value_float'        => 0.50,
                'default_value_text' => '0.50',
                'rationale_text'     => 'Доля build-ресурсов (raw resources рецепта) за ПОЛНЫЙ ремонт оборонной структуры. Реальная стоимость = ceil(ресурсы × 0.50 × доля_потерянного_hp). 0.50 — зеркало ремонта инструментов (repair.cost_fraction); ремонт ощутимый sink, но дешевле повторной постройки.',
                'effect_text'        => 'RepairBuildingAction считает стоимость пропорционально потерянному hp и мгновенно восстанавливает hp до maxHp. 0.50 = полный ремонт сломанной структуры стоит половину её build-ресурсов.',
                'above_effect_text'  => 'При 1.0+ ремонт = полная build-цена → дешевле снести и построить заново, ремонт теряет смысл.',
                'below_effect_text'  => 'При 0.1 оборона почти вечна (ремонт копеечный) → decay-sink обнуляется, защита фактически бесплатна после постройки.',
                'recommended_min'    => '0.2',
                'recommended_max'    => '0.8',
                'hard_min'           => '0',
                'hard_max'           => '3',
            ],
        ];

        $defaults = [
            'category'        => 'combat',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'defense.scaling.per_level_percent',
            'defense.repair.cost_fraction',
        ])->delete();
    }
}
