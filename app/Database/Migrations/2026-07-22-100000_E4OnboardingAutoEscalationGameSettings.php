<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E4 (ROADMAP-100-SESSIONS) — настройки авто-эскалации онбординга «застрял» (OnboardingNudgeHandler).
 *
 * 4 ключа категории world (онбординг — Слой 3 ADR-103). killswitch default OFF → DORMANT:
 * хендлер засижен, но молчит до активации после Tier-3 на testbot.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed существующей game_settings (НЕ createTable) → WipeManifest не затрагивается
 * (game_settings уже KEEP). Idempotent по setting_key.
 */
class E4OnboardingAutoEscalationGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.auto_escalation.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch авто-эскалации онбординга (E4, OnboardingNudgeHandler): новичку, застрявшему на назначенном шаге обучающей цепочки дольше stuck_minutes и активному прямо сейчас (last_seen в окне), шлётся ОДНА контекстная подсказка текущего шага. default=false → dormant: хендлер засижен, молчит до активации после Tier-3.',
                'effect_text'        => 'ON: everyMinute-хендлер выбирает новичков (level ≤ contextual_hints.max_level, opt-in daily_tips_enabled, достижимы и активны по last_seen) с незавершённым онбординг-шагом старше stuck_minutes, по которому подсказка ещё не слалась (one-shot OnbNudge_<step> в action_log), и шлёт description текущего шага. Бьёт по раннему оттоку «не понял что делать» (инцидент m8k2b).',
                'above_effect_text'  => 'true: застрявшие активные новички получают пере-подсказку. Риск — навязчивость, поэтому one-shot per шаг + opt-out + батч-кап. Включать после Tier-3-проверки текста и таймингов.',
                'below_effect_text'  => 'false: авто-эскалации нет (dormant). Новичок, застрявший на шаге, не получает пере-подсказку — только исходный done_text предыдущего шага. Emergency-off при навязчивости/баге.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'onboarding.auto_escalation.stuck_minutes',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 45,
                'default_value_text' => '45',
                'rationale_text'     => 'Сколько минут назначенный онбординг-шаг (quest_steps, is_completed=0) должен «висеть», прежде чем новичок считается застрявшим и получает пере-подсказку. 45 мин — достаточно, чтобы не дёргать игрока, который просто думает/читает, но и не бросить надолго того, кто реально не понял.',
                'effect_text'        => 'Порог возраста незавершённого шага (NOW - created_at) в выборке fetchStuck. Влияет, как быстро после назначения шага сработает пере-подсказка.',
                'above_effect_text'  => 'Выше (напр. 120): подсказка приходит позже — меньше шанс «спасти» новичка в первой сессии, зато 0 риска поторопить.',
                'below_effect_text'  => 'Ниже (напр. 10): подсказка приходит быстро, но рискует сработать, пока игрок ещё осваивается сам → ощущение навязчивости.',
                'recommended_min'    => '15',
                'recommended_max'    => '120',
                'hard_min'           => '1',
                'hard_max'           => '1440',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'onboarding.auto_escalation.active_window_minutes',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => 'Окно «активен сейчас»: подсказка шлётся, только если last_seen игрока (E6 Ф1) свежее этого числа минут. Цель — пере-подсказывать тем, кто ПРЯМО СЕЙЧАС играет и застрял, а не слать пуш оффлайн-призраку (который воспримет это как спам при возврате).',
                'effect_text'        => 'Порог свежести telegram_users.last_seen в выборке fetchStuck.',
                'above_effect_text'  => 'Выше (напр. 1440): подсказка может прийти игроку, который давно ушёл — пуш «в спину», ощущается как спам.',
                'below_effect_text'  => 'Ниже (напр. 5): ловим только тех, кто буквально только что что-то нажал; часть застрявших, отошедших на 10 минут, упустим.',
                'recommended_min'    => '15',
                'recommended_max'    => '60',
                'hard_min'           => '1',
                'hard_max'           => '1440',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'onboarding.auto_escalation.max_per_run',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'Максимум подсказок за один тик (everyMinute) — защита от лавины уведомлений и Telegram rate-limit при внезапном скоплении застрявших. Остальные подхватятся на следующих тиках (one-shot не даст задвоить).',
                'effect_text'        => 'LIMIT выборки fetchStuck за тик. При throttle ~28 msg/sec реальный объём за тик невелик.',
                'above_effect_text'  => 'Выше (напр. 200): за тик обрабатываем больше застрявших, но дольше держим тик и ближе к rate-limit Telegram.',
                'below_effect_text'  => 'Ниже (напр. 5): тик короче, но при наплыве новичков очередь подсказок растягивается на несколько минут.',
                'recommended_min'    => '5',
                'recommended_max'    => '50',
                'hard_min'           => '1',
                'hard_max'           => '200',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'onboarding.auto_escalation.enabled',
            'onboarding.auto_escalation.stuck_minutes',
            'onboarding.auto_escalation.active_window_minutes',
            'onboarding.auto_escalation.max_per_run',
        ])->delete();
    }
}
