<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\Onboarding\OnboardingChainCatalog;
use CodeIgniter\Database\Migration;

/**
 * Пере-сид ВСЕХ описаний онбординг-шагов из каталога (фикс emoji-corruption, 2026-06-20).
 *
 * `quests.description` = utf8mb3 → эмодзи в описаниях OnbStepClaimBase(🏕)/Build(🏗)/Sell(🛒💰)
 * хранились как «?» на проде (видно в «Активных квестах»). Описания сделаны emoji-free (кнопки
 * названы словами) — источник истины {@see OnboardingChainCatalog}. Этот пере-сид приводит DB к
 * каталогу для ВСЕХ 8 шагов (идемпотентно: 3 фикса + остальные no-op). Гард от повтора —
 * `OnboardingChainServiceTest::testStepDescriptionsAreUtf8mb3Safe`. Урок —
 * `feedback_emoji_content_needs_utf8mb4`.
 *
 * `quests` — контент-определение (WipeManifest=KEEP), без новых таблиц/колонок.
 */
class ReseedOnbStepDescriptionsEmojiFree extends Migration
{
    public function up(): void
    {
        foreach (OnboardingChainCatalog::steps() as $step) {
            $this->db->table('quests')
                ->where('title_en', $step['title_en'])
                ->update(['description' => $step['description']]);
        }
    }

    public function down(): void
    {
        // no-op: откат к corrupted-эмодзи (они уже были «?» на проде) бессмыслен.
    }
}
