<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S28 (ADR-032) — seasonal craft rotation: 5 live-tunable GameSettings.
 *
 * Сезон = детерминированная функция от anchor+cycle (SeasonalCraftService).
 * cycle_anchor_date подобран так, что на запуске активна Зима (живой pilot).
 * category=world. Killswitch seasonal.enabled. Idempotent.
 *
 * NB: «season» здесь = craft-сезон (Зима/Весна/...), НЕ endgame leaderboard-season
 * (SeasonResetService). Разные домены — namespace `seasonal.*` свободен.
 */
class S28SeedSeasonalGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'seasonal.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Глобальный killswitch сезонных крафтов. true — система активна (доступен текущий сезон + меню «Сезонный крафт» + ежедневный анонс перехода). Запас прочности на случай негативного фидбэка/ребаланса.',
                'effect_text'        => 'SeasonalCraftService::isSeasonActive() при false всегда возвращает false → сезонные рецепты не крафтятся, меню показывает «недоступно», cron не шлёт анонсы.',
                'above_effect_text'  => 'true — сезонная ротация работает как задумано.',
                'below_effect_text'  => 'false — фича полностью выключена (мягкий откат без удаления контента; уже изготовленные предметы остаются).',
            ],
            [
                'setting_key'        => 'seasonal.season_count',
                'value_type'         => 'int',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => 'Сколько сезонов в полном цикле. 4 (Зима/Весна/Лето/Осень) — естественное восприятие года.',
                'effect_text'        => 'Длина цикла = season_count × season_duration_days дней. Влияет на math активного сезона (intdiv(pos, dur) mod count).',
                'above_effect_text'  => 'Больше 4 — нужны доп. наборы рецептов на каждый лишний сезон, иначе «пустые» сезоны без контента.',
                'below_effect_text'  => 'Меньше 4 — реже смена темы, меньше ощущение события.',
                'recommended_min'    => '2',
                'recommended_max'    => '6',
                'hard_min'           => '1',
                'hard_max'           => '12',
            ],
            [
                'setting_key'        => 'seasonal.season_duration_days',
                'value_type'         => 'int',
                'value_int'          => 21,
                'default_value_text' => '21',
                'rationale_text'     => 'Длительность одного сезона в днях. 21 (3 недели) — успевают и казуалы, но сохраняется ощущение «события»; не FOMO-ёмко как 7-14 дней.',
                'effect_text'        => 'Окно доступности сезонных рецептов. Длина цикла = season_count × это.',
                'above_effect_text'  => 'При 30+ сезоны тянутся, теряется новизна/событийность.',
                'below_effect_text'  => 'При <14 казуалы не успевают → FOMO, негативный фидбэк (П2 риск).',
                'recommended_min'    => '14',
                'recommended_max'    => '30',
                'hard_min'           => '1',
                'hard_max'           => '120',
            ],
            [
                'setting_key'        => 'seasonal.cycle_anchor_date',
                'value_type'         => 'string',
                'value_string'       => '2026-05-20',
                'default_value_text' => '2026-05-20',
                'rationale_text'     => 'Якорная дата (день 0 цикла = начало Зимы). Подобрана так, что на запуске активна Зима выживания (живой pilot, не locked). Порядок сезонов: Зима→Весна→Лето→Осень.',
                'effect_text'        => 'SeasonalCraftService отсчитывает дни от этой даты: idx = intdiv((days mod cycle), duration). Сдвиг даты сдвигает фазу цикла.',
                'above_effect_text'  => 'Сдвиг вперёд — текущий сезон сменится раньше/позже (фаза сдвигается); может «обрезать» активный сезон.',
                'below_effect_text'  => 'Сдвиг назад — аналогично смещает фазу; не критично, math циклична.',
            ],
            [
                'setting_key'        => 'seasonal.recipe_unlock_count_per_season',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Сколько эксклюзивных рецептов в сезоне (контракт для игрока + UI-заголовок). Pilot Зима = 5.',
                'effect_text'        => 'Информационный (меню/анонс показывают «до N рецептов»). Фактический список — required_season в CraftRecipes.',
                'above_effect_text'  => 'Больше — нужно больше контента на сезон (иначе число врёт).',
                'below_effect_text'  => 'Меньше — сезон ощущается беднее.',
                'recommended_min'    => '3',
                'recommended_max'    => '10',
                'hard_min'           => '1',
                'hard_max'           => '50',
            ],
        ];

        $shared = ['category' => 'world', 'created_at' => $now, 'updated_at' => $now];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'seasonal.enabled',
            'seasonal.season_count',
            'seasonal.season_duration_days',
            'seasonal.cycle_anchor_date',
            'seasonal.recipe_unlock_count_per_season',
        ])->delete();
    }
}
