<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Где лежит вся добыча» — снимает путаницу «рюкзак vs Склад базы».
 *
 * Триггер (жалоба игрока 2026-06-26): видит почти пустой «📦 Склад базы» и думает,
 * что добыча пропадает, хотя вся она в инвентаре («Добытые ресурсы»). Склад базы
 * наполняется только карго-дроном, добыча туда не идёт. Автоматически в /tips и
 * «Совет дня» (TipService). Idempotent по title_en. media-off + markdown-safe
 * (парные `*`). tip_type='ресурсы'. game_tips = KEEP (вайп не трогает).
 */
class SeedInventoryStorageTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'InventoryVsBaseStorage';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🎒 *Куда падает добыча:* всё, что ты накопал, копится в инвентаре — раздел '
            . '*«Добытые ресурсы»* (по редкости). Это твой основной запас, и он может быть огромным. '
            . 'А *«Склад базы»* (📦) — отдельное хранилище: туда ресурсы привозит только карго-дрон, '
            . 'добыча сама туда не идёт. Поэтому если на складе базы пусто, а добываешь ты много — '
            . 'всё на месте, просто смотри в «Добытые ресурсы».';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🎒 Где лежит вся добыча',
            'title_en'   => $titleEn,
            'tip_type'   => 'ресурсы',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'InventoryVsBaseStorage')->delete();
    }
}
