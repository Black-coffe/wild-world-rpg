<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-127 — discoverability-намёк /tips про команду-реплей онбординга «📖 Путь новичка» (/guide).
 *
 * Сигналит игроку (особенно пропустившему обучение): есть текстовый справочник по всем
 * механикам, доступный в любой момент — команда /guide или кнопка на карточке «Перс».
 * Конституция UX-DISCOVERABILITY (tip/анонс-consistency: путь сверен с кодом — exact-роут
 * `guide` + BotMenuService::commandList + кнопка в CharacterService::showCharacterInfo).
 * markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 */
class Adr127SeedGuideTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'GuideReplayOnboarding';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '📖 *Путь новичка* — текстовый справочник по всей игре: движение, добыча, крафт, база, '
            . 'еда, бой, фракции, NPC, роботы, арена и эндгейм. Пропустил обучение в начале или забыл, как '
            . 'что работает — открой его в любой момент: команда */guide* или кнопка *«Путь новичка»* внизу '
            . 'карточки *«Перс»*. Это просто пояснения — наград и выдачи там нет, так что листай сколько угодно.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '📖 Путь новичка',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'GuideReplayOnboarding')->delete();
    }
}
