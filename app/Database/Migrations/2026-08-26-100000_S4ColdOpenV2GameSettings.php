<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — cold-open «полярная звезда».
 *
 * Audit-first прод (S1, read-only): доминирующая утечка ретеншна — немедленный bounce
 * (76% не возвращаются к D1, 56% L1 не двигаются, 58% не называют чара). Живая
 * онбординг-цепочка (OnbStep*, ADR-103 Слой 2) ведёт по шагам, но новичок её не видит
 * постоянно → не знает «что дальше». S4 спина: текущий шаг цепочки виден ВСЕГДА на
 * карточке Перса и Карте одной строкой «🎯 Сейчас: <цель> (X/Y)» (PolarStarService).
 *
 * Единый killswitch S4-спины (полярная звезда + cold-open framing /start). false (default)
 * → DORMANT, byte-identical (PolarStarService::line возвращает null → строки нет). true —
 * показывает полярную звезду новичку с активным шагом. Активация — решение владельца после
 * Tier-3 cold-smoke. read-only фича (без мутаций состояния, без новых таблиц/источников
 * золота → эконом-инвариант П8 н/п, WipeManifest не трогаем; game_settings = KEEP).
 * Тексты целей живут в OnboardingChainCatalog (value_string=255 мал для длинных текстов).
 * Idempotent (как Adr138). Категория world — как все онбординг-ключи.
 */
class S4ColdOpenV2GameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.cold_open_v2.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch S4-спины cold-open «полярная звезда» (ADR-139, ретеншн-спринт S4). false (default) — DORMANT: карточка Перса и Карта байт-идентичны до-S4 (строки цели нет). true — показывает новичку с активным шагом онбординг-цепочки (OnbStep*) одну закреплённую строку «🎯 Сейчас: <цель> (X/Y)» на карточке Перса и в подписи Карты. Бьёт в bounce-утечку (56% L1 не двигаются — не знают «что дальше»). Активация — решение владельца после Tier-3 cold-smoke; замер Move/L2-rate в S10.',
                'effect_text'        => 'PolarStarService::line гейтит вывод по этому флагу. true → новичок с незавершённым шагом цепочки видит текущую цель + прогресс на ключевых экранах. Read-only (без мутаций): только отображение живого состояния цепочки.',
                'above_effect_text'  => 'true — растерявшийся новичок всегда видит следующий шаг (цель S4: cold-start Move 57%→70-80%, named-rate 42%→≥65%); ветераны без активного OnbStep-шага не затронуты (строки нет).',
                'below_effect_text'  => 'false — цель цепочки видна только в «Активных квестах» (плоский список) и в исчезающей подсказке после шага: новичок чаще упирается в «что дальше» и отваливается (bounce).',
            ],
        ];

        $defaults = [
            'category'        => 'world',
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
            'onboarding.cold_open_v2.enabled',
        ])->delete();
    }
}
