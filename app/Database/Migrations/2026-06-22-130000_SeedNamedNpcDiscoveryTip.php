<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Phase 6 (контент-пасс) — discoverability-намёк на именных NPC через /tips.
 *
 * Именные NPC (Варга/Тень/Дитрих/Марта + Корвин) стоят на 5 фиксированных home-клетках в
 * огромной карте → находятся только исследованием. Конституционное правило UX-DISCOVERABILITY:
 * фича должна быть НАХОДИМА. Этот совет сигналит игроку, что именные выжившие существуют, чем
 * полезны (ветвящийся диалог / faction-реакция / квесты / последствия слов) и что к ним можно
 * вернуться (не кочуют). Честный намёк: точные координаты НЕ раскрываются (сохраняем разведку),
 * но игрок знает, что искать и зачем.
 *
 * Совет автоматически попадает в /tips и в авто-рассылку «Совет дня» (общий TipService).
 * Контент media-off-safe (всё в тексте) + markdown-safe (парные *, без одиночных _/*, без
 * апострофов внутри single-quoted строк — урок Tips V25). utf8mb4 уже сконвертирован (Tips Фаза A).
 * Idempotent: INSERT только если нет строки с тем же title_en. game_tips = KEEP в WipeManifest.
 */
class SeedNamedNpcDiscoveryTip extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $titleEn = 'NamedSurvivors';
        $exists  = $this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray();
        if (! empty($exists)) {
            return; // idempotent
        }

        $content = '🎭 *Именные выжившие:* в обжитых землях осели не только безымянные бродяги. '
            . 'Где-то ждут вербовщики четырёх фракций — Варга (Милитари), Тень (Партизаны), '
            . 'Дитрих (Инженеры), Марта (Фермеры) — и одиночки вроде старого радиста Корвина. '
            . 'Они не кочуют: нашёл однажды — сможешь вернуться. Разговор с ними ветвится и меняет '
            . 'отношение: со своей фракцией встретят теплее, чужой — насторожённо. У каждого своё '
            . 'дело и своя правда, а порой и задание. Но выбирай слова: грубость и угрозы они '
            . 'припомнят, а кто слабее — может и сбежать, бросив тебя ни с чем.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🎭 Именные выжившие',
            'title_en'   => $titleEn,
            'tip_type'   => 'NPC',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'NamedSurvivors')->delete();
    }
}
