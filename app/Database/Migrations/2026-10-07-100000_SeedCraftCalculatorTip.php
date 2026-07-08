<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет-указатель на публичный калькулятор крафта (ADR-149).
 *
 * Новая веб-фича `wildworld.fun/kalkulyator-krafta` (Поток E1): игрок выбирает предмет+количество,
 * калькулятор разворачивает весь рецепт до первичного сырья + суб-крафтов + ориентир по золоту.
 * Совет закрывает discoverability из Telegram (иначе про инструмент узнают только зайдя на сайт).
 *
 * Про навигацию/понятия (что за инструмент, где найти), БЕЗ хрупких чисел баланса. markdown-safe
 * (парные *), utf8mb4-эмодзи. Idempotent (по title_en). tip_type='крафт' (валидное ENUM-значение).
 */
class SeedCraftCalculatorTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'CraftCalculatorSite';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🧮 *Калькулятор крафта на сайте:* планируешь большой крафт — загляни на '
            . '*wildworld.fun/kalkulyator-krafta*. Выбираешь предмет и количество, а калькулятор '
            . 'разворачивает весь рецепт до первичного сырья: сколько чего добыть, какие промежуточные '
            . 'детали скрафтить и во сколько примерно обойдётся докупить сырьё у торговца. Удобно '
            . 'заранее прикинуть, что запасать. Ищи *🧮 Калькулятор* в меню сайта.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🧮 Калькулятор крафта на сайте',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'CraftCalculatorSite')->delete();
    }
}
