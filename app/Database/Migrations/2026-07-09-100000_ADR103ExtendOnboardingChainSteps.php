<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\Onboarding\OnboardingChainCatalog;
use CodeIgniter\Database\Migration;

/**
 * E3 (ROADMAP-100) — расширение обучающей цепочки ADR-103: +2 шага поверх исходных
 * шести (OnbStepSell «Первая сделка» sell_any → OnbStepLevel5 «Закалённый новичок»
 * level_milestone=5, выпускной).
 *
 * Логика 1:1 с исходной seed-миграцией ADR103SeedOnboardingQuestChain: идёт по
 * АКТУАЛЬНОМУ OnboardingChainCatalog::steps() (источник истины), вставляет
 * отсутствующие по title_en, prerequisite = предыдущий шаг каталога. На уже
 * засиженных БД доливает только новые шаги (OnbStepBuild получает продолжение
 * цепочки через prerequisite у OnbStepSell); на свежих — исходная миграция засеет
 * все 8, эта станет no-op. Idempotent в обе стороны.
 */
class ADR103ExtendOnboardingChainSteps extends Migration
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
        $this->db->table('quests')
            ->whereIn('title_en', [OnboardingChainCatalog::STEP_SELL, OnboardingChainCatalog::STEP_LEVEL5])
            ->delete();
    }
}
