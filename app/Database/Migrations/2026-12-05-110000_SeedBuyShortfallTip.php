<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Докупить недостающее одной кнопкой» (TIPS-COVERAGE, CLAUDE.md; спрос-01).
 *
 * Аудит прода за 30 дней: покупались ровно 10 ресурсов из 80, все id 1–10. Кнопка «Купить»
 * на экране нехватки при стройке вела на общий выбор редкости — игрок должен был сам помнить,
 * какая редкость у глины, — а на экране крафта вела на ресурс, но не на нужное количество.
 * Теперь обе ведут прямо на недостачу; совет делает это находимым.
 *
 * Idempotent по title_en='BuyShortfall'. media-off самодостаточен, markdown-safe (парные *),
 * категория «ресурсы» из 14 ENUM. game_tips = KEEP (WipeManifest).
 */
class SeedBuyShortfallTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'BuyShortfall';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🎯 *Не хватило сырья — не ходи в магазин кругом.* Когда крафт или стройка '
            . 'отвечают «не хватает», экран сам считает недостачу и даёт кнопку *«🛒 Ресурс ×N»* '
            . 'ровно на это количество. Один тап — и ты уже видишь цену за столько, сколько нужно, '
            . 'вместо того чтобы вспоминать редкость ресурса и считать в уме.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🎯 Докупить недостающее одной кнопкой',
            'title_en'   => $titleEn,
            'tip_type'   => 'ресурсы',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'BuyShortfall')->delete();
    }
}
