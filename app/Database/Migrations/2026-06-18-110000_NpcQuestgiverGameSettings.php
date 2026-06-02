<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 4 — killswitch NPC-квестгиверов (ADR-024).
 *
 * `npc.questgiver_enabled` (bool, default **false** dormant): при ON в экране встречи у NPC
 * с заданным offers_quest_title_en появляется «📜 Задание» — приём создаёт quest_step
 * (движок ADR-088). Зависит также от npc.interaction_enabled и активности самого квеста
 * (quests.extended_enabled). category=world. Idempotent.
 */
class NpcQuestgiverGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $key = 'npc.questgiver_enabled';
        if (! empty($this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray())) {
            return;
        }
        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'rationale_text'     => 'Killswitch NPC-квестгиверов (ADR-089 Фаза 4): нейтральные NPC выдают квесты через опцию «📜 Задание» в экране встречи. Связывает систему встреч (ADR-089) и квестов (ADR-088): квест берётся у живого NPC в мире, а не из меню. Ship dormant.',
            'effect_text'        => 'NpcInteractionService::offeredQuestFor/acceptOfferedQuest. ON → если NPC имеет offers_quest_title_en и квест доступен (active, startable-root, по уровню, не начат) — кнопка «📜 Задание»; приём → quest_step. OFF → кнопки нет.',
            'above_effect_text'  => 'ON: квесты получают «лицо» — их выдают NPC в мире, иммерсивнее, повод исследовать и встречать.',
            'below_effect_text'  => 'OFF (default): мгновенный dormant; квесты берутся как раньше (через меню AvailableQuests), NPC квестов не выдают.',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'npc.questgiver_enabled')->delete();
    }
}
