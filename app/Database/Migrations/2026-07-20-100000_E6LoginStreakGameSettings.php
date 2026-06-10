<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 3 — стрик входа (мягко, без FOMO).
 *
 *   • returnability.streak.enabled (bool, default false → DORMANT) — killswitch;
 *   • returnability.streak.grace_days (int, 1) — сколько дней пропуска НЕ обнуляют серию;
 *   • returnability.streak.base_gold (int, 50) — награда за день 1 (и день сброса);
 *   • returnability.streak.bonus_per_day (int, 25) — прибавка золота за каждый день серии;
 *   • returnability.streak.max_gold (int, 500) — потолок дневной награды.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class E6LoginStreakGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'returnability.streak.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch стрика входа (ADR-108 Ф3): на ПЕРВОМ взаимодействии нового дня игрок получает награду за серию входов подряд (мягко, без FOMO — пропуск ≤ grace_days не обнуляет). default=false → dormant. Цель — возвращаемость/привычка (E1: back-D1 ≈ 18%): повод заходить каждый день.',
                'effect_text'        => 'BotController::webhook() ДО handle() зовёт LoginStreakService::maybeReward. One-shot per день (login_streak_last_day==today → no-op). Серия видна в карточке Перса. Награда = base_gold + bonus_per_day×(день−1), cap max_gold.',
                'above_effect_text'  => 'true: ежедневный заход вознаграждается, серия растёт → выше возвращаемость. Инъекция золота контролируется base/bonus/cap (мягкие значения, gold-sink'."'".'ов в игре достаточно).',
                'below_effect_text'  => 'false: стрик не начисляется и не показывается (dormant). Emergency-off.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'returnability.streak.grace_days',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'Сколько пропущенных дней НЕ обнуляют серию (мягкость, анти-FOMO). 1 = можно пропустить один день и серия продолжится — снимает стресс «не разорвать стрик», но сохраняет смысл регулярности.',
                'effect_text'        => 'computeStreak: если между входами прошло ≤ 1+grace_days дней → серия +1; больше → сброс к 1.',
                'above_effect_text'  => 'Выше (напр. 3): прощаем до 3 пропущенных дней — очень мягко, но «серия» теряет смысл (почти не рвётся).',
                'below_effect_text'  => 'Ниже (0): жёстко — любой пропуск дня обнуляет серию (классический стрик, выше FOMO-давление).',
                'recommended_min'    => 0,
                'recommended_max'    => 3,
                'hard_min'           => 0,
                'hard_max'           => 14,
            ],
            [
                'setting_key'        => 'returnability.streak.base_gold',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Награда золотом за день 1 серии (и за день после сброса). 50 = заметно новичку (старт 1000), но не ломает экономику. Базовая ступень дневной награды.',
                'effect_text'        => 'computeReward: reward = base_gold + bonus_per_day×(день−1), не выше max_gold.',
                'above_effect_text'  => 'Выше: щедрее старт серии — сильнее тянет заходить, но больше инфляция золота.',
                'below_effect_text'  => 'Ниже (0): первый день без награды — стрик стартует «в долг», слабее крючок.',
                'recommended_min'    => 10,
                'recommended_max'    => 200,
                'hard_min'           => 0,
                'hard_max'           => 100000,
            ],
            [
                'setting_key'        => 'returnability.streak.bonus_per_day',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Прибавка золота за каждый следующий день серии. 25 = заметный, но плавный рост (день 7 ≈ base+150), мотивирует продолжать без галопирующей инфляции (упирается в max_gold).',
                'effect_text'        => 'computeReward: каждый день серии добавляет это к награде (линейно), пока не достигнут cap max_gold.',
                'above_effect_text'  => 'Выше: быстрее растёт награда → сильнее тяга длить серию, но раньше упирается в cap и больше золота в экономику.',
                'below_effect_text'  => 'Ниже (0): награда фиксирована (=base каждый день), серия не даёт растущего стимула.',
                'recommended_min'    => 0,
                'recommended_max'    => 100,
                'hard_min'           => 0,
                'hard_max'           => 100000,
            ],
            [
                'setting_key'        => 'returnability.streak.max_gold',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 500,
                'default_value_text' => '500',
                'rationale_text'     => 'Потолок дневной награды за стрик. 500 = достигается ~на 19-й день подряд (base 50 + 25×18), дальше награда не растёт — анти-инфляционный предохранитель при длинных сериях.',
                'effect_text'        => 'computeReward клампит итог сверху этим значением.',
                'above_effect_text'  => 'Выше: длинные серии дают больше золота (риск инфляции у самых преданных игроков).',
                'below_effect_text'  => 'Ниже: награда упирается в потолок раньше — стимул длить серию ослабевает после достижения cap. 0 = без потолка (не рекомендуется).',
                'recommended_min'    => 100,
                'recommended_max'    => 2000,
                'hard_min'           => 0,
                'hard_max'           => 1000000,
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
            'returnability.streak.enabled',
            'returnability.streak.grace_days',
            'returnability.streak.base_gold',
            'returnability.streak.bonus_per_day',
            'returnability.streak.max_gold',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
