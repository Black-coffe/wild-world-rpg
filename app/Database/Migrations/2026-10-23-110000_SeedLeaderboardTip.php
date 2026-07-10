<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет про экран «🏆 Топ игроков».
 *
 * Вердикт «нужен совет: ДА». Обоснование: это НОВАЯ player-поверхность, а не рефактор.
 * Причём построена она ровно потому, что игроки о ней спрашивали и не находили
 * (двое написали боту «топ» / «тут есть топ игроков?» и получили «Не понял команду»).
 * Совет закрывает круг: рассказывает, что топ теперь есть и как до него дойти.
 *
 * Совет про навигацию/понятия, БЕЗ чисел баланса (анти-дрейф). markdown-safe (парные *),
 * utf8mb4-эмодзи. Idempotent (по title_en). tip_type='общие' (валидное ENUM из 14).
 * Media-off самодостаточен: весь смысл в тексте.
 */
class SeedLeaderboardTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'PlayerLeaderboard';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🏆 *В игре есть общий топ игроков.* '
            . 'Открыть: *🧑 Я* → *📊 Прогресс* → *🏆 Топ игроков*. Или просто напиши боту *топ*. '
            . 'Вкладка *🔥 Живые* показывает тех, кто выходил на связь недавно — это твои настоящие '
            . 'соперники. Вкладка *👑 Легенды острова* — сильнейшие за всё время, некоторых из них '
            . 'здесь уже не встретить. Внизу списка всегда видно твою строку: какое место ты занимаешь. '
            . 'Место в топе — престиж, наград за него нет. Не путай с *🏆 Рейтинг PvP*: там очки за '
            . 'победы в дуэлях, а не уровень.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🏆 Топ игроков: кто впереди',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'PlayerLeaderboard')->delete();
    }
}
