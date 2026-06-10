<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-104 Фаза 1 — «Стартовый набор выжившего».
 *
 * Сидит 5 ключей `onboarding.starter_kit.*`:
 *   • enabled (bool, default false → DORMANT) — killswitch выдачи набора на /start;
 *   • water / wood / bark / pebble (int) — количества базовых ресурсов в наборе.
 *
 * Закрывает прод-разрыв №1 (E1/E5): 54% новичков L1 имеют ПУСТОЙ инвентарь и
 * не делают ни одного действия → набор заполняет инвентарь и разблокирует ранний
 * цикл обучающей цепочки (ADR-103: крафт/продажа возможны в первую минуту, не
 * дожидаясь 10-минутной добычи).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed существующей game_settings (НЕ createTable) → WipeManifest не затрагивается
 * (game_settings уже KEEP). Идемпотентно по setting_key.
 */
class ADR104StarterKitGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'onboarding.starter_kit.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch «Стартового набора выжившего» (ADR-104 Фаза 1): при создании персонажа на /start выдаём небольшой паёк базовых ресурсов (Вода/Древесина/Кора/Галька) + сообщение Роби. default=false → dormant: код выдачи спит до активации после Tier-3. Бьёт по прод-разрыву №1 — 54% новичков L1 с пустым инвентарём и без единого действия.',
                'effect_text'        => 'StarterKitService::grant (в ветке создания нового чара StartCommand) видаёт ресурсы и пишет idempotency-флаг STARTER_KIT_GRANTED в action_log ТОЛЬКО при ON. Заполняет инвентарь новичка и делает первый крафт/продажу возможными сразу (разблокирует ранний цикл обучающей цепочки ADR-103).',
                'above_effect_text'  => 'true: каждый НОВЫЙ персонаж получает паёк (one-shot, only at creation). Новичок начинает не с пустоты, а с материалом на первый крафт/сделку — прямой удар по 54% пустых инвентарей и раннему оттоку.',
                'below_effect_text'  => 'false: набор не выдаётся (dormant), новичок стартует с пустым инвентарём как сейчас — должен сам добыть первый ресурс (минимум 10 мин ожидания). Использовать как emergency-off, если набор окажется лишним/багованным.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'onboarding.starter_kit.water',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Количество Воды (resource id 17, rarity 10, lvl 1) в стартовом наборе. Вода — самый базовый ресурс выживания (питьё) и материал; 10 ед. = на первую сделку/крафт, не нарушая экономику (новичок добывает столько за минуты).',
                'effect_text'        => 'StarterKitService::grant выдаёт это число Воды новому чару (addOrIncreaseResource).',
                'above_effect_text'  => 'Выше: больше стартовой Воды — новичок дольше не нуждается в добыче воды, но и слабее стимул двигаться/добывать. >100 = заметная инъекция в раннюю экономику.',
                'below_effect_text'  => 'Ниже (0): Воду в набор не кладём. 0 = ресурс исключён из пайка.',
                'recommended_min'    => 0,
                'recommended_max'    => 50,
                'hard_min'           => 0,
                'hard_max'           => 500,
            ],
            [
                'setting_key'        => 'onboarding.starter_kit.wood',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Количество Древесины (resource id 2, rarity 8, lvl 1) в наборе. Древесина — ключевой крафт/строй-материал; 10 ед. разблокируют первый крафт обучающей цепочки.',
                'effect_text'        => 'StarterKitService::grant выдаёт это число Древесины новому чару.',
                'above_effect_text'  => 'Выше: больше стройматериала на старте, быстрее первая постройка, но слабее стимул собирать. >100 = заметная инъекция.',
                'below_effect_text'  => 'Ниже (0): Древесину в набор не кладём.',
                'recommended_min'    => 0,
                'recommended_max'    => 50,
                'hard_min'           => 0,
                'hard_max'           => 500,
            ],
            [
                'setting_key'        => 'onboarding.starter_kit.bark',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 8,
                'default_value_text' => '8',
                'rationale_text'     => 'Количество Коры деревьев (resource id 8, rarity 8, lvl 1) в наборе. Распространённый крафт-материал; 8 ед. дополняют материал для первых рецептов.',
                'effect_text'        => 'StarterKitService::grant выдаёт это число Коры новому чару.',
                'above_effect_text'  => 'Выше: больше крафт-материала на старте. >100 = заметная инъекция.',
                'below_effect_text'  => 'Ниже (0): Кору в набор не кладём.',
                'recommended_min'    => 0,
                'recommended_max'    => 50,
                'hard_min'           => 0,
                'hard_max'           => 500,
            ],
            [
                'setting_key'        => 'onboarding.starter_kit.pebble',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 8,
                'default_value_text' => '8',
                'rationale_text'     => 'Количество Гальки (resource id 19, rarity 9, lvl 1) в наборе. Базовый строй/крафт-материал; 8 ед. дополняют стартовый паёк.',
                'effect_text'        => 'StarterKitService::grant выдаёт это число Гальки новому чару.',
                'above_effect_text'  => 'Выше: больше материала на старте. >100 = заметная инъекция.',
                'below_effect_text'  => 'Ниже (0): Гальку в набор не кладём.',
                'recommended_min'    => 0,
                'recommended_max'    => 50,
                'hard_min'           => 0,
                'hard_max'           => 500,
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
            'onboarding.starter_kit.enabled',
            'onboarding.starter_kit.water',
            'onboarding.starter_kit.wood',
            'onboarding.starter_kit.bark',
            'onboarding.starter_kit.pebble',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
