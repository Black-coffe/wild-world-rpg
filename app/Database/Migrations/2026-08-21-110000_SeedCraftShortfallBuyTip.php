<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * craft-shortfall-buy-11 — совет дня про докупку недостающего с карточки крафта.
 *
 * Сигналит игроку, что тупик «не хватает одного материала» больше не тупик: экран нехватки на
 * карточке крафта показывает блок «🛒 Докупить у торговца» — сколько чего не хватает и во сколько
 * обойдётся закрыть остаток, включая наценку торговца за срочность, до сделки. Без чисел наценки
 * (live-tunable из GameSettings) и без слова «опт» (занято оптовой продажей, ADR-096). Новый ракурс
 * относительно совета про честные цены торговца: этот — про сам путь докупки с карточки крафта,
 * а не про то, как читать цену. markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 */
class SeedCraftShortfallBuyTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'CraftShortfallBuyFromRecipeCard';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🛠 Не хватает одного материала до крафта — не повод откладывать рецепт. Открой '
            . '*«Крафт»*, зайди в карточку нужного предмета: если материалов впритык, там появится блок '
            . '*«🛒 Докупить у торговца»*. Он честно покажет, чего не хватает, сколько это стоит с наценкой '
            . 'торговца за срочность и сколько золота останется после сделки — прежде чем ты на что-то '
            . 'согласишься. Останется своё — списывается первым, докупается только разница.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🛒 Докупить недостающее прямо с карточки крафта',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'CraftShortfallBuyFromRecipeCard')->delete();
    }
}
