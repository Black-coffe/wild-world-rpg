<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Трофейная подать» (TIPS-COVERAGE, CLAUDE.md; ADR-135 Ф5).
 *
 * Едет ВМЕСТЕ с активацией механики (killswitch tribute.enabled=ON): пока фича dormant,
 * совет о ней вводил бы в заблуждение, поэтому seed-миграция — часть Ф5 (прецедент ADR-132
 * «guide/tip в фазе активации»). Подсвечивает первый межигровой переток ресурсов: серия
 * побед в полевом PvP над одним игроком → подать; пути выхода — реванш/выкуп/срок.
 *
 * Категория 'бой'. Навигация/понятие, без хрупких чисел баланса (анти-дрейф). Автоматически
 * в /tips и «Совет дня» (TipService). Idempotent по title_en. media-off + markdown-safe
 * (парные «*»), тон Роби. game_tips = KEEP (WipeManifest н/т — новых таблиц нет).
 */
class Adr135SeedTributeTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'TributeDominance';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '⚖️ *Трофейная подать.* Громишь одного и того же игрока раз за разом в полевом '
            . 'PvP? Он может попасть под твою подать — часть его добычи начнёт уходить тебе. А если '
            . 'сам проиграл серию сильному бойцу — ищи путь к свободе: реванш в бою с обидчиком, '
            . 'выкуп золотом или просто дождись, пока срок выйдет сам. Смотри в '
            . '*«Перс» → «⚖️ Трофейная подать»* (кнопка появляется при активной подати). Под подать '
            . 'попадают только опытные бойцы — ранней игре она не грозит.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '⚖️ Трофейная подать',
            'title_en'   => $titleEn,
            'tip_type'   => 'бой',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'TributeDominance')->delete();
    }
}
