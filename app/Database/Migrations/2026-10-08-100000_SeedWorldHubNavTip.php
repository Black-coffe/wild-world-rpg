<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет-указатель на новую навигацию «🌍 Мир» (ADR-150 Слайс 1).
 *
 * Триггер: жалоба игрока «после обучения непонятно даже как просто ИДТИ». Верхняя группа «🌍 Мир»
 * (killswitch navigation.world_hub.enabled) даёт прямой вход в компас ходьбы: нижняя кнопка «🌍 Мир»
 * / команда /go → карта + стрелки направлений, «🗺 Обзор» (фото карты), «❓ Легенда» (тумблер значков).
 * Совет закрывает discoverability: подсказывает игроку сам путь «как пойти».
 *
 * Про навигацию/понятия (что нажать, где найти), БЕЗ хрупких чисел баланса. markdown-safe (парные *),
 * utf8mb4-эмодзи. Idempotent (по title_en). tip_type='общие' (валидное ENUM-значение).
 */
class SeedWorldHubNavTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'WorldHubNav';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🧭 *Как просто пойти по миру:* жми *«🌍 Мир»* в нижнем меню (или команду /go) — '
            . 'откроется компас: карта вокруг тебя и стрелки восьми направлений. Тапнул стрелку — '
            . 'сделал шаг, карта сразу обновилась. Нужен общий вид острова — кнопка *«🗺 Обзор»*, '
            . 'а *«❓ Легенда»* подскажет, что означают значки и биомы на карте. Для дальней дороги — '
            . '*«🗺️ Поход»*.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🧭 Как просто пойти по миру',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'WorldHubNav')->delete();
    }
}
