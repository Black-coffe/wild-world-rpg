<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет про финальный каркас нижнего меню (ADR-150 ФИНАЛ).
 *
 * Отличается от WorldHubNav / TasksHubNav / MoreHubNav / CraftBaseNav: те объясняют, что ВНУТРИ
 * конкретной группы. Этот — про саму панель: шесть кнопок = шесть мест, и куда какая ведёт.
 * Именно её игрок видит постоянно, и именно она поменялась целиком.
 *
 * Совет про навигацию/понятия, БЕЗ чисел баланса. markdown-safe (парные *), utf8mb4-эмодзи.
 * Idempotent (по title_en). tip_type='общие' (валидное ENUM).
 */
class SeedNavGridFinalTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'NavGridFinal';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🧭 *Шесть кнопок внизу — это шесть мест, а не шесть команд.* '
            . '*🌍 Мир* — компас ходьбы и всё, что происходит на твоей клетке. '
            . '*🧑 Я* — персонаж, инвентарь, экипировка, аптечка. '
            . '*🔨 Крафт* — верстаки, три уровня крафта и ремонт инструментов. '
            . '*🏠 База* — стройка, постройки, склад и маяки. '
            . '*📋 Дела* — что идёт прямо сейчас, квесты и задания дня. '
            . '*⚙️ Ещё* — магазин, арена, развлечения, справочник и настройки. '
            . 'Панель пропала? Напиши /menu — она вернётся.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🧭 Шесть кнопок внизу — шесть мест',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'NavGridFinal')->delete();
    }
}
