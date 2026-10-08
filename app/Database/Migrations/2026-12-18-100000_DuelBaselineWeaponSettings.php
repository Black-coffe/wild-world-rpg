<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * duel-baseline-weapon (поправка ADR-071) — дуэль решает бой, а не стаж.
 *
 * Три новых ключа `pvp.duel.*` (категория `combat`): урон базового оружия, вес перевеса своего оружия,
 * уворот в дуэли. Читает {@see \App\Services\PVE\DuelService}, применяют `DuelService::equalize()` и
 * {@see \App\Services\PVE\DuelEquipmentRepository}. `pvp.duel.baseline_health` получает умолчание 200
 * вместо 1000 (прежний rationale «не марафон до 150 раундов» был неверен: без оружия за 150 раундов
 * снималось ≈ 230 HP), значение 1000 → 200 меняется только там, где его не правили из админки
 * (`updated_by IS NULL`). `pvp.duel.baseline_stat` больше не задаёт ловкость — тексты обновлены.
 *
 * Idempotent по `setting_key`. `game_settings` = KEEP (WipeManifest не трогаем).
 */
class DuelBaselineWeaponSettings extends Migration
{
    private const NEW_KEYS = ['pvp.duel.baseline_weapon_damage', 'pvp.duel.weapon_advantage_weight', 'pvp.duel.dodge_percent'];

    private const HEALTH_BEFORE = [
        'default_value_text' => '1000',
        'rationale_text'     => 'Здоровье, к которому нормализуются оба бойца в дуэли. 1000 — достаточно для нескольких раундов (видно отыгрыш билда), но не марафон до 150 раундов. Равно у обоих.',
        'effect_text'        => 'DuelService::equalize override health/max_health обоих к этому значению (+ tired=full, без tired-штрафа).',
        'above_effect_text'  => 'Выше → дольше бои (риск упереться в pvpMaxRounds=150 → exhausted-ничья).',
        'below_effect_text'  => 'Ниже → бои короче, выше дисперсия исхода (один удачный удар решает).',
        'recommended_min'    => '300',
        'recommended_max'    => '3000',
    ];

    private const HEALTH_AFTER = [
        'default_value_text' => '200',
        'rationale_text'     => 'Здоровье обоих бойцов в дуэли. 200 при базовом оружии 10 и увороте 40 % — нокаут у двух безоружных новичков примерно за 45 раундов из 150 (модель на 3000 боёв), разбор боя читается целиком. Прежние 1000 не снимались за 150 раундов вовсе — исход решал тай-брейк по стажу.',
        'effect_text'        => 'DuelService::equalize ставит health/max_health обоих бойцов к этому значению (+ tired=full). Своё здоровье игрока дуэль не трогает.',
        'above_effect_text'  => 'Выше 200 — бои дольше; около 1000 при базовом оружии 10 бой не доходит до нокаута за 150 раундов, и снова решает тай-брейк (остаток HP → билд → стаж).',
        'below_effect_text'  => 'Ниже 200 — бой в несколько ударов: исход решает первый удар и пара уворотов, сильное оружие кончает бой за 2–3 раунда.',
        'recommended_min'    => '100',
        'recommended_max'    => '1000',
    ];

    private const STAT_BEFORE = [
        'rationale_text' => 'Значение силы/ловкости/интеллекта, к которому нормализуются оба бойца. 50 — даёт ощутимые stats-бонусы (сила→урон, ловкость→уворот) без перекоса. Равны у обоих → решает билд (экип) + RNG.',
        'effect_text'    => 'DuelService::equalize override strength/agility/intellect обоих к этому значению.',
    ];

