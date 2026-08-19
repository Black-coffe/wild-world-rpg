<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-172 — совет о том, что дорогую технику теперь есть смысл страховать.
 *
 * Изменение player-facing: дроны вошли в список страхуемых типов, а штраф смерти
 * на предметах с quantity=1 перестал округляться в ноль — то есть риск, от которого
 * полис защищает, стал настоящим. Совет говорит про навигацию и понятие («что это,
 * где найти»), без чисел баланса — доля потерь живёт в GameSettings и может меняться.
 *
 * Путь сверен с кодом: `HangarAction` → кнопка «🛡 Страховка техники» (безусловная),
 * `PersonalInsurance` → «📦 Крафт-страховка», обе на роут `craftInsuranceList`.
 * markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 */
class Adr172SeedDroneInsuranceTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'DroneCraftInsurance';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🛡 *Дрон теперь можно застраховать.* Роботы, верстаки, транспорт и дроны лежат у тебя '
            . 'поштучно — доля от одной штуки не берётся, поэтому смерть считает их отдельно: это шанс '
            . 'лишиться машины целиком. Вечный полис у страхового агента снимает этот шанс: платишь один '
            . 'раз, вещь не списывается при смерти и не достаётся тому, кто тебя убил. Найти: *«🏭 Ангар»* '
            . 'на базе → *«🛡 Страховка техники»*, либо *«🧍 Страховка»* в карточке персонажа → '
            . '*«📦 Крафт-страховка»*. Полис держится, пока предмет у тебя.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🛡 Страховка для дрона',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'DroneCraftInsurance')->delete();
    }
}
