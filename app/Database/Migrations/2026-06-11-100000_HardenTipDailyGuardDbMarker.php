<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Закалка once/day-guard авто-рассылки «Совет дня» (DailyTipBroadcastHandler).
 *
 * Раньше guard держался ТОЛЬКО на cache-маркере `tips_daily_last_sent` — cache:clear
 * или деплой в час рассылки (tips.daily_hour=10) стирал маркер → повторная рассылка
 * тем же игрокам (прод-аномалии 2026-05-23 ×22, 05-27 ×4). Кэш — наименее надёжное
 * хранилище для once/day mass-broadcast.
 *
 * Теперь авторитетный guard — атомарный DB-claim строки `tips.daily_last_broadcast`
 * (value_string = 'Y-m-d'). category='system' — авто-маркер, НЕ редактируется в админке.
 * Хэндлер сам создаёт строку при отсутствии (self-heal), миграция сидит её для чистоты.
 */
class HardenTipDailyGuardDbMarker extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('game_settings')
            ->where('setting_key', 'tips.daily_last_broadcast')
            ->countAllResults();
        if ($exists === 0) {
            $this->db->table('game_settings')->insert([
                'setting_key'        => 'tips.daily_last_broadcast',
                'value_type'         => 'string',
                'value_string'       => '',
                'default_value_text' => '',
                'category'           => 'system',
                'rationale_text'     => 'Авто-маркер: дата (Y-m-d) последней успешной авто-рассылки «Совет дня». Управляется DailyTipBroadcastHandler атомарным claim — НЕ редактировать вручную. Гарантирует ровно 1 рассылку в сутки даже при cache:clear/деплое в час рассылки.',
                'effect_text'        => 'Если значение = сегодняшней дате — рассылка за сегодня уже выполнена, повторный прогон cron пропускается.',
                'above_effect_text'  => 'n/a — служебный маркер',
                'below_effect_text'  => 'n/a — служебный маркер',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'tips.daily_last_broadcast')
            ->delete();
    }
}
