<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет «где искать биом» (компас в легенде).
 *
 * След вопроса игрока в чате 22.07.2026: «а где найти вулканы?» — ответить можно было только
 * вручную из чата. Теперь в экране «❓ Легенда» у каждого биома есть сторона света и порядок
 * расстояния; совет доносит, что эта подсказка вообще существует.
 *
 * Про навигацию/понятия, БЕЗ хрупких чисел баланса. markdown-safe (парные *), utf8mb4-эмодзи.
 * Idempotent (по title_en). tip_type='биомы' (валидное ENUM-значение).
 */
class SeedBiomeCompassTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'BiomeCompass';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🧭 *Не знаешь, где искать биом — спроси легенду.* Открой «🌍 Мир» → '
            . '*«❓ Легенда»*: рядом с каждым биомом написано, в какой он стороне от тебя и '
            . 'далеко ли идти. Редкие биомы отмечены отдельно — например, вулканические '
            . 'территории попадаются нечасто и почти все лежат в северо-восточной части '
            . 'острова; за нефтью, серой и обсидианом идти туда (или в пустыни). Это компас, '
            . 'а не маршрут: сторону света он назовёт, а карту открывать всё равно ногами.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🧭 Где искать нужный биом',
            'title_en'   => $titleEn,
            'tip_type'   => 'биомы',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'BiomeCompass')->delete();
    }
}
