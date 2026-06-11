<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-109 (E8) — настройки ежедневных заданий (daily-цикл).
 *
 * 4 ключа категории endgame (как faction-/quests-настройки). killswitch default OFF → DORMANT.
 * Constitutional rule ADR-024: rationale/effect/above/below NOT NULL. Seed game_settings
 * (KEEP) → WipeManifest не затрагивается. Idempotent по setting_key.
 */
class Adr109DailyTasksGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'quests.daily.enabled',
                'category'           => 'endgame',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch ежедневных заданий (E8, ADR-109): 3 задания/день по уровню (разведка/крафт/бой/охота/торговля), baseline-дельта, награда золото + бонус за все 3. default=false → dormant: таблица/движок засижены, генерация и прогресс молчат до активации после Tier-3.',
                'effect_text'        => 'ON: DailyTaskService::ensureAssigned генерирует набор за день при первом контакте; DailyTaskProgressHandler (everyMinute) завершает задания по дельте монотонных счётчиков и выдаёт золото. OFF: ничего не генерится и не прогрессит.',
                'above_effect_text'  => 'true: у игрока появляется ежедневный план — повод вернуться и структурный прогресс (бьёт по узкому месту E1/E7: доведение до L10 через регулярность). Включать после Tier-3 и проверки наград/баланса.',
                'below_effect_text'  => 'false: дейликов нет (dormant). Игроки без ежедневной петли возвращаемости. Emergency-off при инфляции/навязчивости.',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'quests.daily.count',
                'category'           => 'endgame',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Сколько заданий выдаётся в день. 3 — короткий понятный план (цель E8): не перегружает, но даёт выбор активностей. Меньше пула доступных по уровню → выдаётся сколько есть.',
                'effect_text'        => 'Число слотов в наборе (DailyTaskService::ensureAssigned выбирает $count разных шаблонов).',
                'above_effect_text'  => 'Выше (напр. 5): больше наград и активностей в день, но риск «обязаловки» и инфляции золота.',
                'below_effect_text'  => 'Ниже (1-2): легче закрыть, но меньше выбора и вовлечения.',
                'recommended_min'    => '1', 'recommended_max' => '5', 'hard_min' => '1', 'hard_max' => '5',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'quests.daily.reward_multiplier',
                'category'           => 'endgame',
                'value_type'         => 'float',
                'value_float'        => 1.0,
                'default_value_text' => '1.0',
                'rationale_text'     => 'Глобальный множитель золото-наград дейликов (база per-task — в DailyTaskCatalog по tier уровня). Один live-tunable рычаг масштабировать всю экономику дейликов без правки кода. 1.0 = базовые значения каталога.',
                'effect_text'        => 'reward_gold каждого задания = round(catalog_gold × multiplier), фиксируется при назначении.',
                'above_effect_text'  => 'Выше (напр. 2.0): дейлики щедрее → сильнее тянут возвращаться, но инфляц-давление на золото.',
                'below_effect_text'  => 'Ниже (напр. 0.5): дейлики скромнее, меньше инфляции, но и слабее мотивация.',
                'recommended_min'    => '0.5', 'recommended_max' => '3.0', 'hard_min' => '0.1', 'hard_max' => '10.0',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'quests.daily.all_done_bonus_gold',
                'category'           => 'endgame',
                'value_type'         => 'int',
                'value_int'          => 500,
                'default_value_text' => '500',
                'rationale_text'     => 'Разовый бонус золота за выполнение ВСЕХ заданий дня (стимул закрыть весь набор, а не одно). 500 ≈ половина faction-подъёмных — заметно, но скромно. 0 = бонус выключен.',
                'effect_text'        => 'DailyTaskProgressHandler выдаёт бонус один раз, когда все сегодняшние слоты закрыты (one-shot флаг DailyAllDone_<дата> в action_log).',
                'above_effect_text'  => 'Выше (напр. 1500): сильнее тянет закрыть весь набор, но инфляция.',
                'below_effect_text'  => 'Ниже/0: бонус слабый/выключен — игрок может бросить набор на 2/3.',
                'recommended_min'    => '0', 'recommended_max' => '1500', 'hard_min' => '0', 'hard_max' => '100000',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'quests.daily.enabled', 'quests.daily.count',
            'quests.daily.reward_multiplier', 'quests.daily.all_done_bonus_gold',
        ])->delete();
    }
}
