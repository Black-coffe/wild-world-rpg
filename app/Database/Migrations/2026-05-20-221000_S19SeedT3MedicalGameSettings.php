<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S19 (v0.51.201) — seed 3 GameSettings keys для T3 medical craft durations
 * (ADR-026 reusable pattern, ROADMAP-CRAFT §7 Фаза 4 4/5).
 *
 * Каждое T3 medical — admin-tunable длительность через
 * `tier3.medical.<key>.craft_duration_hours`:
 *   - synthetic_medicine    = 3h  (L20 премиум full-restore)
 *   - emergency_transfusion = 2h  (L18 экстренный +80 HP)
 *   - surgical_kit          = 4h  (L22 multi-use ×3)
 *
 * Wire-up: GenericCraftActionStart::calculateCraftingDuration читает ключ
 * через recipe.duration_override_setting_key (ADR-026). Fallback на
 * tasks.min/max_duration если ключ отсутствует.
 *
 * Constitutional rule (CLAUDE.md §🎛️, ADR-024): rationale_text /
 * effect_text / above_effect_text / below_effect_text NOT NULL.
 *
 * Idempotent: пропускает существующие setting_key.
 */
class S19SeedT3MedicalGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tier3.medical.synthetic_medicine.craft_duration_hours',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => '3 часа для L20 премиум full-restore — это «панический» предмет на крайний случай (health→100). 3h делает его осознанным запасом, а не спамом перед каждым боем. Дороже по времени, чем переливание (2h), дешевле «титанового» крафта.',
                'effect_text'        => 'Длительность task craftSyntheticMedicine (override tasks.min/max_duration). GenericCraftActionStart::calculateCraftingDuration читает через recipe.duration_override_setting_key.',
                'above_effect_text'  => 'При 6h — full-restore становится «sleep-on-it», игроки забросят его в пользу более быстрых хилок и купят аптечки T1/T2.',
                'below_effect_text'  => 'При 1h — full-heal превращается в дешёвый расходник, обесценивает риск смерти и существующие T1/T2 медикаменты.',
            ],
            [
                'setting_key'        => 'tier3.medical.emergency_transfusion.craft_duration_hours',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => '2 часа для L18 экстренного +80 HP — entry-level T3 medical (parity с GaussPistol/Tactical entry-time). Самый быстрый T3-медикамент: «скорая помощь», которую можно заготовить за вечер.',
                'effect_text'        => 'Длительность task craftEmergencyTransfusion (override tasks.min/max_duration). См. wire-up в synthetic_medicine.',
                'above_effect_text'  => 'При 4h — теряет роль быстрой заготовки, игроки выберут только full-restore или surgical kit.',
                'below_effect_text'  => 'При 1h — слишком дёшево по времени для +80 HP, ломает прогрессию «время = сила хилки».',
            ],
            [
                'setting_key'        => 'tier3.medical.surgical_kit.craft_duration_hours',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => '4 часа для L22 multi-use (×3) — самый эффективный медикамент по суммарному хилу (150 HP за 3 применения). 4h оправдывают эффективность и более высокий уровень/цену.',
                'effect_text'        => 'Длительность task craftSurgicalKit (override tasks.min/max_duration). См. wire-up в synthetic_medicine.',
                'above_effect_text'  => 'При 8h — overnight commit; multi-use перестанет окупаться против заготовки нескольких одноразовых.',
                'below_effect_text'  => 'При 2h — multi-use × дешёвое время = доминирующий выбор, убивает смысл одноразовых хилок.',
            ],
        ];

        $shared = [
            'category'        => 'craft',
            'value_type'      => 'int',
            'recommended_min' => '2',
            'recommended_max' => '12',
            'hard_min'        => '1',
            'hard_max'        => '48',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'tier3.medical.synthetic_medicine.craft_duration_hours',
                'tier3.medical.emergency_transfusion.craft_duration_hours',
                'tier3.medical.surgical_kit.craft_duration_hours',
            ])
            ->delete();
    }
}
