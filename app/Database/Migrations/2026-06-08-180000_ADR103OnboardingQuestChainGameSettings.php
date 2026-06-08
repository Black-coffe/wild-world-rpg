<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-103 Часть B Слой 2 — killswitch обучающей квест-цепочки «Первые шаги выжившего».
 *
 * Ключ `onboarding.quest_chain.enabled` (bool, default false → DORMANT) — ОТДЕЛЬНЫЙ
 * от `quests.extended_enabled`: онбординг-цепочка катится независимо от расширенных
 * квестов (ADR-088). Контент-квесты сидятся следующей миграцией, но невидимы/не
 * прогрессят и не назначаются новичкам, пока этот killswitch OFF.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed существующей game_settings (НЕ createTable) → WipeManifest не затрагивается
 * (game_settings уже KEEP). Idempotent.
 */
class ADR103OnboardingQuestChainGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'onboarding.quest_chain.enabled',
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'rationale_text'     => 'Killswitch обучающей квест-цепочки «Первые шаги выжившего» (ADR-103 Часть B Слой 2): движение → добыча → крафт → захват базы → ОТКРЫТИЕ базы → первая постройка. ОТДЕЛЬНЫЙ от quests.extended_enabled — онбординг катится независимо. default=false → dormant: контент засижен, но цепочка не назначается новичкам и не прогрессит до активации после Tier-3.',
            'effect_text'        => 'OnboardingChainService::ensureChainAssigned (на /start) назначает новичку первый шаг ТОЛЬКО при ON. QuestObjectiveHandler добавляет онбординг-шаги (title_en LIKE OnbStep%) в обработку и засчитывает новые objective-типы (claim_base/open_base_screen/craft_any/any_building) ТОЛЬКО при ON. recordBaseOpened пишет event-флаг открытия базы ТОЛЬКО при ON.',
            'above_effect_text'  => 'true: новички (level ≤ onboarding.contextual_hints.max_level) на /start получают цепочку «Первые шаги выжившего» и ведутся по этапам с just-in-time подсказками после каждого шага. Прямой удар по корневому драйверу раннего оттока (инцидент m8k2b: новичок не понял базу/стройку).',
            'below_effect_text'  => 'false: цепочка не назначается и не прогрессит (dormant). Новички остаются без структурированного обучения — только контекстные one-shot подсказки Слоя 1 (если их killswitch ON). Использовать как emergency-off, если цепочка окажется навязчивой/багованной.',
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
            ->where('setting_key', 'onboarding.quest_chain.enabled')
            ->delete();
    }
}
