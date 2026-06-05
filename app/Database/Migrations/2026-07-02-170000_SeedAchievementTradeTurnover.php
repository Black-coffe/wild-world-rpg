<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-066 контент-пасс «оцифровка механик» №2 — экономика/оборот (фидбэк владельца: «важно для оборота»).
 *
 * 2 НОВЫХ criteria_type (логика в AchievementService): gold_sold / gold_bought — суммарный оборот игрока
 * по золоту (transactions.price = итог строки, type sell/buy; см. PlayerEconomyService::tradeSummary).
 * Плюс верхние тиры gold_total (5M/10M).
 *
 * Новая категория trade (🤝 Торговля) — чтобы не раздувать economy. Добавлена в web catMeta +
 * Telegram CATEGORY_LABELS. Тиры — по списку владельца: Продано/Куплено 100/1к/10к/50к/100к/1млн/10млн/500млн.
 *
 * Idempotent (по achievement_key UNIQUE). WipeManifest: achievements = KEEP. Новых таблиц нет.
 */
class SeedAchievementTradeTurnover extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [key, title, description, category, icon, criteria_type, target, points, sort]
        $rows = [
            // ── Экономика: верхние тиры накопления (gold_total = текущий баланс) ──
            ['gold_5m',    'Толстосум',          'Накопи 5 000 000 золота — мешки уже не закрываются.',          'economy', '🤑', 'gold_total', 5000000,   130, 332],
            ['gold_10m',   'Король золота',       'Накопи 10 000 000 золота. Среди руин ты — монетный двор.',     'economy', '👑', 'gold_total', 10000000,  160, 333],

            // ── Торговля: Продано (gold_sold = суммарная выручка с продаж) ──
            ['sold_100',   'Первая выручка',      'Продай товара на 100 золота. Дело пошло.',                     'trade', '🪙', 'gold_sold', 100,        5,   500],
            ['sold_1k',    'Лоточник',            'Продай товара на 1 000 золота — лоток приносит доход.',        'trade', '🧺', 'gold_sold', 1000,       10,  501],
            ['sold_10k',   'Сбытчик',             'Продай товара на 10 000 золота. Сбыт налажен.',                'trade', '📦', 'gold_sold', 10000,      20,  502],
            ['sold_50k',   'Купец',               'Продай товара на 50 000 золота — тебя знают на торгу.',        'trade', '💱', 'gold_sold', 50000,      35,  503],
            ['sold_100k',  'Торговый дом',        'Продай товара на 100 000 золота. У тебя свой оборот.',         'trade', '🏪', 'gold_sold', 100000,     50,  504],
            ['sold_1m',    'Оптовик',             'Продай товара на 1 000 000 золота — поставки потоком.',        'trade', '🚚', 'gold_sold', 1000000,    80,  505],
            ['sold_10m',   'Магнат сбыта',        'Продай товара на 10 000 000 золота. Рынок держится на тебе.',  'trade', '🏭', 'gold_sold', 10000000,   120, 506],
            ['sold_500m',  'Барон пустоши',       'Продай товара на 500 000 000 золота. Легенда торговых путей.', 'trade', '🏆', 'gold_sold', 500000000,  180, 507],

            // ── Торговля: Куплено (gold_bought = суммарные траты на покупки) ──
            ['bought_100', 'Первая покупка',      'Купи товара на 100 золота. Каждому нужно начать.',             'trade', '🛍', 'gold_bought', 100,      5,   520],
            ['bought_1k',  'Покупатель',          'Купи товара на 1 000 золота — ты знаешь, что тебе нужно.',     'trade', '🛒', 'gold_bought', 1000,     10,  521],
            ['bought_10k', 'Завсегдатай рынка',   'Купи товара на 10 000 золота. Рынок — твой второй дом.',       'trade', '🏬', 'gold_bought', 10000,    20,  522],
            ['bought_50k', 'Скупщик',             'Купи товара на 50 000 золота — берёшь всё стоящее.',           'trade', '🎒', 'gold_bought', 50000,    35,  523],
            ['bought_100k','Снабженец',           'Купи товара на 100 000 золота. Запасы на любой случай.',       'trade', '💼', 'gold_bought', 100000,   50,  524],
            ['bought_1m',  'Крупный заказчик',    'Купи товара на 1 000 000 золота — твои заказы заметны.',       'trade', '📥', 'gold_bought', 1000000,  80,  525],
            ['bought_10m', 'Властелин спроса',    'Купи товара на 10 000 000 золота. Спрос двигаешь ты.',         'trade', '🚛', 'gold_bought', 10000000, 120, 526],
            ['bought_500m','Сердце рынка',        'Купи товара на 500 000 000 золота. Без тебя торг замер бы.',   'trade', '💎', 'gold_bought', 500000000,180, 527],
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
            'gold_5m', 'gold_10m',
            'sold_100', 'sold_1k', 'sold_10k', 'sold_50k', 'sold_100k', 'sold_1m', 'sold_10m', 'sold_500m',
            'bought_100', 'bought_1k', 'bought_10k', 'bought_50k', 'bought_100k', 'bought_1m', 'bought_10m', 'bought_500m',
        ];
        $this->db->table('achievements')->whereIn('achievement_key', $keys)->delete();
    }
}
