<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * F7.4 — додаємо effect_log + notified_users JSON колонки в active_events.
 *
 * Мета:
 *  - effect_log: накопичувати per-character intent deltas протягом подіи
 *    (health_delta, gold_delta, applied_count, first/last timestamps).
 *    EventCloseHandler читаесть це при event end → формуесть end-summary
 *    уведомлению с агрегатом вместо 30 окремих tick-уведомлений.
 *  - notified_users: список char_id, яким уже отправлено start- або
 *    one_shot уведомлению. Запобігаесть повторномв отправленню при каждомв tick.
 *
 * Обидви колонки nullable — backward compat (legacy active_events rows
 * матимуть NULL, dispatcher спринимаесть NULL як empty array).
 *
 * Див. mmorpg-vault/lore/refactor/F7-Audit.md (Step F7.4).
 */
class AddEffectLogToActiveEvents extends Migration
{
    public function up()
    {
        $this->forge->addColumn('active_events', [
            'effect_log' => [
                'type'  => 'JSON',
                'null'  => true,
                'after' => 'effect_applied',
            ],
            'notified_users' => [
                'type'  => 'JSON',
                'null'  => true,
                'after' => 'effect_log',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('active_events', ['effect_log', 'notified_users']);
    }
}
