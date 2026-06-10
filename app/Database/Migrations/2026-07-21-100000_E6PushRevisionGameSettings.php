<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 4 — ревизия push: тихие часы + дедупликация.
 *
 *   • notifications.quiet_hours.enabled (bool, default false → DORMANT) — killswitch;
 *   • notifications.quiet_hours.start_hour (int, 23) — начало ночного окна (час 0-23);
 *   • notifications.quiet_hours.end_hour (int, 8) — конец ночного окна (час 0-23);
 *   • notifications.dedup.enabled (bool, default false → DORMANT) — killswitch дедупа;
 *   • notifications.dedup.window_seconds (int, 60) — окно подавления дублей.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 * Категория experimental — как notifications.silent_threshold.enabled (ADR-083).
 */
class E6PushRevisionGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'notifications.quiet_hours.enabled',
                'category'           => 'experimental',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch «тихих часов» (ADR-108 Ф4): ночью рутинные task-completions доставляются беззвучно (disable_notification), чтобы не будить игрока. default=false → dormant. Расширяет ADR-083 silent-threshold: тихо ночью даже если silent-threshold выключен. Игрок с notify_sound=1 (явно вернул звук) ночью НЕ глушится (его выбор главнее).',
                'effect_text'        => 'SilentNotificationPolicy::routineSilentFor: если текущий час в окне [start_hour, end_hour) → рутинное уведомление тихое. Важные (PvP/события/ачивки/находки) не трогаются. Только routine-handler-уведомления.',
                'above_effect_text'  => 'n/a — булев ключ',
                'below_effect_text'  => 'n/a — булев ключ',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'notifications.quiet_hours.start_hour',
                'category'           => 'experimental',
                'value_type'         => 'int',
                'value_int'          => 23,
                'default_value_text' => '23',
                'rationale_text'     => 'Час начала ночного окна тишины (серверное время, 0-23). 23 = с 23:00. Окно [start, end) может переходить через полночь (23..8 = ночь).',
                'effect_text'        => 'isHourInWindow(hour, start, end): рутинные уведомления тихие, пока час в окне.',
                'above_effect_text'  => 'Выше (напр. 0/1): тишина начинается позже — больше вечерних уведомлений со звуком.',
                'below_effect_text'  => 'Ниже (напр. 21): тишина раньше — глушим уже вечером.',
                'recommended_min'    => 18,
                'recommended_max'    => 23,
                'hard_min'           => 0,
                'hard_max'           => 23,
            ],
            [
                'setting_key'        => 'notifications.quiet_hours.end_hour',
                'category'           => 'experimental',
                'value_type'         => 'int',
                'value_int'          => 8,
                'default_value_text' => '8',
                'rationale_text'     => 'Час конца ночного окна тишины (серверное время, 0-23). 8 = до 08:00. Окно [start, end): час == end уже НЕ тихий.',
                'effect_text'        => 'isHourInWindow: при start>end окно переходит через полночь (час ≥ start ИЛИ час < end).',
                'above_effect_text'  => 'Выше (напр. 10): тишина дольше утром — меньше ранних уведомлений со звуком.',
                'below_effect_text'  => 'Ниже (напр. 6): тишина заканчивается раньше — утренние уведомления звучат раньше.',
                'recommended_min'    => 5,
                'recommended_max'    => 10,
                'hard_min'           => 0,
                'hard_max'           => 23,
            ],
            [
                'setting_key'        => 'notifications.dedup.enabled',
                'category'           => 'experimental',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch дедупликации (ADR-108 Ф4): подавляет ПОВТОРНУЮ доставку идентичного рутинного уведомления тому же чату в окне window_seconds (anti-flood для одинаковых «крафт готов» подряд). default=false → dormant. Только routine-handler-уведомления; важные не дедуплицируются.',
                'effect_text'        => 'BaseTaskHandler::safeSendMessage/safeSendPhoto: если isRoutineNotification И NotificationDedupService::shouldSuppress (ключ chat+hash тела был в кэше за window_seconds) → отправка пропускается.',
                'above_effect_text'  => 'n/a — булев ключ',
                'below_effect_text'  => 'n/a — булев ключ',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'notifications.dedup.window_seconds',
                'category'           => 'experimental',
                'value_type'         => 'int',
                'value_int'          => 60,
                'default_value_text' => '60',
                'rationale_text'     => 'Окно подавления дублей (секунды). 60 = одинаковое рутинное уведомление не повторяется чаще раза в минуту. Достаточно, чтобы погасить всплеск из крон-тиков, но не теряет осмысленно разнесённые во времени.',
                'effect_text'        => 'TTL ключа кэша дедупа: повтор того же (chat, тело) в пределах окна подавляется.',
                'above_effect_text'  => 'Выше (напр. 300): дольше глушим повторы — риск потерять легитимное «то же действие снова».',
                'below_effect_text'  => 'Ниже (напр. 10): глушим только очень частые всплески, почти все повторы проходят.',
                'recommended_min'    => 30,
                'recommended_max'    => 300,
                'hard_min'           => 1,
                'hard_max'           => 86400,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $keys = [
            'notifications.quiet_hours.enabled',
            'notifications.quiet_hours.start_hour',
            'notifications.quiet_hours.end_hour',
            'notifications.dedup.enabled',
            'notifications.dedup.window_seconds',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
