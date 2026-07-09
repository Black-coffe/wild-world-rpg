<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет-указатель на хаб «📋 Дела» (ADR-150 Слайс 3).
 *
 * Триггер: firehose-срез 2026-07-09 — экраны целей находят лишь 30% активных игроков, а все
 * обращения к активным задачам шли командой /tasks (кнопки не существовало). Группа «📋 Дела»
 * (killswitch navigation.tasks_hub.enabled) сводит в один экран: что идёт сейчас (таймеры),
 * текущую цель, квесты и задания дня. Совет закрывает discoverability самого экрана.
 *
 * Отдельно проговаривает правду про «⛔️ Прервать»: legacy-/tasks звал это «моментально снять
 * задачу», хотя прогресс и награда теряются — совет не должен наследовать вводящее в заблуждение
 * обещание (ревью-этос Редколлегии).
 *
 * Про навигацию/понятия (что нажать, где найти), БЕЗ хрупких чисел баланса. markdown-safe
 * (парные *), utf8mb4-эмодзи. Idempotent (по title_en). tip_type='общие' (валидное ENUM).
 */
class SeedTasksHubTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'TasksHubNav';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '📋 *Забыл, чем занят и что дальше:* жми *«📋 Дела»* в нижнем меню (или команду '
            . '/tasks) — там всё сразу: какие задачи идут прямо сейчас и сколько осталось ждать, '
            . 'твоя текущая цель, квесты и задания дня. Задачи завершаются сами — награда придёт '
            . 'отдельным сообщением, сидеть и смотреть не нужно. Кнопка *«⛔️ Прервать»* рядом с '
            . 'задачей именно прерывает её: прогресс и награда пропадут, так что жми осознанно.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '📋 Где посмотреть, чем ты занят',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'TasksHubNav')->delete();
    }
}
