<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-098 «Радио-сигнал к редким объектам» — совет про сигналы в Походе (коммуникация).
 *
 * Объясняет игроку, что в дальнем Походе можно поймать радио-сигнал к редкому объекту
 * (бункер/технопарк/город-призрак) и идти на него по направлению, пока кто-то не добрался
 * первым. Автоматически в /tips и «Совет дня» (TipService). Idempotent по title_en.
 * media-off + markdown-safe. game_tips = KEEP.
 */
class Adr098SeedObjectSignalTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'ObjectSignal';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '📡 *Сигналы в Походе:* отправляясь в дальний поход (🚜 Переехать → Дальний поход), '
            . 'ты можешь поймать слабый радио-сигнал к редкому объекту поблизости — бункеру, технопарку, '
            . 'городу-призраку. В сигнале — направление и примерная дистанция; иди на него, и сигнал '
            . 'будет крепнуть. Но спеши: источник умолкает, как только кто-то доберётся туда первым. '
            . 'Внутри таких объектов — щедрый лут и прокачка характеристик.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '📡 Сигналы к редким объектам',
            'title_en'   => $titleEn,
            'tip_type'   => 'биомы',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'ObjectSignal')->delete();
    }
}
