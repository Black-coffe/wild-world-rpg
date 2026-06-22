<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB12 (ADR-137 «Узлы») — пороги оценки силы узла СЛОВАМИ для карточки «☠ Осмотреть».
 *
 * Карточка осмотра (BossEncounterService::powerTier) показывает оценку ТИРАМИ-словами (🟢 по силам /
 * 🟡 тяжело / 🔴 нужна группа), НЕ хрупкими числами (анти-дрейф). Пороги — по разнице (уровень узла −
 * уровень игрока):
 *   - `combat.nodes.tier_solo_diff`(0) — узел не выше игрока → «по силам» (соло);
 *   - `combat.nodes.tier_hard_diff`(15) — узел до +N над игроком → «тяжело»; выше → «нужна группа».
 *
 * Constitutional rule (ADR-024): rationale/effect/above/below NOT NULL. Seed game_settings → WipeManifest=KEEP.
 * Идемпотентно по setting_key.
 */
class Adr137NodesExamineTierGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key' => 'combat.nodes.tier_solo_diff', 'value_int' => 0,
                'rationale' => 'Порог «по силам» (ADR-137 WB12, карточка осмотра): если уровень узла − уровень игрока ≤ этого, оценка = 🟢 «можно в одиночку». Подсказка СЛОВАМИ, не точная формула (анти-дрейф). Не влияет на бой — только на текст оценки.',
                'effect' => 'Граница зелёной (соло) оценки силы узла в карточке осмотра.',
                'above' => 'Выше: «по силам» показывается даже когда узел заметно выше игрока → недооценка опасности.',
                'below' => 'Ниже (−): зелёная оценка только для узлов СЛАБЕЕ игрока → осторожнее, но почти всё «тяжело».',
                'rmin' => -10, 'rmax' => 10, 'hmin' => -50, 'hmax' => 50,
            ],
            [
                'setting_key' => 'combat.nodes.tier_hard_diff', 'value_int' => 15,
                'rationale' => 'Порог «тяжело» vs «нужна группа» (ADR-137 WB12): если уровень узла − уровень игрока ≤ этого (и > tier_solo_diff), оценка = 🟡 «тяжело, лучше с подмогой»; выше → 🔴 «нужна группа (Облава)». Граница между «соберись» и «не в одиночку».',
                'effect' => 'Граница жёлтой/красной оценки силы узла в карточке осмотра.',
                'above' => 'Выше: «тяжело» показывается для очень сильных узлов → игрок недооценивает необходимость группы.',
                'below' => 'Ниже: «нужна группа» загорается рано → даже посильные узлы выглядят группозависимыми.',
                'rmin' => 5, 'rmax' => 40, 'hmin' => 1, 'hmax' => 200,
            ],
        ];

        foreach ($rows as $r) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $r['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert([
                'setting_key' => $r['setting_key'], 'category' => 'combat', 'value_type' => 'int',
                'value_int' => $r['value_int'], 'default_value_text' => (string) $r['value_int'],
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
            'combat.nodes.tier_solo_diff', 'combat.nodes.tier_hard_diff',
        ])->delete();
    }
}
