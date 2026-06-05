<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 4 — admin-tunable баланс охраняемых руин (ADR-024). category=world.
 *
 * settlements.ruins.enabled (bool, 0) — мастер-рубильник услуги повторяемого лута руин.
 * settlements.ruins.guard_enabled (bool, 0) — размещение hostile-стражи (AutoPve авто-бьёт).
 * settlements.ruins.loot_cooldown_hours (int, 12) — кулдаун повторного лута одной руины (per char).
 * settlements.ruins.loot_amount_mult (float, 1.0) — множитель величины лута. Idempotent.
 */
class SettlementsRuinsGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $keys = [
            [
                'setting_key'        => 'settlements.ruins.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch услуги повторяемого лута охраняемых руин (ADR-101 Фаза 4). true — в хабе руины доступна кнопка «Обыскать руины» (выдаёт ценные ресурсы по кулдауну); false — услуга dormant. Сами руины-маркеры гейтятся per-ruin полем settlements.enabled (строка поселения). Ship dormant, активация после Tier-3.',
                'effect_text'        => 'RuinLootAction/RuinLootService: при ON «Обыскать руины» начисляет лут из loot_config руины с кулдауном; при OFF возвращает «недоступно».',
                'above_effect_text'  => 'true (вкл): глубокий север даёт повторяемый источник ценных ресурсов (Oil/RareMetals/Ironstone) — endgame-цель за риск (стража + дорога).',
                'below_effect_text'  => 'false (выкл, default): услуга недоступна, лут не выдаётся; руины могут быть видны на карте, но «пустые».',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'settlements.ruins.guard_enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch размещения hostile-стражи руин (ADR-101 Фаза 4). true — SettlementPlacementHandler ставит стражей (ai_behavior=aggressive) на якоря руин → AutoPveHandler авто-атакует игрока на клетке (опасность); false — стража не размещается (руина без охраны). Отдельно от лута — можно активировать лут без стражи и наоборот.',
                'effect_text'        => 'SettlementPlacementHandler пропускает settlement_npcs с role=guard при OFF; при ON держит 1 живой спавн стража на якоре (респавн после убийства).',
                'above_effect_text'  => 'true (вкл): руины опасны — страж бьёт пришедшего (риск↔награда); требует баланса под уровень эндгейм-игрока.',
                'below_effect_text'  => 'false (выкл, default): руины безопасны (нет авто-боя), только дорога + кулдаун ограничивают фарм.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'settlements.ruins.loot_cooldown_hours',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 12,
                'default_value_text' => '12',
                'rationale_text'     => 'Кулдаун повторного лута ОДНОЙ руины одним персонажем (часы). 12ч = дважды в сутки на руину при 3 руинах → ограничивает инфляцию ценных ресурсов, но держит руины «повторяемыми» (не разовый лут как у strategic-объектов). ADR-101 §5.',
                'effect_text'        => 'RuinLootService блокирует «Обыскать руины», пока с last_looted_at не прошло cooldown часов (per character × ruin).',
                'above_effect_text'  => 'выше (напр. 24): реже фарм, ценнее каждый заход, меньше инфляция — но руины «скучнее».',
                'below_effect_text'  => 'ниже (напр. 4-6): чаще фарм → больше приток ценных ресурсов → риск инфляции экономики.',
                'recommended_min'    => '6',
                'recommended_max'    => '48',
                'hard_min'           => '1',
                'hard_max'           => '168',
            ],
            [
                'setting_key'        => 'settlements.ruins.loot_amount_mult',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 1.0,
                'default_value_text' => '1.0',
                'rationale_text'     => 'Множитель величины лута руин поверх loot_config (max-amount каждого ресурса × mult). 1.0 = базовые количества из сида. Глобальный рычаг щедрости руин без правки сидов. ADR-101 §5.',
                'effect_text'        => 'RuinLootService: фактический max каждого ресурса = floor(loot_config.max × mult); выдача = rand(1, max).',
                'above_effect_text'  => 'выше (напр. 1.5-2.0): руины щедрее — сильнее тянут в эндгейм-зоны, но риск инфляции ценных ресурсов.',
                'below_effect_text'  => 'ниже (напр. 0.5): руины скупее — слабее стимул идти на риск.',
                'recommended_min'    => '0.5',
                'recommended_max'    => '2.0',
                'hard_min'           => '0.1',
                'hard_max'           => '5.0',
            ],
        ];

        foreach ($keys as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'settlements.ruins.enabled',
            'settlements.ruins.guard_enabled',
            'settlements.ruins.loot_cooldown_hours',
            'settlements.ruins.loot_amount_mult',
        ])->delete();
    }
}
