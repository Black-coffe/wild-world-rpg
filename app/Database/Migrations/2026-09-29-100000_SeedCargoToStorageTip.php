<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Slice 2 (инициатива «ресурс-грамотность») — совет «Как завезти на склад базы».
 *
 * Дополняет SeedInventoryStorageTip (Slice 0, «где лежит добыча»): тот объясняет,
 * что добыча в рюкзаке, а не на складе; этот — КАК доставить ресурсы на склад
 * (только карго-дрон: Мастерская роботов L2 → крафт → отправка). Автоматически
 * попадает в /tips и «Совет дня» (TipService). Idempotent по title_en.
 * media-off + markdown-safe (парные `*`). tip_type='ресурсы'. game_tips = KEEP.
 */
class SeedCargoToStorageTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'CargoDroneToStorage';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🚚 *Как завезти ресурсы на склад базы:* добыча сама на склад не попадёт — '
            . 'её отвозит только *карго-дрон*. Сначала построй *Мастерскую робототехники* до 2 уровня '
            . 'и скрафти карго-дрон («Крафт» → «Стандартный верстак»). Потом жми *«🚚 Карго-дрон»*, '
            . 'выбери ресурс — он увезёт груз на склад с любой клетки. Забрать обратно: *«📦 Склад»* → '
            . '*«🎒 Забрать всё»*, стоя на своей базе.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🚚 Как завезти ресурсы на склад',
            'title_en'   => $titleEn,
            'tip_type'   => 'ресурсы',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'CargoDroneToStorage')->delete();
    }
}
