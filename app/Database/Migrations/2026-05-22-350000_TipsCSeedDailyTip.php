<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-038 Фаза C8 — совет B18 «Совет дня».
 *
 * Намеренно отложен из seed Фазы B: совет описывает фичу авто-рассылки, которая
 * появляется только в этом деплое (C1-C5). Вставка после того, как фича существует —
 * чтобы не было «lying description» (урок S29). Idempotent по title_en.
 */
class TipsCSeedDailyTip extends Migration
{
    public function up(): void
    {
        $titleEn = 'DailyTip';
        $exists  = $this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('game_tips')->insert([
            'title_en'   => $titleEn,
            'title_ru'   => '📌 Совет дня',
            'tip_type'   => 'настройки',
            'content'    => '📌 *Совет дня:* раз в сутки Роби сам пришлёт случайный совет — и микро-прокачку! Не нужно — отключи в разделе «⚙️ Настройки». А хочешь больше советов прямо сейчас — зови /tips вручную!',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'DailyTip')->delete();
    }
}
