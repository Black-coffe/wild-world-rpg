<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Как работает скорость Похода» (TIPS-COVERAGE, CLAUDE.md).
 *
 * Повод — вопрос игрока Max Syskov 2026-07-05: «какая реальная скорость похода, как
 * высчитывается? за 6 минут прошёл всего 2 клетки, здоровье/выносливость 100, но всё
 * равно медленно». Игроку неясна логика: он ждёт «высокая стата → быстрее». На деле темп
 * ПОСТОЯННЫЙ (~минута-две на клетку, привязка к игровому циклу → лёгкие колебания) и НЕ
 * зависит от ❤️/💤 — они топливо, а не двигатель.
 *
 * Отдельный ракурс от существующего MarchEfficiency (тот про «Поход вместо шагов»), не
 * дубль. Навигация/понятие, без хрупких чисел баланса (анти-дрейф). Автоматически в /tips
 * и «Совет дня». Idempotent по title_en. media-off + markdown-safe. game_tips = KEEP.
 */
class SeedMarchSpeedTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'MarchSpeed';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🚜 *Почему Поход идёт с одной скоростью?* Отряд движется примерно клетку за '
            . 'минуту-две, и темп *постоянный* — он не ускоряется от высокого здоровья или '
            . 'выносливости. ❤️ и 💤 — это *топливо* в пути (тратятся за каждую клетку), а не '
            . '«двигатель». Момент каждого шага привязан к игровому циклу, поэтому иногда шаг '
            . 'быстрее, иногда чуть дольше — это нормально. Нужно далеко — жми «🗺️ Поход» и '
            . 'задавай больше клеток: отряд дойдёт сам, пока ты занят другими делами.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🚜 Скорость Похода',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'MarchSpeed')->delete();
    }
}
