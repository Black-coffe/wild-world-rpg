<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 4 — discoverability-намёк /tips об охраняемых руинах.
 *
 * Сигналит игроку: на глубоком севере есть постоянные руины (Бункер/Технопарк/Город-призрак) с
 * ценным повторяемым лутом, но их стерегут — высокий уровень и риск. Конституция UX-DISCOVERABILITY.
 * markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 */
class SeedRuinsTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'GuardedRuins';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '☢️ *Охраняемые руины:* на *глубоком севере* (чем выше — тем опаснее) стоят остовы старого мира — '
            . '*Заброшенный Бункер*, *Разорённый Технопарк*, *Мёртвый Город-призрак*. Это постоянные места на карте: '
            . 'их можно обыскивать снова и снова, унося редкие металлы, нефть и руду. Но даром не дают — руины '
            . 'стережёт страж, который бьёт на поражение, а дорога на север сама по себе смертельна. Высокий уровень, '
            . 'крепкое снаряжение и трезвый расчёт — иначе там и останешься. Ищи значки ☢️ 🏭 🏙️ на карте.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '☢️ Охраняемые руины',
            'title_en'   => $titleEn,
            'tip_type'   => 'world',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'GuardedRuins')->delete();
    }
}
