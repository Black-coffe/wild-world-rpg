<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Поход вместо шагов по одной клетке» (TIPS-COVERAGE, CLAUDE.md).
 *
 * Повод — прод-обращение новичка 2026-06-19: шёл к далёкой точке шагами-стрелками,
 * сливал выносливость и ждал её восстановления («0.1 в минуту — изнурительно»). Совет
 * проактивно подсвечивает существующую (ADR-019), но плохо находимую механику: длинные
 * переходы дешевле через «🗺️ Поход», а базу вообще можно разбить на текущей клетке.
 *
 * Навигация/понятие, без хрупких чисел баланса (анти-дрейф). Автоматически в /tips и
 * «Совет дня» (TipService). Idempotent по title_en. media-off + markdown-safe. game_tips = KEEP.
 */
class SeedMarchEfficiencyTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'MarchEfficiency';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🗺️ *Долгий путь? Не шагай по одной клетке.* Если нужно далеко — на экране '
            . 'перемещения жми *«🗺️ Поход»*: задаёшь сторону и сколько клеток, и отряд идёт сам, '
            . 'по пути попадаются находки, события и встречи. Шаги стрелками по одной клетке заметно '
            . 'дороже по выносливости — для дальних переходов Поход куда выгоднее. А базу, кстати, '
            . 'можно разбить прямо там, где стоишь, — необязательно куда-то идти.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🗺️ Поход вместо шагов',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'MarchEfficiency')->delete();
    }
}
