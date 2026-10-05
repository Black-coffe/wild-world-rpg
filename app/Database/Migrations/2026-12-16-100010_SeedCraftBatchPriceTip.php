<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Партия стоит как все её штуки» (TIPS-COVERAGE, CLAUDE.md; craft-batch-price-confirm).
 *
 * Жалоба 05.10.2026: 50 сапёрных лопат одним нажатием — 59 часов и 300 000 золота, «куда деньги ушли».
 * Время и цена партии — время и цена одной штуки, умноженные на количество; крупную партию игра теперь
 * показывает итогом и просит подтвердить. Соседний совет `CraftQuantityBatch` рассказывает, ГДЕ кнопки
 * количества; этот — ЧТО партия стоит. Не дубль.
 *
 * Idempotent по title_en='CraftBatchPrice'. Без чисел баланса (пороги живут в GameSettings), media-off
 * самодостаточен, markdown-safe (парные *), категория «крафт» из 14 ENUM. game_tips = KEEP (WipeManifest).
 */
class SeedCraftBatchPriceTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'CraftBatchPrice';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🧾 *Партия стоит как все её штуки.* Золото, сырьё и время крафта считаются за одну вещь и '
            . 'умножаются на количество: партия из полусотни займёт в полсотни раз дольше одной штуки, а постройки '
            . 'и специализация ускоряют каждую. Если партия и крупная, и дорогая, я сначала покажу итог — сколько '
            . 'уйдёт золота, сырья и часов — и запущу только после *✅ Запустить*. Передумал — жми *↩️ Изменить кол-во*.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🧾 Сколько стоит партия крафта',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'CraftBatchPrice')->delete();
    }
}
