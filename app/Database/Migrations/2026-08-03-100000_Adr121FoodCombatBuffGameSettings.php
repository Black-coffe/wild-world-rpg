<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E21 Ф1 (ROADMAP-100, ADR-121) — боевое измерение food-бафа «Сытость».
 *
 * Audit (прод 2026-06-12): дом-цикл (ферма V6/V7 + готовка V8 + рыбалка W23) построен,
 * но РАНТАЙМ-МЁРТВ — 0 чаров «сыты» сейчас, 8 кулинарных действий за 30 дней (все рыбные).
 * Корень: well_fed-баф даёт только −время крафта / +добычу — то, что ветеранам L70+
 * (9 чаров, аудитория гейта L70) НЕ нужно. Петля ADR-113 требует «сквозные блюда с бафами
 * для ОХОТЫ (E15)/севера (E16)», но боевой линковки НЕТ (ноль в app/Services/PVE).
 *
 * Решение Ф1: сытость даёт боевой бонус (PvE-only) — пока сыт, игрок бьёт сильнее и
 * получает меньше урона. Это РЕЮЗ всех существующих блюд (V8/W23) как охотничьего ресурса,
 * замыкает петлю дом-цикл→охота БЕЗ нового контента (как E10 реюзнул квест-движок).
 *
 *  - food.well_fed.combat_enabled — killswitch боевого измерения (default OFF → dormant,
 *    byte-identical: множители = 1.0, фикстуры боя не трогаются).
 *  - food.well_fed.combat_damage_multiplier (>1.0) — исходящий урон игрока, пока сыт.
 *  - food.well_fed.incoming_damage_multiplier (<1.0) — входящий урон по игроку, пока сыт.
 *
 * Эконом-инвариант П8: НЕ faucet золота. Готовка жрёт ингредиенты (существующий синк) —
 * ветераны получают ПРИЧИНУ фармить/готовить, дом-цикл оживает. category=craft (когезия с
 * остальными food.well_fed.* — админ находит все настройки еды в одном экране). Idempotent.
 */
class Adr121FoodCombatBuffGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'food.well_fed.combat_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch боевого измерения «Сытости» (ADR-121). false (default) — сытость влияет только на крафт/добычу (как до E21), бой не трогается. true — пока сыт, игрок в PvE бьёт сильнее и получает меньше урона → блюда становятся охотничьим ресурсом, дом-цикл кормит охоту (E15)/север (E16).',
                'effect_text'        => 'FoodBuffService::combatEnabled. true → PvEService::attack применяет к BattleCharacter игрока outgoingDamageMultiplier/incomingDamageMultiplier, если now < well_fed_until. PvE-only, NPC баф не получает.',
                'above_effect_text'  => 'true: боевая ось еды активна (готовь перед охотой на элиток/походом на север).',
                'below_effect_text'  => 'false: мгновенный откат боевого food-бафа без редеплоя; бой byte-identical (множители 1.0).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'food.well_fed.combat_damage_multiplier',
                'value_type'         => 'float',
                'value_float'        => 1.10,
                'default_value_text' => '1.10',
                'rationale_text'     => 'Множитель ИСХОДЯЩЕГО урона игрока в PvE, пока сыт (+10%). Сытый охотник бьёт сильнее. Скромный bonus — еда помогает, но не заменяет экип/уровень (анти-P2W, горизонтально).',
                'effect_text'        => 'DamageService::calculateDamage множит итоговый урон атакующего-игрока на этот коэффициент (через BattleCharacter::outgoingDamageMultiplier). Детерминир.',
                'above_effect_text'  => 'При 1.50 (+50%) — еда даёт слишком много урона, обесценивает прокачку оружия/силы.',
                'below_effect_text'  => 'При 1.02 (+2%) — прибавка незаметна в бою, готовить перед охотой не стоит.',
                'recommended_min'    => '1.05',
                'recommended_max'    => '1.25',
                'hard_min'           => '1.00',
                'hard_max'           => '2.00',
            ],
            [
                'setting_key'        => 'food.well_fed.incoming_damage_multiplier',
                'value_type'         => 'float',
                'value_float'        => 0.92,
                'default_value_text' => '0.92',
                'rationale_text'     => 'Множитель ВХОДЯЩЕГО урона по игроку в PvE, пока сыт (−8%). Сытый выживает дольше — помогает в охоте на элиток L30-50 и при выживании на опасном севере (E16/E17). Скромно, чтобы не делать сытого неубиваемым.',
                'effect_text'        => 'DamageService::calculateDamage множит урон, получаемый защищающимся-игроком, на этот коэффициент (через BattleCharacter::incomingDamageMultiplier). Детерминир., не опускает урон ниже базового пола (baseDamage×0.5).',
                'above_effect_text'  => 'При 0.99 (−1%) — защита незаметна, на выживаемость не влияет.',
                'below_effect_text'  => 'При 0.60 (−40%) — сытый почти неуязвим в PvE, ломает баланс опасности севера/элиток.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.97',
                'hard_min'           => '0.50',
                'hard_max'           => '1.00',
            ],
        ];

        $defaults = [
            'category'        => 'craft',
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
            'food.well_fed.combat_enabled',
            'food.well_fed.combat_damage_multiplier',
            'food.well_fed.incoming_damage_multiplier',
        ])->delete();
    }
}
