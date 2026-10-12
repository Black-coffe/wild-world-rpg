<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «У чужой базы уровень не гарантия» (TIPS-COVERAGE, CLAUDE.md; oct-pvp-clarity, B2 из чата 2026-10-12).
 *
 * Игрок L504 трижды проиграл L120 у его базы и спросил, как это считается. Совет — взгляд нападающего: что
 * оборона хозяина и потолки Силы/уровней решают исход, что южная зона закрыта для нападений, и где смотреть
 * блок «🧩 Почему так вышло». Соседи: «DefenseStacking» (как строить оборону, взгляд хозяина), «BaseStandoffAlert»
 * (тревога и три хода), «BattleJournal» (где журнал). Этот — почему нападающий проиграл. Не дубль.
 *
 * Idempotent по title_en='PvpBaseOutcome'. Без чисел баланса (проценты стены, потолки — в коде и админке), media-off
 * самодостаточен, markdown-safe (парные *), категория «бой» из 14 ENUM. game_tips = KEEP (WipeManifest).
 */
class SeedPvpBaseOutcomeTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'PvpBaseOutcome';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🏰 *У чужой базы уровень не гарантия.* Хозяин, который стоит на своей базе с целой обороной, '
            . 'сильнее, чем кажется: стена срезает каждый твой удар, а колючая ограда снимает здоровье, пока ты бьёшь. '
            . 'Сила и уровни сверх своих потолков урона уже не прибавляют, а младший по уровню иногда бьёт удачным '
            . 'ударом. Проиграл — открой *📜 Разбор боя*: в блоке *🧩 Почему так вышло* я распишу, что сработало. '
            . 'А в южной зоне нападать нельзя вовсе — ни на выживших, ни на их базы.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🏰 Почему проиграл у чужой базы',
            'title_en'   => $titleEn,
            'tip_type'   => 'бой',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'PvpBaseOutcome')->delete();
    }
}
