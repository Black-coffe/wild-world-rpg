<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\Onboarding\OnboardingChainCatalog;
use CodeIgniter\Database\Migration;

/**
 * W-REG F6 — шаг «Дары пустоши» (OnbStepGather) переводится с `collect_resource` (вода в рюкзаке)
 * на `gather_resource` (вода, добытая завершённой добычей, — отметки
 * {@see \App\Services\Quest\GatheredResourceLedger}). Стартовый паёк кладёт 10 воды и закрывал
 * шаг до первой добычи с текстом «Первый ресурс в кармане!» — неправда.
 *
 * Тип/цель/количество/описание берутся из {@see OnboardingChainCatalog} (источник истины).
 * Идемпотентно: повторный up() пишет те же значения. `quests` — контент (WipeManifest=KEEP),
 * схема не меняется.
 */
class ReseedOnbStepGatherObjective extends Migration
{
    private const LEGACY_DESCRIPTION = 'Без ресурсов не выжить. Собери 5 единиц воды — самый частый и нужный ресурс. Жми кнопки добычи на клетке, где есть вода.';

    public function up(): void
    {
        $step = OnboardingChainCatalog::find(OnboardingChainCatalog::STEP_GATHER);
        if ($step === null) {
            return;
        }

        $this->db->table('quests')
            ->where('title_en', OnboardingChainCatalog::STEP_GATHER)
            ->update([
                'objective_type'   => $step['objective_type'],
                'objective_target' => $step['objective_target'],
                'objective_qty'    => $step['objective_qty'],
                'description'      => $step['description'],
            ]);
    }

    public function down(): void
    {
        $this->db->table('quests')
            ->where('title_en', OnboardingChainCatalog::STEP_GATHER)
            ->update([
                'objective_type'   => 'collect_resource',
                'objective_target' => 'Water',
                'objective_qty'    => 5,
                'description'      => self::LEGACY_DESCRIPTION,
            ]);
    }
}
