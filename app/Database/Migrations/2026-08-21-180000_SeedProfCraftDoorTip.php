<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет: «Проф. крафт» и «Верстаки» — разные двери.
 *
 * Повод — жалоба из Bugs-info (21.08.2026): игрок без цеха жал в хабе крафта кнопку
 * «Профессиональный крафт» и попадал на сборку самого верстака, читая это как дубль
 * соседней кнопки «Верстаки». Экран теперь честно заперт замком, а совет проговаривает
 * разницу проактивно — до того, как игрок споткнётся.
 *
 * Про навигацию и понятия, без чисел баланса (анти-дрейф). markdown-safe (парные *),
 * media-off самодостаточен, категория из ENUM. Идемпотентно по title_en.
 */
class SeedProfCraftDoorTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'ProfCraftVsWorkbenches';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🔒 *«Проф. крафт» и «Верстаки» — разные двери.* В хабе крафта кнопка *«Верстаки»* — это '
            . 'сборка самих рабочих мест: Верстак 1 и Профессиональный верстак (цех). А соседняя кнопка '
            . '*«Проф. крафт»* — сам раздел вещей высшего тира. Пока цеха нет, она стоит с замком и '
            . 'показывает, чего не хватает для сборки. Соберёшь цех — замок пропадёт, и за той же кнопкой '
            . 'откроются оружие, броня, медицина и утилиты высшего тира.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🔒 Проф. крафт и Верстаки — разные двери',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'ProfCraftVsWorkbenches')->delete();
    }
}
