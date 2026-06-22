<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB1 (ADR-137 «Узлы») — БЛОКЕР №1: закап разницы уровней в бою с боссом-узлом.
 *
 * Аудит-находка ADR-137 #4: `DamageService::calculateDamage` (стр.30) считает
 * `levelDifference = (att.level - def.level) * 0.02` БЕЗ clamp. При узлах L150+
 * босс уаншотает игрока в раунде 1 → многоходовый бой невозможен (блокер).
 *
 * Этот ключ — soft-cap разницы уровней, применяемый ТОЛЬКО когда в бою участвует
 * босс (`BattleCharacter::$isBoss`). Обычный PvE/PvP-баланс не трогается. Тот же
 * cap гасит one-shot-ветку `PvpDamageCalculator` для будущего боя с узлом (WB6).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно
 * по setting_key.
 */
class Adr137NodesLeveldiffCapGameSetting extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'combat.nodes.leveldiff_cap',
            'category'           => 'combat',
            'value_type'         => 'int',
            'value_int'          => 30,
            'default_value_text' => '30',
            'rationale_text'     => 'WB1 (ADR-137 «Узлы»): формула урона множит базовый урон на (1 + (att.level − def.level) × 0.02) без ограничения разницы. У узла L150+ это уаншотает игрока в первом раунде, и многоходовый бой с отступлением (ядро механики) становится невозможен. Cap=30 ступеней (×0.02 = ±60% к множителю) держит высокий узел смертельно опасным, но не мгновенным; применяется ТОЛЬКО в бою с боссом (BattleCharacter::isBoss), обычный PvE/PvP не затронут.',
            'effect_text'        => 'Ограничивает |att.level − def.level| значением ±cap в формуле урона, когда в бою участвует босс-узел (обе стороны: и удар узла по игроку, и удар игрока по узлу). Также используется как гейт для отключения one-shot-ветки в бою с узлом. На не-боссовые бои не влияет.',
            'above_effect_text'  => 'Выше (напр. 60-100): высокоуровневые узлы снова бьют почти на уаншот — окно реакции/отступления схлопывается, верхние тиры становятся непроходимы соло и в малой группе; при cap ≥ реальной разницы уровней эффект клампа исчезает (как будто его нет).',
            'below_effect_text'  => 'Ниже (напр. 5-10, как PvP levelDiffCap): узлы почти не «чувствуют» свой уровень — даже L180 бьёт ненамного больнее L50, эндгейм-узлы теряют опасность и ценность похода.',
            'recommended_min'    => 10,
            'recommended_max'    => 50,
            'hard_min'           => 1,
            'hard_max'           => 200,
        ];

        $existing = $this->db->table('game_settings')
            ->where('setting_key', $row['setting_key'])
            ->get()
            ->getRowArray();
        if (! empty($existing)) {
            return;
        }

        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $this->db->table('game_settings')->insert($row);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'combat.nodes.leveldiff_cap')
            ->delete();
    }
}
