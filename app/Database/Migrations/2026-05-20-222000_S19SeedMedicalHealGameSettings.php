<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S19 (v0.51.201) — seed GameSettings heal-значений для drug-предметов
 * (constitutional admin-tunable balance, ADR-024; ROADMAP-CRAFT §7 Фаза 4 4/5).
 *
 * **Контекст / drift-находка (2026-05-20):** эффект применения медикаментов
 * (`UsePharmacyAction`) был ЗАХАРДКОЖЕН в switch на 8 предметов. Новые drug-и
 * не лечили (падали в default «препарат не найден»). Два orphan-предмета в БД
 * (headache_tablets, «Common cold tincture») были именно в этом состоянии.
 *
 * S19 вводит **data-driven ветку**: `UsePharmacyAction` для предметов с ключами
 * `medical.<snake_name_eng>.heal_health` / `.heal_tired` читает эффект из
 * GameSettings (admin-tunable). Legacy 8 (без ключей) продолжают через switch.
 *
 * Seeds 5 предметов × 2 ключа = 10 строк:
 *   - 3 новых T3: synthetic_medicine / emergency_transfusion / surgical_kit
 *   - 2 orphan-фикса: headache_tablets / common_cold_tincture (значения = их
 *     прежний character_boost-дисплей, теперь дисплей = реальный эффект)
 *
 * Категория `combat` — восстановление HP/выносливости (canonical category из
 * CLAUDE.md §🎛️; новая в БД, появится в admin sidebar динамически).
 *
 * Constitutional rule: rationale_text / effect_text / above_effect_text /
 * below_effect_text NOT NULL. Idempotent: пропускает существующие setting_key.
 */
