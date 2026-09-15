<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Несколько баз — выбор по сигналу» (TIPS-COVERAGE, CLAUDE.md; multibase-picker-07).
 *
 * Спека multibase-picker: «🏠 База» вне своей базы, когда баз больше одной, предлагает
 * кнопками те, до которых дотягивается их собственная Вышка связи, остальные — текстом
 * с координатами и расстоянием. Экран базы, Ангар, развитие и декор работают с выбранной
 * базой; робот-промышленник копает у базы, где его запустили и где есть своя Мастерская
 * робототехники.
 *
 * Idempotent по title_en='MultibasePicker'. media-off самодостаточен, markdown-safe (парные *),
 * категория «общие» из 14 ENUM. game_tips = KEEP (WipeManifest, таблица уже классифицирована).
 */
class SeedMultibasePickerTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'MultibasePicker';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🗼 *У тебя несколько баз — «🏠 База» сама разберётся.* Стоишь не на своей базе? '
            . 'Кнопка предложит те базы, до которых дотягивается их собственная Вышка связи, — '
            . 'отдельными кнопками, а остальные перечислит в сообщении с координатами и расстоянием '
            . '(рядом — «Телепорт» или «Двигаться»). Экран базы, *«🤖 Ангар»*, развитие и декор работают '
            . 'именно с той базой, что ты выбрал — а робота-промышленника получится запустить только '
            . 'там, где на этой самой базе стоит своя Мастерская робототехники: копать он будет вокруг неё.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🗼 Несколько баз — выбор по сигналу',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'MultibasePicker')->delete();
    }
}
