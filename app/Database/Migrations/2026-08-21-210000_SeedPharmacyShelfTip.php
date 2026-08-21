<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Аптечка и Провизия — теперь две разные полки» (TIPS-COVERAGE, CLAUDE.md;
 * pharmacy-split-04, brief.md).
 *
 * Аптечка перестала хранить еду и заготовки: лекарства остались на ней одной, а всё съедобное
 * переехало на соседнюю кнопку «🍲 Провизия». Заодно у лекарств, которые снимают рану, в списке —
 * в их строке — написано, какую именно — подбирать их наугад больше не нужно (остальные
 * лечат здоровье и выносливость, ран не трогают).
 *
 * Idempotent по title_en='PharmacyShelfSplit'. media-off самодостаточен, markdown-safe
 * (парные *), категория «персонаж» из 14 ENUM. game_tips = KEEP (WipeManifest).
 *
 * Правка 2026-08-21 (ревью pharmacy-split-09): исходный текст утверждал, что строка
 * «🩺 Снимает:» идёт «прямо под названием» — по факту `ConsumableShelfService::itemLine()`
 * ставит её последней в блоке предмета, после доз/годности/«Баф». На testbot строка уже
 * засеяна (2026-08-21 22:38), поэтому `up()` не просто вставляет при отсутствии, а ещё и
 * обновляет `content` существующей строки — иначе правка не доедет ни до testbot, ни до прода.
 */
class SeedPharmacyShelfTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'PharmacyShelfSplit';

        $content = '💊 *Аптечка теперь только про лечение.* Еда, консервы и прочая провизия с неё '
            . 'переехали на соседнюю кнопку *«🍲 Провизия»* — открой её прямо с экрана аптечки, '
            . 'а назад вернёшься той же кнопкой *«💊 Аптечка»*. И подбирать лекарство от раны '
            . 'наугад не придётся: у тех, что снимают рану, в их строке в списке написано, какую '
            . 'именно, например «🩺 Снимает: 🤢 Отравление» — остальные просто поднимают здоровье '
            . 'и выносливость, ран не трогают.';

        $existing = $this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray();

        if (! empty($existing)) {
            $this->db->table('game_tips')
                ->where('title_en', $titleEn)
                ->update(['content' => $content, 'updated_at' => $now]);

            return;
        }

        $this->db->table('game_tips')->insert([
            'title_ru'   => '💊 Аптечка и Провизия — две разные полки',
            'title_en'   => $titleEn,
            'tip_type'   => 'персонаж',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'PharmacyShelfSplit')->delete();
    }
}
