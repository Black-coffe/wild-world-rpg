<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-104 Фаза 2 — «быстрый первый цикл L1-3».
 *
 * Новичок при добыче упирается в минимум 10 минут ожидания (GatherAction
 * hardcoded [10,30,60,120,360,720]) до ПЕРВОГО ресурса → таймер на фоне пустоты
 * (E1/E5: первая сессия = ожидания, не события). Добавляем для низкоуровневых
 * быструю опцию добычи.
 *
 *   • onboarding.fast_start.enabled (bool, default false → DORMANT) — killswitch;
 *   • onboarding.fast_start.max_level (int, default 3) — применять при level ≤ N;
 *   • onboarding.fast_start.gather_minutes (int, default 2) — длительность быстрой опции.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class ADR104Phase2FastStartGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.fast_start.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch «быстрого первого цикла» (ADR-104 Ф2): для новичка (level ≤ max_level) меню добычи получает дополнительную быструю опцию (gather_minutes мин) поверх стандартных [10,30,60,...]. default=false → dormant. Бьёт по «таймеры вместо событий» — сейчас минимум 10 мин ожидания до первого ресурса.',
                'effect_text'        => 'GatherAction в меню добычи (callback gather) prepend'."'".'ит кнопку «⚡ N мин» (callback gather_N) ТОЛЬКО при ON + level ≤ max_level. Обработчик gather_N уже принимает любое N → задача на N минут. Лут масштабируется длительностью (быстрая добыча = меньше лута — честно).',
                'above_effect_text'  => 'true: новичок может собрать первый ресурс за gather_minutes (default 2) вместо 10 мин → быстрый цикл добыча→крафт→продажа, шаг цепочки ADR-103 «добыть Воду» проходится быстро. Прямой удар по пустоте первой сессии.',
                'below_effect_text'  => 'false: быстрая опция не показывается (dormant), минимум добычи = 10 мин как сейчас. Emergency-off.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'onboarding.fast_start.max_level',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Уровневый потолок быстрой опции добычи. 3 = первые уровни, где новичок осваивает цикл; дальше стандартные длительности (ценность времени появляется с прогрессом).',
                'effect_text'        => 'GatherAction показывает быструю кнопку только персонажам level ≤ этого значения.',
                'above_effect_text'  => 'Выше (напр. 6): быстрая добыча доступна дольше — мягче кривая, но размывается ценность «долгой добычи = больше лута» на средних уровнях.',
                'below_effect_text'  => 'Ниже (1): только самый первый уровень. 0 = фактически выключено (никто не подходит).',
                'recommended_min'    => 1,
                'recommended_max'    => 6,
                'hard_min'           => 0,
                'hard_max'           => 100,
            ],
            [
                'setting_key'        => 'onboarding.fast_start.gather_minutes',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => 'Длительность быстрой опции добычи (минуты). 2 = почти мгновенный первый результат, но не нулевой (сохраняется ощущение «задача идёт»). Лут пропорционально меньше 10-минутного — честный обмен.',
                'effect_text'        => 'Быстрая кнопка добычи создаёт задачу на это число минут (callback gather_<minutes>).',
                'above_effect_text'  => 'Выше (напр. 5): дольше ждать первый ресурс, но больше лута. >10 = теряет смысл (не быстрее стандартной опции).',
                'below_effect_text'  => 'Ниже (1): почти мгновенно, минимальный лут. 0 запрещён (hard_min 1) — нулевая задача бессмысленна.',
                'recommended_min'    => 1,
                'recommended_max'    => 10,
                'hard_min'           => 1,
                'hard_max'           => 60,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $keys = [
            'onboarding.fast_start.enabled',
            'onboarding.fast_start.max_level',
            'onboarding.fast_start.gather_minutes',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
