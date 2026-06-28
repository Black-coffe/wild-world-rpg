<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S2 (ROADMAP-RETENTION-10, ADR-144) — совет «Незнакомец рядом» — учит новичка, что
 * с выжившими можно говорить, а не только драться.
 *
 * Контекст: S2 населяет ghost-town новичковой зоны (NewbieGreeterService + ambient
 * population). Первая встреча с NPC раньше почти не случалась → игрок не знал про
 * меню действий (поговорить/расспросить/обменяться) и про память отношений. Совет —
 * проактивный напоминатель (TipService «Совет дня» + /tips).
 *
 * Idempotent по title_en. media-off + markdown-safe (парные `*`). tip_type='NPC'
 * (валидный ENUM). game_tips = KEEP. Про навигацию/понятие, не про числа баланса.
 */
class S2SeedNewbieEncounterTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'NewbieFirstStranger';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🧑 *Ты не один на острове.* Уже на первых клетках рядом может оказаться выживший — '
            . 'на карте он отмечен значком 🧑. Подойди вплотную и нажми *«👤 Незнакомец»*: с нейтралами '
            . 'можно *поговорить*, *расспросить* про земли и *обменяться* — это не только драка. '
            . 'И помни: NPC *запоминает* тебя — как обойдёшься, так он и будет относиться к тебе дальше. '
            . 'Не спеши нападать: мирный сосед полезнее мёртвого.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🧑 Незнакомец рядом — поговори, не нападай',
            'title_en'   => $titleEn,
            'tip_type'   => 'NPC',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'NewbieFirstStranger')->delete();
    }
}
