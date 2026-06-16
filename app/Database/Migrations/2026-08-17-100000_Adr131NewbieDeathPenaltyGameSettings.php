<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E31 (ROADMAP-100, ADR-131) — анти-фрустрация: смягчение потерь смерти для новичков.
 *
 * Audit (прод 2026-06-16): смерть = «рулетка смерти» (DeathRouletteHandler, ежеминутно для
 * health<=0.99) + PvP (AttackPlayerAction). Штраф (DeathPenaltyCalculator) ПЛОСКИЙ и НЕ зависит
 * от уровня: страховка 0% / есть база 3% / НЕТ базы 50%. Новичок L1-15 (обычно без базы) при
 * смерти теряет ПОЛОВИНУ ресурсов/золота/крафта — «выжигает» на старте (цель E31: смерть учит,
 * а не выжигает). Снимок прода: 506 L1-15 сейчас здоровы → это safety-net на рост онлайна (SEO),
 * не активный пожар.
 *
 * Решение: level-scaled штраф — для level ∈ (0, newbie_level_max] возвращаем сниженные потери.
 * Тюнинг в админке (категория combat). Эконом-инвариант: НЕ faucet (потери лишь СНИЖАЮТСЯ, не
 * выдаётся золото); анти-P2W (защита по уровню, не за деньги).
 *
 *  - combat.death.newbie_level_max — killswitch+порог (default 0 → DORMANT, byte-identical:
 *    штраф остаётся 3%/50% для всех; >0 → защита для level<=N).
 *  - combat.death.newbie_penalty_no_base — штраф новичку БЕЗ базы (вместо 50%).
 *  - combat.death.newbie_penalty_with_base — штраф новичку С базой (вместо 3%).
 *
 * Idempotent (как Adr121). Страховка (0%) всегда приоритетнее. game_settings = KEEP (WipeManifest
 * не трогаем — таблица классифицирована, новых таблиц нет).
 */
class Adr131NewbieDeathPenaltyGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'combat.death.newbie_level_max',
                'value_type'         => 'int',
                'value_int'          => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'Killswitch+порог смягчения потерь смерти новичкам (ADR-131). 0 (default) — DORMANT: штраф плоский для всех (база 3% / без базы 50%), byte-identical с до-E31. >0 — персонажи с level<=N получают сниженные потери (newbie_penalty_*). Рекомендованное при активации — 10 (полоса онбординга L1-10, согласовано с soft-start ADR-090 и event-tier ADR-117).',
                'effect_text'        => 'DeathService::handlePlayerDeathAndReward → DeathPenaltyCalculator::decide(insured, hasBase, level, newbie). При 0<level<=N и НЕ insured возвращаются newbie_penalty_no_base/with_base вместо 3%/50%. Страховка (0%) приоритетнее. Влияет на «рулетку смерти» и PvP-смерть.',
                'above_effect_text'  => 'При 25 — защита тянется до середины игры: смерть почти ничего не стоит до L25, теряется обучающая цена ошибки.',
                'below_effect_text'  => 'При 0 — защиты нет (dormant), новичок без базы теряет 50% при смерти (риск churn на старте).',
                'recommended_min'    => '5',
                'recommended_max'    => '15',
                'hard_min'           => '0',
                'hard_max'           => '30',
            ],
            [
                'setting_key'        => 'combat.death.newbie_penalty_no_base',
                'value_type'         => 'float',
                'value_float'        => 0.10,
                'default_value_text' => '0.10',
                'rationale_text'     => 'Доля потерь ресурсов/золота/крафта при смерти новичка БЕЗ базы (вместо плоских 50%). 0.10 = теряет 10% — ощутимо (смерть не бесплатна, мотивирует строить базу/страховку), но не выжигает старт. Применяется только при newbie_level_max>0 и level<=него.',
                'effect_text'        => 'DeathPenaltyCalculator::decide возвращает это значение новичку без активной базы. LootProcessor списывает эту долю + (при PvP) половину передаёт победителю.',
                'above_effect_text'  => 'При 0.50 — равно legacy, защиты нет.',
                'below_effect_text'  => 'При 0.0 — смерть новичку без базы бесплатна: пропадает стимул строить базу и осторожничать.',
                'recommended_min'    => '0.05',
                'recommended_max'    => '0.20',
                'hard_min'           => '0.00',
                'hard_max'           => '0.50',
            ],
            [
                'setting_key'        => 'combat.death.newbie_penalty_with_base',
                'value_type'         => 'float',
                'value_float'        => 0.0,
                'default_value_text' => '0.0',
                'rationale_text'     => 'Доля потерь при смерти новичка С базой (вместо плоских 3%). 0.0 = новичок с базой не теряет ничего — награда за то, что построил лагерь (главный онбординг-шаг). Применяется только при newbie_level_max>0 и level<=него.',
                'effect_text'        => 'DeathPenaltyCalculator::decide возвращает это значение новичку с активной базой. 0 → LootProcessor списывает 0 (как страховка, но по уровню+базе).',
                'above_effect_text'  => 'При 0.03 — равно legacy для базы (символическая потеря).',
                'below_effect_text'  => 'При 0.0 — смерть с базой полностью безопасна новичку (намеренно: поощряет строительство базы).',
                'recommended_min'    => '0.00',
                'recommended_max'    => '0.03',
                'hard_min'           => '0.00',
                'hard_max'           => '0.50',
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
            'combat.death.newbie_level_max',
            'combat.death.newbie_penalty_no_base',
            'combat.death.newbie_penalty_with_base',
        ])->delete();
    }
}
