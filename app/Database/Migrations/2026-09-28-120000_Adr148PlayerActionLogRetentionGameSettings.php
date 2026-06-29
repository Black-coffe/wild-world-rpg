<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-148 (ротация) — параметры авто-очистки firehose `player_action_log`
 * (команда player-actions:cleanup, расписание Config\Tasks daily 03:40).
 *
 *   - retention_days — TTL: удалять строки старше N дней (0 = пропустить TTL).
 *   - max_rows — кап кольцевого буфера: оставить ≤ N новейших, лишние старые
 *     вытеснить «старое новым» (0 = пропустить кап).
 *
 * Инфра-параметры (не игровой баланс), в GameSettings ради live-tuning без редеплоя +
 * audit-trail (как killswitch logging.player_actions.enabled). Категория experimental.
 * Idempotent. game_settings = KEEP (WipeManifest не трогаем).
 */
class Adr148PlayerActionLogRetentionGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'logging.player_actions.retention_days',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'recommended_min'    => 7,
                'recommended_max'    => 365,
                'hard_min'           => 0,
                'hard_max'           => 3650,
                'rationale_text'     => 'TTL firehose player_action_log: строки старше N дней удаляются ежедневной ротацией (player-actions:cleanup). 30 дней — достаточно для форензики (разбор «куда делись ресурсы», воронки, баг-репорты по реальному пути игрока за последний месяц), при этом таблица не растёт бесконечно. 0 = TTL отключён (чистит только кап max_rows).',
                'effect_text'        => 'CleanupPlayerActionLog: DELETE WHERE created_at < NOW()-INTERVAL N DAY (пакетами).',
                'above_effect_text'  => 'Выше — дольше история (глубже форензика), но таблица крупнее и медленнее аналитические выборки.',
                'below_effect_text'  => 'Ниже — таблица компактнее/быстрее, но старше N дней разбор уже невозможен.',
            ],
            [
                'setting_key'        => 'logging.player_actions.max_rows',
                'value_type'         => 'int',
                'value_int'          => 2000000,
                'default_value_text' => '2000000',
                'recommended_min'    => 100000,
                'recommended_max'    => 10000000,
                'hard_min'           => 0,
                'hard_max'           => 100000000,
                'rationale_text'     => 'Кап кольцевого буфера player_action_log: в таблице остаётся не более N НОВЕЙШИХ строк, лишние СТАРЕЙШИЕ вытесняются («старое новым»). 2 млн — жёсткий потолок-предохранитель поверх TTL: если онлайн резко вырастет и за retention_days накопится больше — самые старые всё равно вытеснятся, защищая БД от разрастания. 0 = кап отключён (чистит только TTL).',
                'effect_text'        => 'CleanupPlayerActionLog: при COUNT(*) > N удаляет (COUNT−N) старейших по id ASC (пакетами).',
                'above_effect_text'  => 'Выше — больше истории в окне кап, но крупнее таблица; при большом TTL может не срабатывать вовсе.',
                'below_effect_text'  => 'Ниже — таблица жёстко ограничена сверху, но при всплеске активности старое вытеснится раньше retention_days (потеря недавней форензики).',
            ],
        ];

        $defaults = [
            'category'        => 'experimental',
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
            'logging.player_actions.retention_days',
            'logging.player_actions.max_rows',
        ])->delete();
    }
}