    private const STAT_AFTER = [
        'rationale_text' => 'Значение силы и интеллекта, к которому нормализуются оба бойца. 50 — сила даёт +5 % к урону без перекоса. Равны у обоих → решает билд (экип) + RNG. Ловкость (уворот) с duel-baseline-weapon задаёт pvp.duel.dodge_percent.',
        'effect_text'    => 'DuelService::equalize override strength/intellect обоих к этому значению. Ловкость не задаёт — её ставит pvp.duel.dodge_percent.',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'pvp.duel.baseline_weapon_damage',
                'value_float'        => 10.0,
                'default_value_text' => '10',
                'rationale_text'     => 'Урон базового оружия в дуэли: им бьёт безоружный боец и тот, чьё оружие (урон × редкость) слабее. 10 — середина Uncommon: всё Common (4–6) поднимается до базового, перевес начинается с Uncommon. Без базового оружия кулаки (2) за 150 раундов не снимают и половины здоровья — исход решал стаж.',
                'effect_text'        => 'DuelEquipmentRepository::getEquippedWeapon в дуэли (арена и поле): оружие слабее этого значения заменяется базовым (Common, Physical, крит 0). Обычный PvP и PvE не читают.',
                'above_effect_text'  => 'Выше 10 — больше оружия срезается до базового: при 30 перевес остаётся только у топового снаряжения, билд почти не решает.',
                'below_effect_text'  => 'Ниже 10 — безоружный новичок бьёт слабее, бои длиннее; около 2–3 нокаут при HP 200 почти не наступает, снова решает тай-брейк.',
                'recommended_min'    => '5',
                'recommended_max'    => '20',
                'hard_min'           => '1',
                'hard_max'           => '200',
            ],
            [
                'setting_key'        => 'pvp.duel.weapon_advantage_weight',
                'value_float'        => 0.5,
                'default_value_text' => '0.5',
                'rationale_text'     => 'Какая доля перевеса своего оружия над базовым идёт в бой: урон = база + вес × (своё − база). 0.5 при увороте 40 % — перевес +10 % выигрывает около 57 % дуэлей, двойной — около 94 %, Rare 24 — около 99 % (модель на 3000 боёв). Билд решает, но небольшой перевес не гарантирует победу (выбор владельца 2026-10-08).',
                'effect_text'        => 'DuelEquipmentRepository::duelWeapon: оружие сильнее базового бьёт с этим весом перевеса. Тип урона и крит своего оружия сохраняются.',
                'above_effect_text'  => 'Выше 0.5 — сильнее оружие решает чаще; при 1.0 перевес +25 % выигрывает около 82 % боёв, двойной — почти всегда.',
                'below_effect_text'  => 'Ниже 0.5 — арена ближе к лотерее: при 0.3 двойной перевес выигрывает около 82 %; при 0 у всех базовое оружие и билд не решает вовсе.',
                'recommended_min'    => '0.3',
                'recommended_max'    => '0.8',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'pvp.duel.dodge_percent',
                'value_float'        => 40.0,
                'default_value_text' => '40',
                'rationale_text'     => 'Шанс уворота обоих бойцов в дуэли, %. Уворот — единственная случайность боя: при прежних 12,5 % исход почти детерминирован, и перевес +10 % выигрывал 79 % боёв. 40 % (вместе с весом перевеса 0.5) даёт слабому шанс: +10 % выигрывает около 57 %.',
                'effect_text'        => 'DuelService::equalize ставит обоим ловкость = значение / 0.25 (PvpFormulaService::getDodgeChance). Одинаково у обоих; обычный PvP не читает.',
                'above_effect_text'  => 'Выше 40 — больше промахов: бои длиннее и случайнее; к 75 (потолок движка) три удара из четырёх мимо.',
                'below_effect_text'  => 'Ниже 40 — исход почти детерминирован: любой перевес в оружии побеждает почти всегда.',
                'recommended_min'    => '25',
                'recommended_max'    => '50',
                'hard_min'           => '0',
                'hard_max'           => '75',
            ],
        ];

        $defaults = [
            'category'     => 'combat',
            'value_type'   => 'float',
            'value_int'    => null,
            'value_bool'   => null,
            'value_string' => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }

        $this->db->table('game_settings')->where('setting_key', 'pvp.duel.baseline_health')
            ->update(self::HEALTH_AFTER + ['updated_at' => $now]);
        // Значение — только там, где его не правили из админки: ручная настройка владельца важнее умолчания.
        $this->db->table('game_settings')
            ->where('setting_key', 'pvp.duel.baseline_health')
            ->where('value_int', 1000)
            ->where('updated_by', null)
            ->update(['value_int' => 200]);

        $this->db->table('game_settings')->where('setting_key', 'pvp.duel.baseline_stat')
            ->update(self::STAT_AFTER + ['updated_at' => $now]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', self::NEW_KEYS)->delete();

        $now = date('Y-m-d H:i:s');
        $this->db->table('game_settings')->where('setting_key', 'pvp.duel.baseline_health')
            ->update(self::HEALTH_BEFORE + ['updated_at' => $now]);
        $this->db->table('game_settings')
            ->where('setting_key', 'pvp.duel.baseline_health')
            ->where('value_int', 200)
            ->where('updated_by', null)
            ->update(['value_int' => 1000]);

        $this->db->table('game_settings')->where('setting_key', 'pvp.duel.baseline_stat')
            ->update(self::STAT_BEFORE + ['updated_at' => $now]);
    }
}
