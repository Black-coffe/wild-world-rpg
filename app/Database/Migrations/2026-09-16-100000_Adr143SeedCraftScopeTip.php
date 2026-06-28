<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-143 — совет «Можно ли уходить, пока крафтится» — снимает путаницу
 * «занят я или нет, пока что-то делается».
 *
 * Триггер (диалоги игроков 2026-06-28): Лисовский «когда крафтится на базе —
 * можно ли заниматься другим, ходить добывать?», Евгений «крафчу компоненты».
 * Ответ зависит от типа крафта (флаг tasks.parallel_execution_allowed): лёгкое
 * идёт в фоне (можно отойти), тяжёлое/готовка занимает целиком. Дополняет
 * легенду 🌍/🏠/⏳/🔒 на самих экранах крафта/стройки ([[ActionScopeService]]).
 *
 * Авто-раздача в /tips и «Совет дня» (TipService). Idempotent по title_en.
 * media-off + markdown-safe (парные `*`). tip_type='крафт'. game_tips = KEEP.
 */
class Adr143SeedCraftScopeTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'CraftScopeBackgroundVsBusy';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '⏳ *Можно ли уходить, пока что-то делается?* Смотри на пометку у крафта и стройки. '
            . '*🌍 Где угодно* — крафтить можно из любой точки (база и верстак нужны лишь в наличии, '
            . 'стоять на них не надо); *🏠 Только на базе* — стройку начинают, стоя на своей базе. '
            . 'А главное — занятость: *⏳ Идёт в фоне* (компоненты, инструменты, броня, вся стройка) — '
            . 'спокойно уходи добывать и ходить, оно делается само; *🔒 Займёт целиком* (тяжёлые T3-вещи, '
            . 'готовка на костре) — пока идёт, двигаться и добывать нельзя, нужно дождаться. '
            . 'О готовности я сообщу сам.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '⏳ Можно ли уходить, пока крафтится',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'CraftScopeBackgroundVsBusy')->delete();
    }
}
