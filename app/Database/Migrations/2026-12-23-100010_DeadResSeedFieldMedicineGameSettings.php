<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * D-DEAD-RES — лечение четырёх полевых лекарств первого верстака (ADR-024).
 *
 * Ключи `medical.<snake_name_eng>.heal_health` / `.heal_tired` читает data-driven
 * ветка `UsePharmacyAction::resolveEffectsFromSettings()` (S19) — Аптечка и Провизия
 * применяют предмет без правки switch'а. Карточка рецепта в боте
 * (`MedicalRecipePreviewT3Action`) печатает те же числа.
 *
 * Опоры первого верстака: Повязка +2/+1, Регенератор +30/+20, Базовая аптечка +40/+20.
 * Новые — узкие: одно лечит, другое бодрит, ни одно не перерастает аптечку.
 *
 * Idempotent: пропускает существующие setting_key.
 */
class DeadResSeedFieldMedicineGameSettings extends Migration
{
    /**
     * @var array{category:string,value_type:string,recommended_min:string,recommended_max:string,hard_min:string,hard_max:string}
     */
    public const SHARED = [
        'category'        => 'combat',
        'value_type'      => 'int',
        'recommended_min' => '0',
        'recommended_max' => '40',
        'hard_min'        => '0',
        'hard_max'        => '100',
    ];

    /**
     * @var list<array{setting_key:string,value_int:int,default_value_text:string,rationale_text:string,effect_text:string,above_effect_text:string,below_effect_text:string}>
     */
    public const ROWS = [
        // ── Antivenom (L5): Змеиный яд 6 · Снег 10 ──
        [
            'setting_key'        => 'medical.antivenom.heal_health',
            'value_int'          => 25,
            'default_value_text' => '25',
            'rationale_text'     => 'Сыворотка — лечащее средство первого верстака: сильнее Повязки, слабее Регенератора (30) и Базовой аптечки (40). Даёт змеиному яду смысл копить.',
            'effect_text'        => 'UsePharmacyAction для Antivenom добавляет это к здоровью (cap 100). Карточка рецепта в «💊 Лекарствах» печатает это число.',
            'above_effect_text'  => 'Выше 30 — дешёвая по сырью сыворотка обгоняет Регенератор и аптечку, их перестают варить.',
            'below_effect_text'  => 'Ниже 15 — шесть единиц яда за слабое лечение невыгодны, яд снова копится без дела.',
        ],
        [
            'setting_key'        => 'medical.antivenom.heal_tired',
            'value_int'          => 0,
            'default_value_text' => '0',
            'rationale_text'     => 'Сыворотка не бодрит: специализация — здоровье, выносливость закрывают мазь и таблетки.',
            'effect_text'        => 'UsePharmacyAction для Antivenom добавляет это к выносливости (cap 100).',
            'above_effect_text'  => 'Выше 10 — сыворотка становится универсальной и вытесняет мазь и таблетки.',
            'below_effect_text'  => 'Ниже 0 нельзя (hard_min 0): лекарство не должно утомлять.',
        ],

        // ── ChitinPatch (L2): Тропические насекомые 12 · Капельники 4 ──
        [
            'setting_key'        => 'medical.chitin_patch.heal_health',
            'value_int'          => 18,
            'default_value_text' => '18',
            'rationale_text'     => 'Пластырь — ранний доступный хил (L2) из самого массового сырья: заметно сильнее Повязки, слабее сыворотки.',
            'effect_text'        => 'UsePharmacyAction для ChitinPatch добавляет это к здоровью (cap 100).',
            'above_effect_text'  => 'Выше 25 — пластырь L2 догоняет сыворотку L5 и обесценивает её.',
            'below_effect_text'  => 'Ниже 10 — пластырь равен Повязке, двенадцать насекомых тратить незачем.',
        ],
        [
            'setting_key'        => 'medical.chitin_patch.heal_tired',
            'value_int'          => 5,
            'default_value_text' => '5',
            'rationale_text'     => 'Небольшой бонус к выносливости — приятный, но не превращает пластырь в стимулятор.',
            'effect_text'        => 'UsePharmacyAction для ChitinPatch добавляет это к выносливости (cap 100).',
            'above_effect_text'  => 'Выше 15 — пластырь становится «всё-в-одном» на L2 и вытесняет мазь.',
            'below_effect_text'  => 'При 0 — допустимо, пластырь просто чистый хил.',
        ],

        // ── SulfurOintment (L5): Сера 5 · Вулканический пепел 8 ──
        [
            'setting_key'        => 'medical.sulfur_ointment.heal_health',
            'value_int'          => 10,
            'default_value_text' => '10',
            'rationale_text'     => 'Мазь в первую очередь снимает усталость; здоровье — вспомогательное.',
            'effect_text'        => 'UsePharmacyAction для SulfurOintment добавляет это к здоровью (cap 100).',
            'above_effect_text'  => 'Выше 20 — мазь лечит наравне с пластырем и бодрит сильнее, становится доминирующей.',
            'below_effect_text'  => 'При 0 — мазь чистый тоник, допустимо.',
        ],
        [
            'setting_key'        => 'medical.sulfur_ointment.heal_tired',
            'value_int'          => 20,
            'default_value_text' => '20',
            'rationale_text'     => '+20 к выносливости — уровень Регенератора и аптечки по бодрости, но без их лечения.',
            'effect_text'        => 'UsePharmacyAction для SulfurOintment добавляет это к выносливости (cap 100).',
            'above_effect_text'  => 'Выше 30 — мазь догоняет таблетки L7 и обесценивает их.',
            'below_effect_text'  => 'Ниже 10 — сера и пепел снова не стоят крафта.',
        ],

        // ── MineralTablets (L7): Редкие руды 3 · Капельники 3 · Снег 5 ──
        [
            'setting_key'        => 'medical.mineral_tablets.heal_health',
            'value_int'          => 5,
            'default_value_text' => '5',
            'rationale_text'     => 'Таблетки — тоник для долгого перехода; здоровье почти не трогают.',
            'effect_text'        => 'UsePharmacyAction для MineralTablets добавляет это к здоровью (cap 100).',
            'above_effect_text'  => 'Выше 15 — таблетки становятся универсальными и вытесняют мазь.',
            'below_effect_text'  => 'При 0 — чистый тоник, допустимо.',
        ],
        [
            'setting_key'        => 'medical.mineral_tablets.heal_tired',
            'value_int'          => 30,
            'default_value_text' => '30',
            'rationale_text'     => 'Самый сильный тоник первого верстака (L7, редкие руды во входе) — выше мази и аптечки по бодрости.',
            'effect_text'        => 'UsePharmacyAction для MineralTablets добавляет это к выносливости (cap 100).',
            'above_effect_text'  => 'Выше 40 — таблетки L7 спорят с медициной профессионального верстака.',
            'below_effect_text'  => 'Ниже 20 — таблетки не лучше мази, редкие руды тратить незачем.',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::ROWS as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge(self::SHARED, $row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', array_column(self::ROWS, 'setting_key'))
            ->delete();
    }
}
