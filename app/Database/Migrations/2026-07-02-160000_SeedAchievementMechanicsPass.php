<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-066 контент-пасс «оцифровка механик» (Asana) — 22 новых достижения по направлениям,
 * включая 5 НОВЫХ criteria_type (логика в AchievementService): survival_days («время бессмертия» —
 * дни с последнего возрождения), buildings_count, buildings_max_level, distinct_biomes,
 * distinct_items_crafted. Плюс расширение существующих типов (char_level 75/100, quests 100,
 * explored_cells 5000, craft_total 500, gold 100k/1M).
 *
 * 2 новые категории: survival (🛡 выживание), building (🏗 стройка) — добавлены в CATEGORY_LABELS
 * (Telegram) и catMeta (web). Боевые (npc_kills/pvp) НЕ добавлены: npc_kills=0 у всех чаров,
 * pvp_ladder пуст → никто бы не открыл (нечего показывать).
 *
 * Idempotent (по achievement_key UNIQUE). WipeManifest: achievements = KEEP. Новых таблиц нет.
 */
class SeedAchievementMechanicsPass extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [key, title, description, category, icon, criteria_type, target, points, sort]
        $rows = [
            // ── Развитие (расширение) ──
            ['reach_level_75',  'Колосс',            'Достигни 75 уровня — немногие заходят так далеко.',        'progression', '🗿', 'char_level',       75,      110, 300],
            ['reach_level_100', 'Сотый',             'Достигни 100 уровня. Пустошь запомнит это имя.',           'progression', '💯', 'char_level',       100,     150, 301],
            ['quest_100',       'Летописец',         'Заверши 100 заданий — твоя история длиннее иных легенд.',  'progression', '📚', 'quests_completed', 100,     120, 302],

            // ── Исследование (новый distinct_biomes + расширение) ──
            ['biomes_3',        'Кочевник',          'Открой 3 разных биома — пустошь многолика.',               'exploration', '🏕️', 'distinct_biomes',  3,       20,  310],
            ['biomes_6',        'Землепроходец',     'Открой 6 разных биомов — от лесов до вулканов.',           'exploration', '🗺', 'distinct_biomes',  6,       40,  311],
            ['biomes_9',        'Весь остров',       'Открой все 9 биомов острова. Не осталось белых пятен.',    'exploration', '🌍', 'distinct_biomes',  9,       70,  312],
            ['explorer_5000',   'Легенда странствий','Разведай 5000 клеток — твои следы повсюду.',               'exploration', '🧭', 'explored_cells',   5000,    120, 313],

            // ── Крафт (новый distinct_items_crafted + расширение) ──
            ['recipes_5',       'Подмастерье рецептов','Скрафти 5 разных предметов — руки начинают помнить.',    'crafting',    '📜', 'distinct_items_crafted', 5,  15,  320],
            ['recipes_25',      'Знаток рецептов',   'Скрафти 25 разных предметов — мало что тебе в новинку.',   'crafting',    '📘', 'distinct_items_crafted', 25, 40,  321],
            ['recipes_50',      'Энциклопедист',     'Скрафти 50 разных предметов. Ты — ходячий справочник.',    'crafting',    '📚', 'distinct_items_crafted', 50, 70,  322],
            ['crafter_500',     'Промышленник',      'Скрафти 500 предметов суммарно — конвейер пустоши.',       'crafting',    '⚙️', 'craft_total',      500,     120, 323],

            // ── Экономика (расширение) ──
            ['gold_100k',       'Богач',             'Накопи 100 000 золота. Голод тебе больше не грозит.',      'economy',     '💵', 'gold_total',       100000,  50,  330],
            ['gold_1m',         'Олигарх пустоши',   'Накопи 1 000 000 золота — целое состояние среди руин.',    'economy',     '🏦', 'gold_total',       1000000, 120, 331],

            // ── Выживание (НОВАЯ категория, criteria survival_days = «время бессмертия») ──
            ['survive_7',       'Выживший',          'Продержись 7 дней без смерти. Пустошь проверяет на прочность.', 'survival', '🩹', 'survival_days',   7,       15,  400],
            ['survive_30',      'Закалённый пустошью','Продержись 30 дней без смерти — месяц на грани.',         'survival',    '🛡', 'survival_days',    30,      35,  401],
            ['survive_90',      'Старожил',          'Продержись 90 дней без смерти. Ты часть этих земель.',     'survival',    '⏳', 'survival_days',    90,      60,  402],
            ['survive_365',     'Бессмертный',       'Продержись 365 дней без смерти. Тебя уже считают легендой.', 'survival',  '♾',  'survival_days',   365,     120, 403],

            // ── Стройка (НОВАЯ категория, criteria buildings_*) ──
            ['builder_1',       'Строитель',         'Возведи первую постройку. С этого начинается дом.',        'building',    '🏗', 'buildings_count',  1,       10,  410],
            ['builder_5',       'Прораб',            'Возведи 5 построек — у тебя уже хозяйство.',               'building',    '🏘', 'buildings_count',  5,       30,  411],
            ['builder_10',      'Градостроитель',    'Возведи 10 построек. Среди руин растёт новый очаг.',       'building',    '🏙', 'buildings_count',  10,      60,  412],
            ['fortify_5',       'Укрепление',        'Подними постройку до 5 уровня — стены крепнут.',           'building',    '🧱', 'buildings_max_level', 5,    35,  413],
            ['fortify_10',      'Цитадель',          'Подними постройку до 10 уровня. Неприступная твердыня.',   'building',    '🏰', 'buildings_max_level', 10,   70,  414],
        ];

        foreach ($rows as $r) {
            [$key, $title, $desc, $cat, $icon, $type, $target, $points, $sort] = $r;
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
                'criteria_type'   => $type,
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
        $keys = [
            'reach_level_75', 'reach_level_100', 'quest_100',
            'biomes_3', 'biomes_6', 'biomes_9', 'explorer_5000',
            'recipes_5', 'recipes_25', 'recipes_50', 'crafter_500',
            'gold_100k', 'gold_1m',
            'survive_7', 'survive_30', 'survive_90', 'survive_365',
            'builder_1', 'builder_5', 'builder_10', 'fortify_5', 'fortify_10',
        ];
        $this->db->table('achievements')->whereIn('achievement_key', $keys)->delete();
    }
}
