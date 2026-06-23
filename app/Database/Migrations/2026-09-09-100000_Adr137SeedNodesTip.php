<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB14 (ADR-137 «Узлы») — discoverability-совет /tips про узлы-боссы.
 *
 * Сигналит игроку (от лица Роби): на карте есть постоянные точки-боссы (☠/⏳), их
 * можно осмотреть до боя и валить сообща («Облава»), с сильных падает трофей «Метка
 * пустоши» (только против узлов). Про навигацию и понятия, НЕ про хрупкие числа баланса.
 * tip_type=`эндгейм` (валидный ENUM, см. TipsAExtendCategories). markdown-safe (парные *),
 * utf8mb4-эмодзи. Idempotent по title_en. БЕЗ слова «клан» (механика отрезана из v1).
 * Узлы пока dormant (`world.nodes.point_mode_enabled` OFF) — совет уедет на прод вместе
 * с активацией (WB16), как и /guide-раздел. game_tips=KEEP → WipeManifest не затрагивается.
 */
class Adr137SeedNodesTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'WorldBossNodes';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '☠ *Узлы — Хозяева пустоши.* По карте стоят постоянные точки силы со значком *☠*: '
            . 'их держат мировые боссы, куда опаснее обычных тварей. Встань на такую клетку, жми '
            . '*☠ Узел* → *☠ Осмотреть* — игра честно подскажет, по силам ли он в одиночку или нужна подмога. '
            . 'Валить узел можно сообща («Облава»): у него общий запас здоровья, а добыча делится по вкладу в урон. '
            . 'С сильных узлов падает 🔒 *Метка пустоши* — трофей, что усиливает только против узлов (не надеть, не продать). '
            . 'Юг по силам подрастающему бойцу, север смертелен. Повергнешь — точка возродится, а Хозяин станет злее.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '☠ Узлы — мировые боссы',
            'title_en'   => $titleEn,
            'tip_type'   => 'эндгейм',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'WorldBossNodes')->delete();
    }
}
