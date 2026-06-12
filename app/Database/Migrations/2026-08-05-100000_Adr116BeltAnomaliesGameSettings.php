<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E16 Ф2 (ADR-116) — admin-tunable баланс поясных аномалий (ADR-024). category=world.
 *
 * Аномалии — поясные лор-лендмарки Y300-600 («вторая родина»): enterable settlements type=anomaly,
 * лут читается RuinLootService(keyPrefix='world.anomalies') из settlements.loot_config по кулдауну.
 * Отдельный killswitch от руин (свой риск-профиль: пояс, без стражи, модест-лут + капля поясного сырья).
 *
 * world.anomalies.enabled (bool, 0)            — мастер-рубильник услуги «Исследовать аномалию».
 * world.anomalies.loot_cooldown_hours (int,12) — кулдаун повторного исследования одной аномалии (per char).
 * world.anomalies.loot_amount_mult (float,1.0) — множитель величины лута. Idempotent. Ship dormant.
 */
class Adr116BeltAnomaliesGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $keys = [
            [
                'setting_key'        => 'world.anomalies.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch услуги повторяемого лута поясных аномалий (E16 Ф2, ADR-116). true — в хабе аномалии (type=anomaly, пояс Y300-600) доступна кнопка «Исследовать аномалию» (выдаёт модест-лут + каплю поясного сырья по кулдауну); false — услуга dormant. Сами маркеры аномалий гейтятся per-anomaly полем settlements.enabled. Отдельно от settlements.ruins.enabled (другой риск-профиль: пояс vs глубокий север). Ship dormant, активация после Tier-3 и ревью лута владельцем.',
                'effect_text'        => 'AnomalyLootAction/RuinLootService(prefix=world.anomalies): при ON «Исследовать аномалию» начисляет лут из loot_config аномалии с кулдауном; при OFF возвращает «затихла».',
                'above_effect_text'  => 'true (вкл): пояс Y300-600 получает повторяемый лор-источник ресурсов — текстурирует «вторую родину» подросшего игрока, мягко тянет на север; добавляет вторичный (cooldown-gated) приток поясного сырья поверх gather-гейта E16 Ф1.',
                'below_effect_text'  => 'false (выкл, default): услуга недоступна, аномалии могут быть видны на карте как лор-точки, но «пустые» (только осмотр).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'world.anomalies.loot_cooldown_hours',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 12,
                'default_value_text' => '12',
                'rationale_text'     => 'Кулдаун повторного исследования ОДНОЙ аномалии одним персонажем (часы). 12ч = дважды в сутки на аномалию → ограничивает приток поясного сырья (Пепел Предтеч / Кристалл Разлома — rarity 1), держит аномалии «повторяемыми». Делит таблицу character_ruin_loot (ключ char×settlement) с руинами. ADR-116 Ф2.',
                'effect_text'        => 'RuinLootService блокирует «Исследовать аномалию», пока с last_looted_at не прошло cooldown часов (per character × anomaly).',
                'above_effect_text'  => 'выше (напр. 24): реже фарм, ценнее каждый заход, меньше приток поясного сырья.',
                'below_effect_text'  => 'ниже (напр. 4-6): чаще фарм → больше приток rarity-1 поясного сырья → риск обесценить gather-гейт E16 Ф1 и грандмастер-синк.',
                'recommended_min'    => '6',
                'recommended_max'    => '48',
                'hard_min'           => '1',
                'hard_max'           => '168',
            ],
            [
                'setting_key'        => 'world.anomalies.loot_amount_mult',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 1.0,
                'default_value_text' => '1.0',
                'rationale_text'     => 'Множитель величины лута аномалий поверх loot_config (max-amount каждого ресурса × mult). 1.0 = базовые количества из сида (модест: generic-сырьё + 1-2 поясного). Глобальный рычаг щедрости аномалий без правки сидов. ADR-116 Ф2.',
                'effect_text'        => 'RuinLootService: фактический max каждого ресурса = floor(loot_config.max × mult); выдача = rand(1, max).',
                'above_effect_text'  => 'выше (напр. 1.5-2.0): аномалии щедрее — сильнее тянут в пояс, но риск обесценить поясное сырьё (П8 — это rarity-1 ингредиент грандмастер-рецептов).',
                'below_effect_text'  => 'ниже (напр. 0.5): аномалии скупее — слабее стимул, лор-точки почти без награды.',
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
            'world.anomalies.enabled',
            'world.anomalies.loot_cooldown_hours',
            'world.anomalies.loot_amount_mult',
        ])->delete();
    }
}
