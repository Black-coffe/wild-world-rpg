<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR building-bonus-absorption — discoverability-намёк /tips про дубли построек.
 *
 * Сигналит игроку правило, которое иначе узнаётся опытным путём: бонус к характеристикам за
 * постройку определённого типа даётся один раз на персонажа, вторая и любая следующая копия
 * (на этой же базе или на другой) его не добавляет. Конституция UX-DISCOVERABILITY / TIPS-COVERAGE.
 * markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 */
class SeedDuplicateBuildingsTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'DuplicateBuildingsAbsorbed';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🔁 *Дубли построек:* прибавку к характеристикам за постройку определённого типа ты получаешь '
            . 'один раз на персонажа. Вторая, пятая, тридцатая копия — хоть на этой же базе, хоть на другой — '
            . 'больше характеристик не даёт. Налог с дублей ведёт себя иначе: одинаковые постройки на *одной* '
            . 'базе складываются в одну налоговую строку, а такое же здание на *другой* базе — это отдельная '
            . 'база со своим налогом. Что дубли всё же дают — оборону именно той базы, где стоят: у *стен* и '
            . '*оград* каждый дополнительный сегмент усиливает защиту (с убывающей отдачей — не кратно), '
            . 'а дозорная вышка в этот счёт не входит.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🔁 Дубли построек и бонусы',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'DuplicateBuildingsAbsorbed')->delete();
    }
}
