<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W7a (ADR-065) — killswitch для расширенного Robi-chain онбординга (шаги 5-7).
 *
 * default true → новые персонажи проходят шаги 5/7 «Подсказки и советы»,
 * 6/7 «Этапы прокачки», 7/7 «Готов идти». Если false → старое 4-шаговое
 * поведение (шаг 4/4 closer → startAdventure).
 *
 * Шаг 7/7 в W7a — closer без catalog promo (catalog приходит в W7b и
 * расширит handler кнопкой «📚 Что нового»).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below
 * NOT NULL. Idempotent: пропускает существующий setting_key.
 */
class W7aSeedOnboardingRobiExtendedGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'onboarding.robi_extended.enabled',
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => 'true',
            'rationale_text'     => "Killswitch для расширения Robi-онбординга 4 → 7 шагов (W7a ADR-065). После vNext (V1-V29) и vNext2 (W1-W5) на проде ~25 player-facing фич, которых старый 4-шаговый Robi не покрывает — новички не знают про /tips, milestones (lvl 5 Спец → lvl 10 фракция → Robotics cascade L1-L4 дроны → защита базы). default=true → +3 шага активны для новых чаров. false → instant rollback на старое 4/4 поведение, никаких изменений existing-чарам не нужно (их action_log уже хранит completed-шаги 1-4).",
            'effect_text'        => "GetTrainingStart4Action.handle() читает этот ключ через GameSettings: если true → nextStep='getTrainedStart5' + caption «Шаг 4/7», если false → nextStep='startAdventure' + caption «Шаг 4/4». То же для шагов 1-3 (нумерация 1/7 vs 1/4). Шаги 5/6/7 регистрируются в CallbackRoutes независимо — если killswitch=false, маршруты просто не дёргаются.",
            'above_effect_text'  => "true: новые чары видят 7 шагов Роби. Шаг 5 = объяснение /tips + Совет дня + opt-out; шаг 6 = milestones roadmap; шаг 7 = closer + CTA. ~+90 секунд к онбордингу, но игрок получает mental-model всей прогрессии до endgame.",
            'below_effect_text'  => "false: легаси 4 шага. Новичок не узнаёт про /tips и не видит milestone-roadmap → продолжает discovery через accident. Используем как emergency-rollback если шаги 5-7 вызовут confusion / drop-off в первой когорте.",
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $existing = $this->db->table('game_settings')
            ->where('setting_key', $row['setting_key'])
            ->get()
            ->getRowArray();
        if (empty($existing)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'onboarding.robi_extended.enabled')
            ->delete();
    }
}
