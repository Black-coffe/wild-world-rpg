<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-148 — killswitch firehose действий игрока ({@see \App\Services\Logging\PlayerActionLogger}).
 *
 * Инфра-флаг (не игровой баланс), но держим в GameSettings ради live-toggle БЕЗ редеплоя
 * (горячий путь webhook'а) + audit-trail. default true → как только Шаг 2 подключит чокпоинт,
 * захват сразу ЖИВОЙ (это явная цель задачи «логировать всё»). Флип false мгновенно глушит
 * запись firehose, если объём начнёт мешать (до построения авто-очистки/ротации).
 *
 * Категория `experimental` — bucket для инфра/feature-флагов вне core-баланса. Idempotent.
 * game_settings = KEEP (WipeManifest не трогаем).
 */
class Adr148PlayerActionLogGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'logging.player_actions.enabled',
            'category'           => 'experimental',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => 'true',
            'rationale_text'     => 'Killswitch ADR-148 firehose всех прямых действий игрока (таблица player_action_log: callback-кнопки/текст/reply/forceReply/slash). true (default) — каждое действие пишется одной строкой в единой точке BotController. false — запись firehose мгновенно глушится (захват остаётся no-op), на случай если объём начнёт мешать до построения авто-очистки/ротации. Инфра-флаг, не игровой баланс; в GameSettings ради live-toggle без редеплоя + audit-trail.',
            'effect_text'        => 'PlayerActionLogger::commit() гейтит INSERT в player_action_log. Не влияет на геймплей/UX — чистая телеметрия на сервере.',
            'above_effect_text'  => 'true — полная оцифровка действий игрока (форензика «куда делись ресурсы», воронки, разбор багов по реальному пути игрока). Таблица растёт быстро (планируется авто-очистка/ротация).',
            'below_effect_text'  => 'false — firehose не пишется; видимость действий игрока возвращается к редким ручным маркерам в action_log (как было до ADR-148).',
            'value_int'          => null,
            'value_float'        => null,
            'value_string'       => null,
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('game_settings')
            ->where('setting_key', $row['setting_key'])
            ->get()
            ->getRowArray();

        if (empty($exists)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'logging.player_actions.enabled')
            ->delete();
    }
}
