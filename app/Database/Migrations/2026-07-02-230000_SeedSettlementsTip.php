<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 1 — discoverability-намёк /tips о статических поселениях.
 *
 * Сигналит игроку, что в пустоши есть постоянные обжитые места (Перекрёсток — нейтральный
 * безопасный аутпост) с торговлей/ремонтом, и что их видно на карте значком 🏚. Конституция
 * UX-DISCOVERABILITY. markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 */
class SeedSettlementsTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'Settlements';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🏚 *Обжитые места:* пустошь не вся дикая — на скрещении дорог стоит *Перекрёсток*, '
            . 'нейтральный аутпост. Там действует уговор: оружие в ножны, на местных не нападают. '
            . 'Сюда приходят перевести дух, поторговать с маркитантом и починить снаряжение у кузнеца. '
            . 'Ищи значок 🏚 на карте и зайди — в безопасной зоне можно не бояться удара в спину. '
            . 'Говорят, по острову есть и другие места: чьи-то оплоты и логова, куда так просто не суются.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🏚 Обжитые места',
            'title_en'   => $titleEn,
            'tip_type'   => 'world',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'Settlements')->delete();
    }
}
