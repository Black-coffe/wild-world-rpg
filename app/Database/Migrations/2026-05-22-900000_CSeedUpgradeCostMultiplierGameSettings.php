<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * C (ADR-043) — per-building множитель цены апгрейда зданий (constitutional admin-tunable,
 * ADR-024). category=economy, value_type=float. Idempotent.
 *
 * Единая шкала BuildingUpgrades × множитель (только золото). Спред по РЕАЛЬНОЙ ценности
 * апгрейда (после ADR-042): высокая ×1.3, стандарт ×1.0, низкая/нулевая ×0.6.
 * BuildingUpgradeValidator читает economy.upgrade.cost_multiplier.<lower_name_en> (default 1.0).
 */
class CSeedUpgradeCostMultiplierGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [lower_name_en => [multiplier, описание ценности]]
        $buildings = [
            // высокая ценность ×1.3 (дороже — получаешь много)
            'workshop'            => [1.3, 'высокая: ускоряет ВЕСЬ крафт (−25%→−45%)'],
            'greenhouse'          => [1.3, 'высокая: производство еды (пассив + активная посадка)'],
            'teleportationcenter' => [1.3, 'высокая: снижение цены телепорта + лимит маяков'],
            // стандарт ×1.0 (реальный per-level эффект)
            'laboratory'          => [1.0, 'стандарт: ускорение медицинского крафта'],
            'roboticsworkshop'    => [1.0, 'стандарт: ускорение крафта роботов'],
            'handpump'            => [1.0, 'стандарт: добыча воды растёт с уровнем'],
            'gym'                 => [1.0, 'стандарт: прибавка силы по уровню'],
            'communicationtower'  => [1.0, 'стандарт: радиус роботов +100/уровень'],
            'woodenwall'          => [1.0, 'стандарт: оборона масштабируется (ADR-041)'],
            'barbedfence'         => [1.0, 'стандарт: оборона масштабируется (ADR-041)'],
            'watchtower'          => [1.0, 'стандарт: оборона масштабируется (ADR-041)'],
            // низкая/нулевая ценность ×0.6 (дешевле — узко или апгрейд без эффекта)
            'blastfurnace'        => [0.6, 'низкая: бустит лишь 1 рецепт (MetalFragments)'],
            'warehouse'           => [0.6, 'нулевая: апгрейд не даёт эффекта (только гейт рынка)'],
            'solarstation'        => [0.6, 'нулевая: апгрейд не даёт эффекта (только prereq Арсенала)'],
            'arsenal'             => [0.6, 'нулевая: апгрейд не даёт эффекта (только хранение/экип)'],
        ];

        $defaults = [
            'category'        => 'economy',
            'value_type'      => 'float',
            'value_int'       => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => '0.5',
            'recommended_max' => '2.0',
            'hard_min'        => '0.1',
            'hard_max'        => '10',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($buildings as $nameEn => [$mult, $desc]) {
            $key = 'economy.upgrade.cost_multiplier.' . $nameEn;
            $exists = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, [
                'setting_key'        => $key,
                'value_float'        => $mult,
                'default_value_text' => (string) $mult,
                'rationale_text'     => "Множитель ЗОЛОТА цены апгрейда здания «{$nameEn}» ({$desc}). Базовая шкала BuildingUpgrades × это. 1.0 = базовая цена.",
                'effect_text'        => 'BuildingUpgradeValidator множит $req[gold] на это значение перед проверкой/показом/списанием. Ресурсы не затрагиваются.',
                'above_effect_text'  => 'Выше → апгрейд этого здания дороже золотом.',
                'below_effect_text'  => 'Ниже → апгрейд дешевле; при <0.5 почти бесплатно.',
            ]));
        }
    }

    public function down(): void
    {
        $keys = [];
        foreach ([
            'workshop', 'greenhouse', 'teleportationcenter', 'laboratory', 'roboticsworkshop',
            'handpump', 'gym', 'communicationtower', 'woodenwall', 'barbedfence', 'watchtower',
            'blastfurnace', 'warehouse', 'solarstation', 'arsenal',
        ] as $nameEn) {
            $keys[] = 'economy.upgrade.cost_multiplier.' . $nameEn;
        }
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
