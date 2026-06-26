<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S5 (ROADMAP-RETENTION-10, ADR-142) — discoverability-совет /tips про «Навес».
 *
 * После активации `onboarding.first_build.enabled` новичку доступно дешёвое one-shot
 * первое укрытие «Навес» (закрывает горлышко OnbStepBuild). Сигналим от лица Роби: первая
 * постройка проще, чем кажется; начни с Навеса; путь — «База» → «🏗 Строить». Про
 * понятия/навигацию, НЕ про хрупкие числа баланса (анти-дрейф). tip_type=`общие` (валидный
 * ENUM GameTipsModel). markdown-safe (парные *), media-off, utf8mb4-эмодзи. Idempotent по
 * title_en. game_tips=KEEP → WipeManifest не затрагивается.
 *
 * Сидится ПРИ АКТИВАЦИИ S5 (killswitch ON) — не lying-tip про невидимую фичу.
 */
class S5SeedFirstShelterTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'FirstShelter';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '⛺ *Первое укрытие — проще, чем кажется.* Совсем новичкам доступен *Навес* — самая дешёвая '
            . 'первая постройка: немного дерева и воды, готов за пару минут, без налога. Это твоя первая постройка '
            . 'на базе и почин настоящего лагеря. Открой *«База»* в нижнем меню → *«🏗 Строить»* и начни с него — '
            . 'а дальше придут Склад, Теплица и оборона.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '⛺ Первое укрытие',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'FirstShelter')->delete();
    }
}
