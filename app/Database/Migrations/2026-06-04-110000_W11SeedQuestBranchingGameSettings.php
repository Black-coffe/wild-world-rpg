<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W11 (vNext2, Фаза 3, ADR-067) — killswitch branching-движка (admin-tunable, ADR-024).
 *
 * `quests.branching_enabled` (bool, default **false** dormant): true — branch_group
 * квесты предлагают игроку взаимоисключающий ВЫБОР пути (одна ветка, остальные навсегда
 * закрыты); false — branching dormant (branch_group квесты не предлагаются вовсе,
 * 0 player-эффекта). Ship dormant зеркало W9: активация батчем в конце ROADMAP с анонсом.
 * category=world (квесты — мировой контент). Idempotent.
 */
class W11SeedQuestBranchingGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $key = 'quests.branching_enabled';

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
            'rationale_text'     => 'Killswitch ветвящихся квест-цепочек (ADR-067). true — квест-развилка (несколько квестов с общим branch_group и prerequisite_quest) предлагает игроку выбрать ОДИН путь, остальные ветки навсегда закрываются. false — dormant: branch_group квесты не предлагаются. Ship dormant (W11 foundation), активация в конце ROADMAP с анонсом.',
            'effect_text'        => 'QuestChainService::branchingEnabled. true → pendingBranchOptions/pendingBranchesForCharacter возвращают опции выбора (AvailableQuests hub + уведомление QuestObjectiveHandler рендерят кнопки развилки); chooseBranch фиксирует выбор. false → пусто (развилки невидимы).',
            'above_effect_text'  => 'true (вкл): игроки видят и проходят развилки — реиграбельность, агентность, разные награды веток (W12 фракционные ветви, W13 story-arc).',
            'below_effect_text'  => 'false (выкл, текущий default): мгновенный dormant без редеплоя; линейные цепочки V11/V12/V13 не затронуты (branch_group=null авто-назначается как прежде).',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'quests.branching_enabled')->delete();
    }
}
