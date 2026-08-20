<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Цены у торговца реагируют на торговлю» (TIPS-COVERAGE, CLAUDE.md; market-01,
 * docs/specs/market-decay). После включения килсвитча `economy.market.proportional_decay_enabled`
 * цена у торговца снова заметно движется от того, что остров скупает и продаёт — раньше
 * (при `-1` за тик против миллионных счётчиков) движение было практически незаметным. Совет
 * объясняет понятие без чисел баланса (те тюнятся в `economy.market.*`).
 *
 * Idempotent по title_en='MarketDynamicPrices'. media-off + markdown-safe (парные *),
 * utf8mb4-эмодзи (колонка уже сконвертирована). game_tips = KEEP (WipeManifest).
 */
class SeedMarketDynamicPricesTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'MarketDynamicPrices';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '💰 *Цена у торговца — не константа.* Если остров много что-то продаёт скупщику — '
            . 'цена на этот ресурс падает; если много покупает — растёт. Со временем, если торговли '
            . 'нет, цена сама сползает обратно к базовой. Проверяй цену перед крупной сделкой — '
            . 'выгодный момент не длится вечно, и невыгодный тоже.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '💰 Рынок реагирует на торговлю',
            'title_en'   => $titleEn,
            'tip_type'   => 'ресурсы',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'MarketDynamicPrices')->delete();
    }
}
