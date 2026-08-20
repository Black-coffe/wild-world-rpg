<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ремонт по открытому хвосту ADR-174 (daily 2026-08-20): у 🐎 Тягловой повозки
 * «честный минус» в admin-текстах гласил «скорость не растёт вовсе (cells_per_tick =
 * база везде)», хотя посев — и прод — дают ей 4 клетки/тик при пешей базе 3.
 * Расхождение замысла и посева закрывается СО СТОРОНЫ ТЕКСТА, а не числа:
 *
 *  - 4 клетки/тик — это то, что уже выкачено игрокам, описано в дев-блоге №12
 *    (`wild-world-transport-dva-goda`) и подтверждено прод-снимком `game_settings`;
 *    опустить его до 3 значило бы нерфить живую механику ради формулировки.
 *  - Настоящий минус тягловой повозки — не «нет скорости», а «скорость ровно та же,
 *    что у стартовой 🛒 Лёгкой повозки 6-го уровня»: за 14-й уровень и фракцию
 *    Фермеров платят усталостью (пол 0.75) и грузом (0.33), а не темпом.
 *
 * Затронуты только три строки, куда шаблон посева подставлял враньё: `*_unexplored`,
 * `tired_factor`, `cargo_share`. Числа НЕ трогаются — миграция чисто текстовая.
 *
 * Idempotent: `up()` пишет по точному совпадению setting_key и не зависит от того,
 * сколько раз прогонялся; повторный прогон перезапишет тем же текстом.
 */
class FixDraftCartHonestMinusText extends Migration
{
    private const PREFIX = 'world.vehicle.draft_cart.';

    /** Честный минус после ремонта (одна формулировка на все три строки). */
    private const MINUS_NEW = 'скорость ровно как у стартовой 🛒 Лёгкой повозки — 4 клетки/тик на любой местности, без фронтирной и холодной надбавки; преимущество тягловой повозки лежит в усталости и грузе, не в темпе';

    /** Что стояло до ремонта — для down(). */
    private const MINUS_OLD = 'скорость не растёт вовсе (cells_per_tick = база везде)';

    public function up(): void
    {
        $this->writeTexts(self::MINUS_NEW);
    }

    public function down(): void
    {
        $this->writeTexts(self::MINUS_OLD);
    }

    private function writeTexts(string $minus): void
    {
        $name = '🐎 Тягловая повозка';
        $now  = date('Y-m-d H:i:s');

        $texts = [
            'cells_per_tick_unexplored' => "{$name} — клеток/тик по целине (фронтиру). Бонус транспорта смотрит именно сюда (concept-final.md §0.2). Честный минус: {$minus}.",
            'tired_factor'              => "{$name} — множитель усталости за клетку (база 0.5; 0.75 — пол множителя, ниже единственный регулятор темпа исследования обесценивается). Честный минус: {$minus}.",
            'cargo_share'               => "{$name} — доля дополнительного груза (0=нет карго-роли, зажато сверху world.vehicle.cargo.max_share); 0.33 — потолок карго-роли среди пяти машин. Честный минус: {$minus}.",
        ];

        foreach ($texts as $suffix => $rationale) {
            $key = self::PREFIX . $suffix;

            $row = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
            if (empty($row)) {
                // Посев SeedVehicleGameSettings ещё не отработал — идемпотентно молчим.
                continue;
            }

            $this->db->table('game_settings')
                ->where('setting_key', $key)
                ->update([
                    'rationale_text' => $rationale,
                    'updated_at'     => $now,
                ]);
        }
    }
}
