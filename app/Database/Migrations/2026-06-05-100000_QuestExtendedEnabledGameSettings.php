<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-088 — killswitch расширенной системы квестов (admin-tunable, ADR-024).
 *
 * `quests.extended_enabled` (bool, default **false** dormant): true — новые
 * STANDALONE objective-квесты (collect_resource / building_level / level_milestone /
 * npc_kills) видны в «Доступных квестах», стартуются вручную и прогрессят в
 * QuestObjectiveHandler. false — dormant: контент засеян, но невидим/не прогрессит
 * (0 player-эффекта). Ship dormant, активация после Tier-3. category=world. Idempotent.
 */
class QuestExtendedEnabledGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $key = 'quests.extended_enabled';

        $exists = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'rationale_text'     => 'Killswitch расширенной системы квестов (ADR-088): новые STANDALONE objective-квесты — «собери всё» (collect_resource), фермерство/защита базы (building_level), майлстоуны роста (level_milestone), драки с NPC (npc_kills, Фаза 2). true — видны/стартуются/прогрессят; false — dormant. Ship dormant, активация после Tier-3 смоука.',
            'effect_text'        => 'QuestChainService::extendedEnabled + isExtendedStartableRoot. true → AvailableQuests листит расширенные «корни» с кнопкой старта (GenericQuestStartAction создаёт quest_steps), QuestObjectiveHandler прогрессит collect_resource/building_level/level_milestone. false → расширенные типы исключены и из UI, и из обработки.',
            'above_effect_text'  => 'true (вкл): игроки получают десятки новых квестов на все уровни (сбор/стройка/фермерство/рост) — больше целей, ретеншн, ориентиры прогрессии.',
            'below_effect_text'  => 'false (выкл, текущий default): мгновенный dormant без редеплоя; существующие квесты V11/V12/W11/W13 не затронуты (0 регрессии).',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'quests.extended_enabled')->delete();
    }
}
