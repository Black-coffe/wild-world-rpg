<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB16-fix (ADR-137 «Узлы», WB15-находка) — оценка «☠ Осмотреть» по РЕАЛЬНОЙ боевой мощи, не по уровню.
 *
 * WB15 вскрыл: карточка осмотра и предупреждение перед боем считали тир по разнице УРОВНЕЙ
 * (`combat.nodes.tier_solo_diff`/`tier_hard_diff`). Но на проде ~90% игроков бьются кулаками
 * (damage_value≈5) независимо от уровня → высокоуровневый кулачник видел ложный 🟢 «по силам»
 * против узла, который его валит. BossEncounterService теперь оценивает тир по детерминированной
 * симуляции (раундов свалить узел / раундов узлу свалить меня) — отношение, а не уровень.
 *
 * Эта миграция: УДАЛЯЕТ устаревшие level-diff пороги (мёртвые после фикса — не читаются) и ВВОДИТ
 * пороги-отношения `combat.nodes.tier_solo_ratio`(1.0)/`tier_hard_ratio`(2.0).
 *
 * Constitutional rule (ADR-024): rationale/effect/above/below NOT NULL. Seed game_settings (НЕ
 * createTable) → WipeManifest=KEEP. Идемпотентно по setting_key. Forward-only (down: убрать новые).
 */
class Adr137NodesExamineTierPowerBased extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Снять мёртвые level-diff пороги (после WB16-фикса не читаются ни одной строкой кода).
        $this->db->table('game_settings')->whereIn('setting_key', [
            'combat.nodes.tier_solo_diff', 'combat.nodes.tier_hard_diff',
        ])->delete();

        $rows = [
            [
                'setting_key' => 'combat.nodes.tier_solo_ratio', 'value_float' => 1.0,
                'rationale' => 'Порог 🟢 «по силам» (ADR-137 WB16, карточка осмотра по РЕАЛЬНОЙ силе). Тир = отношение «раундов мне свалить узел» / «раундов узлу свалить меня» (детерминированная симуляция по экип+статам). ≤ этого → 🟢 «можно в одиночку». 1.0 = бью насквозь не позже, чем он меня (я бью первым → выигрываю). Подсказка СЛОВАМИ, не точная формула. Не влияет на бой — только на текст оценки и порог предупреждения.',
                'effect' => 'Граница зелёной (соло) оценки силы узла в карточке осмотра.',
                'above' => 'Выше (>1.0): 🟢 показывается даже когда узел свалит тебя раньше → недооценка опасности (over-promise, та самая WB15-ошибка в новой форме).',
                'below' => 'Ниже (<1.0): 🟢 только с запасом прочности → осторожнее, но честнее.',
                'rmin' => 0.7, 'rmax' => 1.2, 'hmin' => 0.1, 'hmax' => 5.0,
            ],
            [
                'setting_key' => 'combat.nodes.tier_hard_ratio', 'value_float' => 2.0,
                'rationale' => 'Порог 🟡 «тяжело» vs 🔴 «нужна группа» (ADR-137 WB16). Если отношение раундов ≤ этого (и > tier_solo_ratio) → 🟡 «на грани, помогут припасы/броня/напарник»; выше → 🔴 «нужна группа (Облава) или оружие крепче». Также граница предупреждения перед боем (isLethalGap) — нет второй правды: осмотр 🔴 ⟺ атака предупредит. 2.0 = узлу нужно вдвое больше раундов добить меня, чем мне его → ещё вытягивается хилом/едой/вторым бойцом.',
                'effect' => 'Граница жёлтой/красной оценки силы узла + порог предупреждения перед боем.',
                'above' => 'Выше: 🔴/предупреждение загораются поздно → игрок недооценивает нужду в группе/оружии, чаще гибнет соло.',
                'below' => 'Ниже: 🔴/предупреждение загораются рано → даже вытягиваемые узлы выглядят группозависимыми.',
                'rmin' => 1.5, 'rmax' => 4.0, 'hmin' => 1.0, 'hmax' => 50.0,
            ],
        ];

        foreach ($rows as $r) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $r['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert([
                'setting_key' => $r['setting_key'], 'category' => 'combat', 'value_type' => 'float',
                'value_float' => $r['value_float'], 'default_value_text' => (string) $r['value_float'],
                'rationale_text' => $r['rationale'], 'effect_text' => $r['effect'],
                'above_effect_text' => $r['above'], 'below_effect_text' => $r['below'],
                'recommended_min' => $r['rmin'], 'recommended_max' => $r['rmax'],
                'hard_min' => $r['hmin'], 'hard_max' => $r['hmax'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'combat.nodes.tier_solo_ratio', 'combat.nodes.tier_hard_ratio',
        ])->delete();
    }
}
