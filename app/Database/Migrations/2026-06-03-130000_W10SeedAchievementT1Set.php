<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W10 (ADR-066, закрытие Фазы 2) — контент-пасс T1: +14 достижений к 7 starter из W9
 * (итого 21). Тиры по существующим criteria_type (движок AchievementService не меняется).
 *
 * Idempotent: INSERT только если нет строки с тем же achievement_key. utf8mb4 уже у таблицы.
 */
class W10SeedAchievementT1Set extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('achievements')) {
            return; // W9-миграция создаёт таблицу; без неё нечего сидить
        }

        $now = date('Y-m-d H:i:s');

        // [key, title, description, category, icon, criteria_type, target, points, sort_order]
        $seed = [
            // — Прогресс уровня —
            ['reach_level_10', 'Закалённый', 'Достигни 10 уровня — открыт выбор фракции.', 'progression', '🌿', 'char_level', 10, 20, 110],
            ['reach_level_25', 'Ветеран пустошей', 'Достигни 25 уровня.', 'progression', '🎖', 'char_level', 25, 40, 120],
            ['reach_level_50', 'Легенда острова', 'Достигни 50 уровня.', 'progression', '🏆', 'char_level', 50, 80, 130],
            // — Квесты —
            ['quest_10', 'Странник', 'Заверши 10 квестов.', 'progression', '📖', 'quests_completed', 10, 25, 160],
            ['quest_30', 'Хроникёр', 'Заверши 30 квестов.', 'progression', '📚', 'quests_completed', 30, 50, 170],
            ['quest_50', 'Завершитель', 'Заверши 50 квестов.', 'progression', '🏁', 'quests_completed', 50, 80, 180],
            // — Разведка —
            ['explorer_100', 'Картограф', 'Разведай 100 клеток.', 'exploration', '🗺', 'explored_cells', 100, 25, 210],
            ['explorer_500', 'Первопроходец', 'Разведай 500 клеток.', 'exploration', '🧗', 'explored_cells', 500, 50, 220],
            ['explorer_1000', 'Хозяин карты', 'Разведай 1000 клеток.', 'exploration', '🌍', 'explored_cells', 1000, 80, 230],
            // — Крафт —
            ['crafter_10', 'Ремесленник', 'Сделай 10 крафтов.', 'crafting', '⚒', 'craft_total', 10, 15, 310],
            ['crafter_50', 'Мастер крафта', 'Сделай 50 крафтов.', 'crafting', '🛠', 'craft_total', 50, 40, 320],
            ['crafter_200', 'Кузнец легенд', 'Сделай 200 крафтов.', 'crafting', '🔧', 'craft_total', 200, 80, 330],
            // — Экономика —
            ['gold_50k', 'Торговец', 'Накопи 50 000 золота.', 'economy', '💵', 'gold_total', 50000, 40, 410],
            ['gold_500k', 'Магнат', 'Накопи 500 000 золота.', 'economy', '🏦', 'gold_total', 500000, 80, 420],
        ];

        foreach ($seed as [$key, $title, $desc, $cat, $icon, $ctype, $target, $points, $sort]) {
            $exists = $this->db->table('achievements')->where('achievement_key', $key)->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('achievements')->insert([
                'achievement_key' => $key,
                'title'           => $title,
                'description'     => $desc,
                'category'        => $cat,
                'icon'            => $icon,
                'criteria_type'   => $ctype,
                'criteria_target' => $target,
                'points'          => $points,
                'sort_order'      => $sort,
                'enabled'         => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('achievements')->whereIn('achievement_key', [
            'reach_level_10', 'reach_level_25', 'reach_level_50',
            'quest_10', 'quest_30', 'quest_50',
            'explorer_100', 'explorer_500', 'explorer_1000',
            'crafter_10', 'crafter_50', 'crafter_200',
            'gold_50k', 'gold_500k',
        ])->delete();
    }
}
