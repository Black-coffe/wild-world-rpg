<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S3 (ROADMAP-RETENTION-10, ADR-138) — слом стены L1→L5.
 *
 * Audit-first прод (S1, read-only): 86% чаров застряли на L1; 69 чаров прошли 100+ клеток
 * (макс 1008) и всё ещё L1 с avg level-sum 0.905 (L2 нужна сумма ≥8). Корни: добыча=0 XP
 * (GatherResultPersister:97) + стат-гейны теряются на DECIMAL(7,2); ход даёт лишь +0.05/клетку;
 * прерывание задачи (FinishTaskAction) ОБНУЛЯЕТ статы новичка флэтом (agi −0.5 → пол 0.1).
 *
 * Решение: умеренный гибрид, 4 рычага за единым killswitch, scoped «level < level_cap»:
 *  A — XP за завершение добычи (loop-earned);
 *  B — множитель ранних гейнов (компрессия ВРЕМЕНИ, не силы: L5 = те же статы, быстрее; на L5 гаснет);
 *  C — мягче цена усталости хода (больше действий/сессию);
 *  D — убрать обнуляющий штраф прерывания задачи новичку (анти-фрустрация).
 *
 * enabled=false (default) → DORMANT, byte-identical (ридер EarlyProgressionService возвращает
 * нейтрали: множители 1.0, gather_xp 0.0, penalty-factor 1.0). Тюнинг в админке (категория world).
 * Эконом-инвариант П8: XP ≠ faucet золота/ресурсов. Idempotent (как Adr131). game_settings = KEEP
 * (WipeManifest не трогаем — новых таблиц нет).
 */