class S19SeedMedicalHealGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            // ── SyntheticMedicine (L20, премиум full-restore) ──
            [
                'setting_key'        => 'medical.synthetic_medicine.heal_health',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Полное восстановление HP — это смысл предмета «на крайний случай» (T3, L20, дорогой синтез). 100 закрывает любой урон до cap.',
                'effect_text'        => 'UsePharmacyAction для SyntheticMedicine добавляет это к health (cap 100). Дисплей в PharmacyAction — из character_boost.',
                'above_effect_text'  => 'Выше 100 — бессмысленно (health всё равно ограничен 100).',
                'below_effect_text'  => 'Ниже 100 — премиум-хилка теряет уникальность full-restore, сравнивается с дешёвыми T1/T2.',
            ],
            [
                'setting_key'        => 'medical.synthetic_medicine.heal_tired',
                'value_int'          => 60,
                'default_value_text' => '60',
                'rationale_text'     => 'Сильное снятие усталости (60) усиливает роль «полного восстановления»: бодрит вместе с лечением.',
                'effect_text'        => 'UsePharmacyAction для SyntheticMedicine добавляет это к tired (cap 100).',
                'above_effect_text'  => 'Выше 60 — почти всегда мгновенно докидывает до cap, обесценивает успокоительные/стимуляторы.',
                'below_effect_text'  => 'Ниже 60 — full-restore лечит, но не бодрит, теряет «всё-в-одном» позиционирование.',
            ],

            // ── EmergencyTransfusion (L18, экстренный +80 HP) ──
            [
                'setting_key'        => 'medical.emergency_transfusion.heal_health',
                'value_int'          => 80,
                'default_value_text' => '80',
                'rationale_text'     => '+80 HP — «скорая помощь»: мощнее обычной аптечки (FirstAidKit +40), но не full-restore. Чёткий шаг прогрессии T3.',
                'effect_text'        => 'UsePharmacyAction для EmergencyTransfusion добавляет это к health (cap 100).',
                'above_effect_text'  => 'Выше 80 — приближается к full-restore, стирает грань с SyntheticMedicine.',
                'below_effect_text'  => 'Ниже 80 — не отличается от T2-аптечек, незачем гейтить за T3-верстаком.',
            ],
            [
                'setting_key'        => 'medical.emergency_transfusion.heal_tired',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'Небольшое +20 к выносливости — переливание восстанавливает в основном HP, лёгкий тонизирующий эффект.',
                'effect_text'        => 'UsePharmacyAction для EmergencyTransfusion добавляет это к tired (cap 100).',
                'above_effect_text'  => 'Выше 20 — превращает «кровь» в стимулятор, размывает специализацию.',
                'below_effect_text'  => 'Ниже 20 — почти ноль по выносливости (допустимо, но теряется приятный бонус).',
            ],

            // ── SurgicalKit (L22, multi-use ×3) ──
            [
                'setting_key'        => 'medical.surgical_kit.heal_health',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => '+50 HP за применение × 3 заряда = 150 HP суммарно. Самый эффективный по итогу, оправдывает L22 и цену.',
                'effect_text'        => 'UsePharmacyAction для SurgicalKit добавляет это к health (cap 100) за каждое из 3 применений (durability_count).',
                'above_effect_text'  => 'Выше 50 — multi-use даёт слишком много суммарного хила, доминирует над одноразовыми.',
                'below_effect_text'  => 'Ниже 50 — multi-use перестаёт окупать высокий уровень/цену против EmergencyTransfusion.',
            ],
            [
                'setting_key'        => 'medical.surgical_kit.heal_tired',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => '+30 к выносливости за применение — хирургия + перевязка немного восстанавливает силы.',
                'effect_text'        => 'UsePharmacyAction для SurgicalKit добавляет это к tired (cap 100) за каждое применение.',
                'above_effect_text'  => 'Выше 30 — суммарно ×3 быстро докидывает усталость до cap, обесценивает успокоительные.',
                'below_effect_text'  => 'Ниже 30 — теряется вспомогательный тонизирующий эффект многоразового набора.',
            ],

            // ── orphan-фикс: headache_tablets (значения = прежний дисплей) ──
            [
                'setting_key'        => 'medical.headache_tablets.heal_health',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Значение = прежний character_boost-дисплей (health 10), теперь предмет реально лечит (раньше падал в default). Слабая бытовая таблетка.',
                'effect_text'        => 'UsePharmacyAction для headache_tablets добавляет это к health (cap 100). Multi-use (durability 3).',
                'above_effect_text'  => 'Выше 10 — дешёвая бытовая таблетка начинает конкурировать с крафтовыми аптечками.',
                'below_effect_text'  => 'Ниже 10 — эффект почти неощутим, предмет снова станет бесполезным.',
            ],
            [
                'setting_key'        => 'medical.headache_tablets.heal_tired',
                'value_int'          => 12,
                'default_value_text' => '12',
                'rationale_text'     => 'Значение = прежний дисплей (tired 12). Таблетка от головной боли немного бодрит.',
                'effect_text'        => 'UsePharmacyAction для headache_tablets добавляет это к tired (cap 100).',
                'above_effect_text'  => 'Выше 12 — бытовой предмет даёт неоправданно много выносливости.',
                'below_effect_text'  => 'Ниже 12 — теряется тонизирующий эффект.',
            ],

            // ── orphan-фикс: Common cold tincture (значения = прежний дисплей) ──
            [
                'setting_key'        => 'medical.common_cold_tincture.heal_health',
                'value_int'          => 45,
                'default_value_text' => '45',
                'rationale_text'     => 'Значение = прежний character_boost-дисплей (health 45), теперь предмет реально лечит. Народная настойка средней силы.',
                'effect_text'        => 'UsePharmacyAction для «Common cold tincture» добавляет это к health (cap 100).',
                'above_effect_text'  => 'Выше 45 — настойка приближается к крафтовым T2/T3 хилкам без их стоимости.',
                'below_effect_text'  => 'Ниже 45 — теряет нишу «средней» хилки.',
            ],
            [
                'setting_key'        => 'medical.common_cold_tincture.heal_tired',
                'value_int'          => 36,
                'default_value_text' => '36',
                'rationale_text'     => 'Значение = прежний дисплей (tired 36). Тёплая настойка ощутимо снимает усталость.',
                'effect_text'        => 'UsePharmacyAction для «Common cold tincture» добавляет это к tired (cap 100).',
                'above_effect_text'  => 'Выше 36 — слишком сильное восстановление выносливости для дешёвой настойки.',
                'below_effect_text'  => 'Ниже 36 — теряется согревающий тонизирующий эффект.',
            ],
        ];

        $shared = [
            'category'        => 'combat',
            'value_type'      => 'int',
            'recommended_min' => '0',
            'recommended_max' => '100',
            'hard_min'        => '-100',
            'hard_max'        => '100',
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
                'medical.synthetic_medicine.heal_health',
                'medical.synthetic_medicine.heal_tired',
                'medical.emergency_transfusion.heal_health',
                'medical.emergency_transfusion.heal_tired',
                'medical.surgical_kit.heal_health',
                'medical.surgical_kit.heal_tired',
                'medical.headache_tablets.heal_health',
                'medical.headache_tablets.heal_tired',
                'medical.common_cold_tincture.heal_health',
                'medical.common_cold_tincture.heal_tired',
            ])
            ->delete();
    }
}
