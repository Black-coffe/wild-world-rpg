<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S7 (ROADMAP-RETENTION-10, ADR-145) — совет «Остров живёт» — учит, что на карте есть честный
 * пульс активности реальных выживших.
 *
 * Дополняет ONBOARDING/GUIDE: проактивный напоминатель (TipService «Совет дня» + /tips), что
 * мир обитаем и это видно. media-off + markdown-safe (парные `*`). tip_type='общие' (валидный
 * ENUM). Idempotent по title_en. game_tips = KEEP. Про навигацию/понятие, не числа баланса.
 */
class S7SeedLivingIslandTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'LivingIsland';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🌍 *Остров живёт — и это видно.* На экране *«Карта»* нажми *«🌍 Остров живёт»*: '
            . 'там честный пульс — сколько клеток разведали выжившие за сутки, как давно и где '
            . 'подняли последнюю базу, сколько новичков высадилось за неделю. Все цифры настоящие, '
            . 'без накруток: это следы таких же игроков, как ты. Выживших немного — но ты точно не один.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🌍 Остров живёт — загляни в пульс',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'LivingIsland')->delete();
    }
}
