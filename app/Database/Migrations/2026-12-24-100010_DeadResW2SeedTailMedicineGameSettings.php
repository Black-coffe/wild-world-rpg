<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * D-DEAD-RES волна 2 — лечение пяти полевых лекарств хвоста (ADR-024).
 *
 * Ключи `medical.<snake_name_eng>.heal_health` / `.heal_tired` читает data-driven ветка
 * `UsePharmacyAction::resolveEffectsFromSettings()` (S19); карточка рецепта
 * (`MedicalRecipePreviewT3Action`) печатает те же числа. Границы — как у волны 1.
 *
 * Опоры первого верстака: Повязка +2/+1, Регенератор +30/+20, Базовая аптечка +40/+20,
 * волна 1: Сыворотка 25/0, Пластырь 18/5, Мазь 10/20, Таблетки 5/30. Укол — потолок
 * верстака по здоровью (40), но бодрит слабее аптечки.
 *
 * Idempotent: пропускает существующие setting_key.
 */
class DeadResW2SeedTailMedicineGameSettings extends Migration
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
        // ── SealBalm (L6: Масло тюленя 6 · Арктические цветы 3 · Лед 10) ──
        [
            'setting_key'        => 'medical.seal_balm.heal_health',
            'value_int'          => 12,
            'default_value_text' => '12',
            'rationale_text'     => 'Бальзам прежде всего согревает и бодрит; лечение — вспомогательное, чуть выше Мази.',
            'effect_text'        => 'UsePharmacyAction для SealBalm добавляет это к здоровью (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 20 — бальзам лечит как Пластырь и бодрит сильнее, вытесняя оба.',
            'below_effect_text'  => 'При 0 — чистый тоник, допустимо.',
        ],
        [
            'setting_key'        => 'medical.seal_balm.heal_tired',
            'value_int'          => 25,
            'default_value_text' => '25',
            'rationale_text'     => '+25 к выносливости — между Мазью (20) и Таблетками (30): у льдов свой тоник средней силы.',
            'effect_text'        => 'UsePharmacyAction для SealBalm добавляет это к выносливости (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 30 — бальзам L6 обгоняет Таблетки L7.',
            'below_effect_text'  => 'Ниже 15 — три ресурса льдов за слабый тоник невыгодны, сырьё снова копится.',
        ],

        // ── ThermalCulture (L5: Термофильные микроорганизмы 6 · Обсидиан 5) ──
        [
            'setting_key'        => 'medical.thermal_culture.heal_health',
            'value_int'          => 8,
            'default_value_text' => '8',
            'rationale_text'     => 'Закваска почти не лечит: её дело — выносливость.',
            'effect_text'        => 'UsePharmacyAction для ThermalCulture добавляет это к здоровью (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 15 — закваска становится универсальной и вытесняет Мазь.',
            'below_effect_text'  => 'При 0 — чистый тоник, допустимо.',
        ],
        [
            'setting_key'        => 'medical.thermal_culture.heal_tired',
            'value_int'          => 28,
            'default_value_text' => '28',
            'rationale_text'     => '+28 к выносливости — сильный тоник уровня 5, почти вровень с Таблетками, но без редких руд во входе.',
            'effect_text'        => 'UsePharmacyAction для ThermalCulture добавляет это к выносливости (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 30 — дешёвая закваска L5 обгоняет Таблетки L7, их перестают варить.',
            'below_effect_text'  => 'Ниже 15 — закваска не лучше Мази, микроорганизмы тратить незачем.',
        ],

        // ── CaveTallow (L4: Подземные животные 6 · Сталактиты и сталагмиты 5) ──
        [
            'setting_key'        => 'medical.cave_tallow.heal_health',
            'value_int'          => 22,
            'default_value_text' => '22',
            'rationale_text'     => 'Жир — лечащее средство пещер: сильнее Пластыря (18), слабее Сыворотки (25).',
            'effect_text'        => 'UsePharmacyAction для CaveTallow добавляет это к здоровью (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 25 — жир L4 догоняет Сыворотку L5 и обесценивает её.',
            'below_effect_text'  => 'Ниже 12 — жир почти равен Повязке, шесть подземных животных тратить незачем.',
        ],
        [
            'setting_key'        => 'medical.cave_tallow.heal_tired',
            'value_int'          => 8,
            'default_value_text' => '8',
            'rationale_text'     => 'Небольшой бонус к выносливости, не превращает жир в тоник.',
            'effect_text'        => 'UsePharmacyAction для CaveTallow добавляет это к выносливости (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 15 — жир становится «всё-в-одном» на L4.',
            'below_effect_text'  => 'При 0 — допустимо, жир просто лечит.',
        ],

        // ── DesertSalt (L15: Миражные кристаллы 4 · Пустынный агат 3 · Фоссилии 1 · Драгоценные камни 1) ──
        [
            'setting_key'        => 'medical.desert_salt.heal_health',
            'value_int'          => 10,
            'default_value_text' => '10',
            'rationale_text'     => 'Соль почти не лечит: её дело — выносливость на жаре.',
            'effect_text'        => 'UsePharmacyAction для DesertSalt добавляет это к здоровью (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 20 — соль становится универсальной и вытесняет тоники ниже уровнем.',
            'below_effect_text'  => 'При 0 — чистый тоник, допустимо.',
        ],
        [
            'setting_key'        => 'medical.desert_salt.heal_tired',
            'value_int'          => 35,
            'default_value_text' => '35',
            'rationale_text'     => '+35 к выносливости — самый сильный тоник первого верстака: L15 и четыре ресурса пустыни во входе.',
            'effect_text'        => 'UsePharmacyAction для DesertSalt добавляет это к выносливости (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 40 — соль спорит с медициной профессионального верстака.',
            'below_effect_text'  => 'Ниже 25 — соль не лучше Таблеток L7, пустынное сырьё тратить незачем.',
        ],

        // ── PainkillerShot (L18: Медицинское сырьё 1 · Яд животных 4 · Природный газ 2) ──
        [
            'setting_key'        => 'medical.painkiller_shot.heal_health',
            'value_int'          => 40,
            'default_value_text' => '40',
            'rationale_text'     => 'Укол — потолок лечения первого верстака (решение владельца 2026-11-11): вровень с Базовой аптечкой, из самого дорогого сырья хвоста.',
            'effect_text'        => 'UsePharmacyAction для PainkillerShot добавляет это к здоровью (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 40 — укол обгоняет аптечку и спорит с медициной профессионального верстака.',
            'below_effect_text'  => 'Ниже 25 — укол не лучше Сыворотки L5, медицинское сырьё выгоднее продать банку.',
        ],
        [
            'setting_key'        => 'medical.painkiller_shot.heal_tired',
            'value_int'          => 15,
            'default_value_text' => '15',
            'rationale_text'     => '+15 к выносливости — меньше аптечки (20): укол — средство от боли, не тоник.',
            'effect_text'        => 'UsePharmacyAction для PainkillerShot добавляет это к выносливости (cap 100). Карточка рецепта в «⚕️ Медикаментах» печатает это число.',
            'above_effect_text'  => 'Выше 20 — укол во всём лучше аптечки, её перестают варить.',
            'below_effect_text'  => 'Ниже 5 — допустимо, укол просто сильно лечит.',
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
