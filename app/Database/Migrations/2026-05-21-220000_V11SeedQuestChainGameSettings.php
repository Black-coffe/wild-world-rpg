<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V11 (vNext, Фаза 3, ADR-036) — killswitch цепочек квестов (admin-tunable, ADR-024).
 *
 * `quests.chains_enabled` (bool, default true): true — prerequisite-гейт активен
 * (квест с predусловием скрыт, пока не завершён prerequisite_quest); false — гейт
 * отключён, все квесты доступны как раньше (страховка, если цепочка кого-то
 * заблокирует ошибочно). category=world (квесты — мировой контент). Idempotent.
 */
class V11SeedQuestChainGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $key = 'quests.chains_enabled';

        $exists = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => 'true',
            'rationale_text'     => 'Killswitch цепочек квестов. true — квест с prerequisite_quest скрыт, пока персонаж не завершит предусловие (многоэтапные цепочки V12/V13). false — гейт отключён, все квесты доступны как до V11.',
            'effect_text'        => 'QuestChainService::chainsEnabled. false → AvailableQuests показывает все квесты без проверки prerequisite_quest.',
            'above_effect_text'  => 'true (вкл): цепочки/T3→T4-gating работают.',
            'below_effect_text'  => 'false (выкл): мгновенный откат гейта без редеплоя, если цепочка ошибочно блокирует игроков.',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'quests.chains_enabled')->delete();
    }
}
