<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * F7.5 — додаємо event_pref JSON колонку в telegram_users.
 *
 * Структура event_pref:
 * ```json
 * {
 *     "last_event_notification_at": "2026-05-05 14:30:00",  // throttle tracking
 *     "silenced_until": null,                               // mute deadline (NULL = active)
 *     "muted_kinds": []                                     // ['damage_health', 'gather_debuff']
 * }
 * ```
 *
 * Використовується NotificationPolicy для:
 *   - throttle: max 1 event-нотіфікація/година (override на critical magnitude)
 *   - mute: silenced_until > now → skip нотіфікації
 *   - kind-mute: muted_kinds містить effect_kind події → skip
 *
 * Nullable — backward compat (legacy users матимуть NULL = усі дефолти).
 *
 * Див. mmorpg-vault/lore/refactor/F7-Audit.md (Step F7.5).
 */
class AddEventPrefToTelegramUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('telegram_users', [
            'event_pref' => [
                'type'  => 'JSON',
                'null'  => true,
                'after' => 'last_name',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('telegram_users', 'event_pref');
    }
}
