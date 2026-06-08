<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\Onboarding\OnboardingChainCatalog;
use CodeIgniter\Database\Migration;

/**
 * ADR-103 Часть B Слой 2 — контент обучающей цепочки «Первые шаги выжившего» (dormant).
 *
 * 6 связанных квестов (OnbStep*), линейная цепочка: prerequisite каждого = title_en
 * предыдущего (первый — без prerequisite, его назначает OnboardingChainService на /start;
 * остальные авто-advance движком QuestChainService). Источник истины шагов —
 * {@see OnboardingChainCatalog} (нет дрейфа БД↔код).
 *
 * status='active', но цепочка невидима/не прогрессит, пока `onboarding.quest_chain.enabled`
 * = OFF (гейт в OnboardingChainService + QuestObjectiveHandler). objective_type — VARCHAR,
 * схема не меняется (ADR-088). faction_id опущен → DB default NULL (не фракционный).
 * Idempotent (по title_en).
 */
class ADR103SeedOnboardingQuestChain extends Migration
{
    public function up(): void
    {
        $prev = null;
        foreach (OnboardingChainCatalog::steps() as $step) {
            $row = [
                'title_ru'           => $step['title_ru'],
                'title_en'           => $step['title_en'],
                'description'        => $step['description'],
                'status'             => 'active',
                'min_level'          => 1,
                'reward_type'        => 'gold',
                'reward'             => $step['reward'],
                'prerequisite_quest' => $prev,
                'objective_type'     => $step['objective_type'],
                'objective_target'   => $step['objective_target'],
                'objective_qty'      => $step['objective_qty'],
                'branch_group'       => null,
                'branch_label'       => null,
            ];

            $exists = $this->db->table('quests')
                ->where('title_en', $step['title_en'])
                ->get()
                ->getRowArray();
            if (empty($exists)) {
                $this->db->table('quests')->insert($row);
            }

            $prev = $step['title_en'];
        }
    }

    public function down(): void
    {
        $titles = [];
        foreach (OnboardingChainCatalog::steps() as $step) {
            $titles[] = $step['title_en'];
        }
        $this->db->table('quests')->whereIn('title_en', $titles)->delete();
    }
}
