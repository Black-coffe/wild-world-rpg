<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-103 Часть B Слой 1 — GameSettings для контекстных обучающих подсказок.
 *
 * Два ключа (category=world):
 *   - onboarding.contextual_hints.enabled (bool, default true) — killswitch.
 *   - onboarding.contextual_hints.max_level (int, default 6) — потолок уровня, до
 *     которого новичку показывается one-shot подсказка «первая база».
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed существующей таблицы game_settings (НЕ createTable, НЕ player-колонка) →
 * WipeManifest не затрагивается (game_settings уже KEEP). Idempotent.
 */
class ADR103SeedContextualHintsGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.contextual_hints.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch контекстных one-shot обучающих подсказок (ADR-103 Часть B Слой 1). Триггерный инцидент m8k2b: новичок не понял, что постройки строятся на базе (не в магазине) и как открыть базу. default=true → новички получают своевременную подсказку «как разбить базу и строить». false → instant rollback (existing-чарам ничего не нужно, их показы уже в action_log).',
                'effect_text'        => 'OnboardingHintService::maybeSend читает этот ключ: если false — ни одна контекстная подсказка не отправляется. Подсказки one-shot (трекинг в action_log по action_name=OnbHint_<key>) и уважают per-char opt-out (daily_tips_enabled).',
                'above_effect_text'  => 'true: при первом подходящем моменте (новичок без базы, level ≤ max_level) приходит подсказка «первая база» один раз. Снижает ранний отток из-за непонимания механики базы/стройки.',
                'below_effect_text'  => 'false: подсказки не приходят. Новичок узнаёт о базе/стройке только через accident/чат-саппорт (как m8k2b). Использовать как emergency-off, если подсказки окажутся навязчивыми.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'onboarding.contextual_hints.max_level',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => 'Потолок уровня персонажа, до которого показывается one-shot подсказка «первая база». Отсекает ветеранов (которые механику уже знают) от nudge. 6 покрывает раннюю когорту (avg lvl активных ~4.7 по прод-данным), когда база ещё актуальна, но не спамит развитых.',
                'effect_text'        => 'OnboardingHintService::maybeSendFirstBaseTip не шлёт подсказку, если level > этого значения. Подсказка всё равно one-shot и не приходит, если база уже разбита.',
                'above_effect_text'  => 'Выше (напр. 15): подсказку про базу увидят и игроки середины раннего гейма — больше охват, но риск показать очевидное тем, кто уже разобрался.',
                'below_effect_text'  => 'Ниже (напр. 2): подсказку увидят только самые свежие чары; часть новичков, дошедших до lvl 3-5 без базы, останутся без неё.',
                'recommended_min'    => 2,
                'recommended_max'    => 15,
                'hard_min'           => 1,
                'hard_max'           => 100,
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
            ->whereIn('setting_key', [
                'onboarding.contextual_hints.enabled',
                'onboarding.contextual_hints.max_level',
            ])
            ->delete();
    }
}
