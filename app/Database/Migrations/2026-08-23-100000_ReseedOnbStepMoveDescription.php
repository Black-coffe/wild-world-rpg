<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\Onboarding\OnboardingChainCatalog;
use CodeIgniter\Database\Migration;

/**
 * Пере-сид описания шага OnbStepMove (Move-нудж, пере-срез A+B 2026-06-20).
 *
 * Исходный seed (`ADR103SeedOnboardingQuestChain`) идемпотентен ПО title_en → не обновляет
 * description уже существующих строк. Текст шага «Первая вылазка» теперь называет КОНКРЕТНЫЕ
 * кнопки («🧭 Двигаться» / «🗺 Поход») — холодный старт OnbStepMove (38/67=57%: получают шаг
 * «двигайся», но не знают как). Источник истины — {@see OnboardingChainCatalog} (DRY, без
 * дублирования текста); этот же description пере-подаёт E4 авто-эскалация при застревании.
 *
 * Idempotent: UPDATE к фиксированному значению из каталога. quests — контент-определение
 * (WipeManifest=KEEP), новых таблиц/колонок нет.
 */
class ReseedOnbStepMoveDescription extends Migration
{
    public function up(): void
    {
        $step = OnboardingChainCatalog::find(OnboardingChainCatalog::STEP_MOVE);
        if ($step === null) {
            return;
        }

        $this->db->table('quests')
            ->where('title_en', OnboardingChainCatalog::STEP_MOVE)
            ->update(['description' => $step['description']]);
    }

    public function down(): void
    {
        // Откат к прежнему тексту (без названий кнопок).
        $this->db->table('quests')
            ->where('title_en', OnboardingChainCatalog::STEP_MOVE)
            ->update(['description' => 'Пустошь не изучить, стоя на месте. Сделай первые шаги — переместись на 3 новые клетки. Каждый шаг открывает кусочек карты вокруг.']);
    }
}
