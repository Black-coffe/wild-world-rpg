<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-110 (E9) — настройки наград за достижения (золото).
 *
 * 2 ключа категории endgame. killswitch default OFF → DORMANT (выдача наград молчит до активации
 * после Tier-3). Награда = achievements.points × reward_gold_per_point. Seed game_settings (KEEP)
 * → WipeManifest не затрагивается. Idempotent по setting_key. ADR-024: rationale/effect/above/below NOT NULL.
 */
class Adr110AchievementRewardsGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'achievement.rewards_enabled',
                'category'           => 'endgame',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch золото-наград за достижения (E9, ADR-110). Награда = points x reward_gold_per_point, выдаётся РОВНО при первой разблокировке (без ретро-дампа: существующие 2192 анлока не переполучают). default=false → dormant: достижения выдаются и уведомляют как раньше, но без золота, до активации после Tier-3.',
                'effect_text'        => 'ON: AchievementCheckCron при новой разблокировке начисляет points x reward_gold_per_point золота и показывает его в уведомлении; экран достижений показывает награду в строке. OFF: разблокировки без золота (старое поведение).',
                'above_effect_text'  => 'true: достижения дают осязаемую награду → сильнее мотивируют закрывать цели. Включать после Tier-3 и проверки баланса reward_gold_per_point.',
                'below_effect_text'  => 'false: награды нет (dormant). Достижения — только очки/статус. Emergency-off при инфляции/эксплойте.',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'achievement.reward_gold_per_point',
                'category'           => 'endgame',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Золото за одно очко достижения (points — вес сложности/ценности, уже в таблице achievements). 25: сумма всех points=4320 → максимум за всю игру ~108k золота (символично против avg 1.8M на активного чара — мотивация, не gold-фонтан). Реюз points как веса вместо отдельной reward-колонки (как дейлики: catalog-gold x multiplier).',
                'effect_text'        => 'Золото за разблокировку = achievements.points x это значение (фиксируется при выдаче в AchievementCheckCron).',
                'above_effect_text'  => 'Выше (напр. 100): достижения щедрее (боссовая 200-очковая = 20k) → сильнее тянут, но инфляц-давление.',
                'below_effect_text'  => 'Ниже (напр. 5): награды символические, слабее мотивация, минимум инфляции. 0 = золото фактически выключено (но лучше через rewards_enabled).',
                'recommended_min'    => '5', 'recommended_max' => '100', 'hard_min' => '0', 'hard_max' => '100000',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'achievement.rewards_enabled', 'achievement.reward_gold_per_point',
        ])->delete();
    }
}
