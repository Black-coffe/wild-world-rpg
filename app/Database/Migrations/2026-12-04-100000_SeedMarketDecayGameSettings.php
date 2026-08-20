<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * market-01 (`docs/specs/market-decay/`) — пропорциональное затухание рыночных счётчиков
 * `resources_bank.resources_purchased`/`resources_sold` вместо `-1` за тик (brief.md — аудит
 * прод-данных: до 4.56 млн на счётчик, возврат к базовой цене занял бы 5-9 лет).
 *
 * `economy.market.proportional_decay_enabled` — мастер-killswitch. default false: старое
 * поведение (`-1` за тик) байт-идентично, флип — после Tier-1 смока на preprod-testbot.
 * `economy.market.half_life_hours` / `economy.market.counter_cap` — читает
 * `ResourceBankUpdateHandler::decayCounters()` только когда killswitch включён.
 *
 * Idempotent по `setting_key` (как SeedVehicleGameSettings). `game_settings` = KEEP
 * (WipeManifest не трогаем — новых таблиц/колонок нет).
 */
class SeedMarketDecayGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'economy.market.proportional_decay_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Мастер-killswitch пропорционального затухания рыночных счётчиков (story market-01). false (default) — ResourceBankUpdateHandler состаривает счётчики на -1 за тик, как до этой задачи. true — счётчики затухают экспоненциально с полураспадом и втягиваются в потолок.',
                'effect_text'        => 'ResourceBankUpdateHandler::process() гейтит выбор между старым `-1` и decayCounters() этим ключом.',
                'above_effect_text'  => 'true — цены снова реагируют на недавнюю торговлю; включать только после Tier-1 смока на preprod-testbot (brief.md).',
                'below_effect_text'  => 'false — счётчики остаются пожизненными суммами; на проде это означает замороженные у клампов цены (аудит brief.md: 41 из 80 ресурсов у нижнего 0.35×).',
            ],
            [
                'setting_key'        => 'economy.market.half_life_hours',
                'value_type'         => 'float',
                'value_float'        => 48.0,
                'default_value_text' => '48',
                'rationale_text'     => 'Период полураспада счётчиков purchased/sold в часах. 48ч — рынок помнит примерно двое суток торговли, дальше сделки перестают давить на цену: достаточно долго, чтобы цена не дёргалась от одной крупной сделки, и достаточно коротко, чтобы недельный простой уже заметно отпускал цену к базе.',
                'effect_text'        => 'ResourceBankUpdateHandler::decayCounters() множит оба счётчика на `2 ** (-intervalMinutes / (half_life_hours × 60))` каждый тик крона.',
                'above_effect_text'  => 'Выше 48ч — рынок «забывает» медленнее, старые сделки продолжают давить на цену дольше, чем чувствуется игроком как актуальная торговля.',
                'below_effect_text'  => 'Ниже 48ч — цена скачет от каждой сделки почти сразу же откатывается к базе, рынок перестаёт ощущаться как накопленный спрос/предложение.',
                'recommended_min'    => '12',
                'recommended_max'    => '168',
                'hard_min'           => '1',
                'hard_max'           => '720',
            ],
            [
                'setting_key'        => 'economy.market.counter_cap',
                'value_type'         => 'int',
                'value_int'          => 2000,
                'default_value_text' => '2000',
                'rationale_text'     => 'Потолок счётчика purchased/sold после затухания. 2000 — на порядок больше типичной суточной торговли одним ресурсом, но на порядки меньше прод-миллионов: втягивает историческую заморозку в диапазон, где новая сделка снова двигает ratio, не обнуляя рынок при флипе килсвитча.',
                'effect_text'        => 'ResourceBankUpdateHandler::decayCounters() масштабирует purchased/sold вниз одним множителем, если максимум после затухания выше этого потолка.',
                'above_effect_text'  => 'Выше 2000 — первые тики после флипа килсвитча дольше выводят прод-миллионы в рабочий диапазон, ratio дольше остаётся у клампа.',
                'below_effect_text'  => 'Ниже 2000 — потолок сам начинает доминировать над затуханием, счётчик почти всегда упирается в cap, а не в реальную недавнюю торговлю.',
                'recommended_min'    => '500',
                'recommended_max'    => '5000',
                'hard_min'           => '100',
                'hard_max'           => '50000',
            ],
        ];

        $defaults = [
            'category'        => 'resources',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'economy.market.proportional_decay_enabled',
            'economy.market.half_life_hours',
            'economy.market.counter_cap',
        ])->delete();
    }
}
