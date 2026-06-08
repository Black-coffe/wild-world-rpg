<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-102 Ф3 — discoverability-намёк /tips про стак обороны.
 *
 * Сигналит игроку: несколько одинаковых защитных сооружений на одной базе теперь
 * складываются (стена/ограда — с убыванием, до общего потолка; вышка — от наличия).
 * Конституция UX-DISCOVERABILITY. markdown-safe (парные *), utf8mb4. Idempotent.
 */
class Adr102SeedDefenseStackTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'DefenseStacking';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🛡️ *Стопка обороны:* несколько одинаковых защитных сооружений на одной базе теперь '
            . 'усиливают друг друга. Поставь ещё одну *Деревянную стену* или *Колючую ограду* — снижение '
            . 'получаемого урона и контрурон атакующему вырастут. Но каждая следующая слабее предыдущей, '
            . 'а общий потолок снижения урона остаётся прежним — бесконечно копить смысла нет. '
            . '*Дозорная вышка* — исключение: она работает от наличия, вторую строить незачем. '
            . 'Уровень и количество вместе делают базу по-настоящему крепкой к рейду.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🛡️ Стопка обороны',
            'title_en'   => $titleEn,
            'tip_type'   => 'бой',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'DefenseStacking')->delete();
    }
}
