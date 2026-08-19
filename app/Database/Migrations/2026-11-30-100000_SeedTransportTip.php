<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Транспорт меняет темп Похода» (TIPS-COVERAGE, CLAUDE.md; docs/specs/transport-system).
 *
 * После story transport-06 темп Похода перестаёт быть общим для всех: каждая машина задаёт
 * свой размер пачки (`cellsPerTick`), а фракционные машины ведут себя по-разному на целине
 * и на знакомой земле. Совет объясняет игроку понятие — где искать транспорт (крафт →
 * категория «🚚 Транспорт», доступна с 6 уровня) и что выбор фракции на 10 уровне заодно
 * выбирает свою машину, — без чисел баланса, которые тюнятся в `GameSettings world.vehicle.*`.
 *
 * Idempotent по title_en='VehicleIntro'. media-off + markdown-safe (парные *), utf8mb4-эмодзи
 * (колонка уже сконвертирована). game_tips = KEEP (WipeManifest).
 */
class SeedTransportTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'VehicleIntro';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🚚 *Транспорт меняет темп Похода.* Пешком все идут одинаково, но повозка, велосипед '
            . 'или дрон задают свой шаг — и разные машины ведут себя по-разному на разведанной земле '
            . 'и на целине. Крафт транспорта открыт с *6 уровня* — смотри категорию «🚚 Транспорт» в крафте. '
            . 'На *10 уровне* выбор фракции заодно выбирает и свою машину — это решение навсегда, так что '
            . 'загляни в витрину фракционных машин заранее. Экран «🚚 Мой транспорт» показывает, что у тебя '
            . 'активно и сколько времени машина уже сэкономила.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🚚 Транспорт и темп Похода',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'VehicleIntro')->delete();
    }
}