class Adr138EarlyProgressionGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'progression.early.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Мастер-killswitch слома стены L1→L5 (ADR-138, ретеншн-спринт S3). false (default) — DORMANT: ранний прогресс байт-идентичен до-S3 (добыча 0 XP, штраф прерывания полный). true — включает 4 рычага (gather_xp / gain_multiplier / move_cost_factor / interrupt_penalty_factor) для персонажей с level < level_cap. Активация — решение владельца после Tier-3 + замера (S10).',
                'effect_text'        => 'EarlyProgressionService гейтит все рычаги по этому флагу. true → новичок (level<cap) получает XP за добычу, ускоренные ранние гейны, дешевле ход, без обнуления статов за прерывание.',
                'above_effect_text'  => 'true — стена L1→L5 смягчена для свежих когорт (цель L2-rate 14%→35-55%); ветераны ≥cap не затронуты.',
                'below_effect_text'  => 'false — стена остаётся: 86% на L1, добыча бессмысленна для уровня, прерывание выжигает статы новичка.',
            ],
            [
                'setting_key'        => 'progression.early.level_cap',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Порог «новичка» для рычагов слома стены: рычаги активны при level < level_cap. 5 — узкая ранняя полоса (L1-4); на L5 прогрессия возвращается к легаси-жёсткости (грайнд П1 цел post-L5). Согласовано с soft-start ADR-090 и порогом фракции L10 (новичок успевает увидеть прогресс до социальных гейтов).',
                'effect_text'        => 'EarlyProgressionService::isEarly(level) = enabled && level < level_cap. Все 4 рычага гаснут на этом уровне.',
                'above_effect_text'  => 'При 10 — ускорение тянется до полосы фракции: уровни до L10 дёшевы, теряется ценность грайнда середины ранней игры.',
                'below_effect_text'  => 'При 2 — ускорение почти не работает (только L1), стена L2→L5 остаётся.',
                'recommended_min'    => '3',
                'recommended_max'    => '8',
                'hard_min'           => '1',
                'hard_max'           => '15',
            ],
            [
                'setting_key'        => 'progression.early.gather_xp',
                'value_type'         => 'float',
                'value_float'        => 0.30,
                'default_value_text' => '0.30',
                'rationale_text'     => 'Опыт за ЗАВЕРШЕНИЕ добычи у новичка (level<cap), вместо текущих 0. Loop-earned награда: вышел, потратил усталость, рискнул туманом — добыча перестаёт быть бессмысленной для уровня. 0.30 ≥ 0.01 → переживает округление DECIMAL(5,2) (стат-гейны 0.0006 — нет). Множится на gain_multiplier. 0 = легаси (добыча 0 XP).',
                'effect_text'        => 'GatherResultPersister::bumpCharacterStats: expGain = gather_xp (× gain_multiplier) при enabled && level<cap. Завершение добычи добавляет опыт к сумме уровня.',
                'above_effect_text'  => 'При 1.0 — добыча даёт уровень слишком быстро (1-2 добычи = заметный скачок), риск обесценить ход/крафт как источники.',
                'below_effect_text'  => 'При 0.0 — добыча не даёт опыта (легаси): сигнатурная петля новичка не двигает прогресс.',
                'recommended_min'    => '0.10',
                'recommended_max'    => '0.60',
                'hard_min'           => '0.00',
                'hard_max'           => '3.00',
            ],
            [
                'setting_key'        => 'progression.early.gain_multiplier',
                'value_type'         => 'float',
                'value_float'        => 2.0,
                'default_value_text' => '2.0',
                'rationale_text'     => 'Множитель ранних гейнов опыта/статов (ход, марш, добыча) у новичка (level<cap). Компрессия ВРЕМЕНИ, не силы: L5 по-прежнему = сумма статов 20 = те же статы, просто достигнуты за меньше действий; на level_cap множитель гаснет → post-L5 байт-идентично (грайнд П1 цел). 1.0 = легаси.',
                'effect_text'        => 'EarlyProgressionService::gainMultiplier(level): move/march XP+stat и gather_xp ×N при enabled && level<cap. Меняет только БУДУЩИЕ гейны (не пересчитывает статы) → нет achievement-шторма.',
                'above_effect_text'  => 'При 4.0 — L2-L5 проходятся за ~четверть действий: видимый прогресс быстрый, но ближе к «казуализации» (П1); держать в S10 по метрике L2-rate.',
                'below_effect_text'  => 'При 1.0 — ускорения нет (легаси-скорость): ~159 ходов до L2, стена держится.',
                'recommended_min'    => '1.5',
                'recommended_max'    => '4.0',
                'hard_min'           => '1.00',
                'hard_max'           => '10.00',
            ],
            [
                'setting_key'        => 'progression.early.move_cost_factor',
                'value_type'         => 'float',
                'value_float'        => 0.80,
                'default_value_text' => '0.80',
                'rationale_text'     => 'Множитель цены усталости хода у новичка (level<cap). <1 → ход дешевле → больше действий за сессию (бюджет ~29 ходов растёт), новичок успевает дойти до награды. 0.80 = −20% к 3.35. 1.0 = легаси.',
                'effect_text'        => 'MoveCharacterToDirectionAction: tiredCost ×factor при enabled && level<cap. Влияет только на базовую цену хода новичка (опасные биомы +1.15 health — отдельно).',
                'above_effect_text'  => 'При 1.0 — релиф выключен (легаси-цена): новичок упирается в усталость за ~29 ходов.',
                'below_effect_text'  => 'При 0.40 — ход почти бесплатен для новичка: риск обесценить time/stamina-петлю (кор-ценность П1) даже в ранней полосе.',
                'recommended_min'    => '0.60',
                'recommended_max'    => '1.00',
                'hard_min'           => '0.30',
                'hard_max'           => '1.00',
            ],
            [
                'setting_key'        => 'progression.early.interrupt_penalty_factor',
                'value_type'         => 'float',
                'value_float'        => 0.0,
                'default_value_text' => '0.0',
                'rationale_text'     => 'Множитель штрафа за ПРЕРЫВАНИЕ задачи у новичка (level<cap). FinishTaskAction при прерывании списывает флэтом exp −0.5 / str −0.25 / agi −0.5 / int −0.5 (floored 0.1) — для новичка это ОБНУЛЯЕТ ранний прогресс к полу 0.1 (регрессивно: ветерану ничто). 0.0 → новичок не теряет статы за прерывание (анти-фрустрация, дух ADR-103/131). 1.0 = легаси (полный штраф).',
                'effect_text'        => 'FinishTaskAction: каждая потеря (exp/health/str/agi/int/tired) ×factor при enabled && level<cap перед max(0.1,…). 0 → потери=0 (статы не меняются при прерывании).',
                'above_effect_text'  => 'При 1.0 — штраф полный (легаси): прерывание сбрасывает статы новичка к полу 0.1, выжигая прогресс.',
                'below_effect_text'  => 'При 0.0 — прерывание новичку бесплатно по статам (намеренно): убирает антионбординг-ловушку; ресурсо-штраф прерывания (если есть) не затрагивается.',
                'recommended_min'    => '0.00',
                'recommended_max'    => '0.50',
                'hard_min'           => '0.00',
                'hard_max'           => '1.00',
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
            'progression.early.enabled',
            'progression.early.level_cap',
            'progression.early.gather_xp',
            'progression.early.gain_multiplier',
            'progression.early.move_cost_factor',
            'progression.early.interrupt_penalty_factor',
        ])->delete();
    }
}
