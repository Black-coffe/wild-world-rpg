<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W9 (ADR-066) — 2 GameSettings для achievement-движка.
 *
 * - `achievement.enabled` (bool, default FALSE = dormant на ship). W10 активирует вместе
 *   с контентом (20+ medals) + UI просмотра. Без UI уведомления = тупик (rule #4) →
 *   foundation спит до W10 (зеркало W3a weight-cap, ADR-059).
 * - `achievement.max_awards_per_tick` (int, default 25) — throttle против notification-шторма
 *   при активации (342 чара разом выполнят level/base/faction-критерии). Размазывает backfill.
 *
 * Constitutional (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Idempotent: пропускает существующий setting_key.
 */
class W9SeedAchievementGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'achievement.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch системы достижений (W9 ADR-066). default=FALSE на ship — foundation спит: AchievementCheckCron no-op, ничего не выдаётся/не уведомляется. W10 активирует вместе с контентом (20+ medals) и UI просмотра «🏅 Достижения» — без UI уведомления вели бы в тупик (rule #4). Прецедент W3a (weight-cap shipped OFF).',
                'effect_text'        => 'AchievementService::isEnabled() читает ключ. false → AchievementCheckCron сразу выходит (0 выдач). true → cron everyMinute набирает qualifying-чаров set-based SQL по каждому достижению и выдаёт (idempotent, UNIQUE).',
                'above_effect_text'  => 'true: достижения активны. При первой активации существующие игроки получат backfill за прошлые достижения (троттлится max_awards_per_tick, батч per-player). Положительный «вот что ты уже заработал» момент.',
                'below_effect_text'  => 'false: система спит. Используем на ship W9 (нет UI) и как emergency-rollback если выдача/уведомления окажутся проблемными.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'achievement.max_awards_per_tick',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Сколько выдач достижений AchievementCheckCron делает за один тик (everyMinute). Throttle против notification-шторма: при активации (W10) ~342 существующих чара разом выполнят пороги level/base/faction → без cap сотни Telegram-сообщений за минуту. Cap размазывает backfill во времени.',
                'effect_text'        => 'AchievementCheckCron останавливает выдачу, набрав max_awards_per_tick новых выдач за тик; остаток догоняется на следующих тиках. Уведомления батчатся per-player (один msg на чара со всеми его новыми анлоками).',
                'above_effect_text'  => 'Выше (напр. 200): backfill быстрее, но риск Telegram rate-limit при массовой активации и всплеск сообщений.',
                'below_effect_text'  => 'Ниже (напр. 5): backfill очень плавный, но существующие игроки ждут свои «прошлые» медали дольше после активации.',
                'recommended_min'    => '5',
                'recommended_max'    => '100',
                'hard_min'           => '1',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (empty($existing)) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['achievement.enabled', 'achievement.max_awards_per_tick'])
            ->delete();
    }
}
