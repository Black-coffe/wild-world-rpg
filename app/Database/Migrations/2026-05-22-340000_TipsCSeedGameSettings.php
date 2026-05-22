<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-038 Фаза C2 — live-tunable настройки авто-рассылки «Совет дня».
 *
 * category=world (как seasonal.*). Rich rationale обязателен (CLAUDE.md §🎛️, ADR-024).
 * Idempotent (skip если ключ уже есть).
 */
class TipsCSeedGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tips.daily_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Глобальный killswitch ежедневной авто-рассылки «Совет дня». true — DailyTipBroadcastHandler раз в сутки шлёт каждому игроку (у кого daily_tips_enabled=1) персональный совет с микро-наградой. Запас на случай негативного фидбэка/спам-жалоб.',
                'effect_text'        => 'При false DailyTipBroadcastHandler сразу выходит (return) → рассылка не идёт никому. Ручной /tips и per-character тумблер не затрагиваются.',
                'above_effect_text'  => 'true — авто-рассылка работает (по часу tips.daily_hour, дедуп 15 дней, opt-out у игрока).',
                'below_effect_text'  => 'false — рассылка полностью выключена глобально (мягкий откат без удаления хендлера/контента).',
            ],
            [
                'setting_key'        => 'tips.daily_hour',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Час серверного времени (0-23), в который уходит ежедневная рассылка «Совет дня». 10 утра — мягкое время, игрок видит совет в начале активного дня, не ночью.',
                'effect_text'        => 'DailyTipBroadcastHandler (everyMinute) шлёт только когда текущий час == это значение, один раз в сутки (once/day guard в cache). Меняешь час → рассылка сместится со следующего дня.',
                'above_effect_text'  => 'Больше (вечер/ночь) — совет придёт позже; в поздние часы (22-23) часть игроков уже спит, меньше вовлечённость.',
                'below_effect_text'  => 'Меньше (ранее утро/ночь, 0-6) — рассылка может разбудить уведомлением; ниже вовлечённость.',
                'recommended_min'    => '7',
                'recommended_max'    => '21',
                'hard_min'           => '0',
                'hard_max'           => '23',
            ],
        ];

        $shared = ['category' => 'world', 'created_at' => $now, 'updated_at' => $now];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'tips.daily_enabled',
            'tips.daily_hour',
        ])->delete();
    }
}
