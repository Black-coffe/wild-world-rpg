<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет-указатель на хаб «⚙️ Ещё» (ADR-150 Слайс 4).
 *
 * Триггер: аудит firehose 2026-07-09 — «🏟 Арена» включена (`pvp.duel.enabled=1`), но за всё
 * время НОЛЬ тапов (вход только из хаба поселения и из Рейтинга, открывающегося лишь из Арены);
 * Оракул — 3 тапа, лежал на дне цепочки «Окопаться → Развлечения → Оракул». Совет закрывает
 * discoverability: называет кнопку и то, что за ней лежит.
 *
 * Про навигацию/понятия, БЕЗ хрупких чисел баланса. Не обещает dormant-фич: Арена названа как
 * добровольные дуэли (её killswitch на проде включён), Оракул упомянут как ставки внутри
 * «Развлечений». markdown-safe (парные *), utf8mb4-эмодзи. Idempotent (по title_en).
 * tip_type='общие' (валидное ENUM).
 */
class SeedMoreHubTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'MoreHubNav';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '⚙️ *За кнопкой «Ещё»* внизу экрана спрятано всё, что не про ходьбу и не про дела: '
            . '*Магазин* (продать добычу, купить нужное), *Арена* — добровольные дуэли с живыми '
            . 'игроками, где статы уравнены и ты ничего не теряешь, *Развлечения* с играми и ставками '
            . 'у Оракула, твоя *фракция*, *Путь новичка* и *Настройки*. Раньше Арену можно было найти '
            . 'только забредя в поселение — теперь она в одном тапе.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '⚙️ Что спрятано за кнопкой «Ещё»',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'MoreHubNav')->delete();
    }
}
