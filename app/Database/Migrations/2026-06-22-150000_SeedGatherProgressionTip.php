<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-090 «Мягкий старт» — совет про прогрессию добычи (коммуникация).
 *
 * Объясняет игроку, почему на старте собираются в основном вода/базовые материалы и как набор
 * расширяется с уровнем (закрывает вопрос «почему только вода?»). Автоматически в /tips и
 * «Совет дня» (TipService). Idempotent по title_en. media-off+markdown-safe. game_tips = KEEP.
 */
class SeedGatherProgressionTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'GatherProgression';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '⛏️ *Что собирается при добыче:* набор ресурсов зависит от биома И твоего уровня. '
            . 'На старте доступны вода и самые базовые материалы — это нормально. Чем выше уровень, '
            . 'тем больше видов открывается (песок, древесина, камень, руды, минералы) и тем щедрее '
            . 'добыча. Хочешь разнообразия — расти и осваивай новые биомы: каждый богат на своё.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '⛏️ Почему сначала только вода',
            'title_en'   => $titleEn,
            'tip_type'   => 'ресурсы',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'GatherProgression')->delete();
    }
}
