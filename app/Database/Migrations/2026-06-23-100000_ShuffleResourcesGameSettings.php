<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-093 — «Перемешать ресурсы»: 3 economy.shuffle.* GameSettings (admin-tunable, ADR-024).
 *
 * RNG-конвертер внутри одной редкости (ShuffleResourcesAction): killswitch, % потерь при
 * пересыпке (house edge / sink), варианты количеств. Каждый ключ с rich rationale
 * (why/effect/above/below) — invariant ADR-024. category='economy'. Idempotent.
 */
class ShuffleResourcesGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'economy.shuffle.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch мини-игры «Перемешать ресурсы». true — игра доступна из меню Развлечения.',
                'effect_text'        => 'false → ShuffleResourcesAction отвечает «игра временно недоступна», перемешивание не выполняется.',
                'above_effect_text'  => 'true (вкл): RNG-обмен ресурсов внутри редкости доступен.',
                'below_effect_text'  => 'false (выкл): мгновенный откат фичи без редеплоя (при эксплойте/инфляции).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.shuffle.loss_pct',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Процент потерь при пересыпке (house edge / sink). 10 — отдал 50, получил 45: лёгкий, но постоянный sink, чтобы перемешивание не было бесплатным «прачечным» обменом ресурсов. Редкость сохраняется, потому экономике важен именно объёмный sink, а не редкостный.',
                'effect_text'        => 'ShuffleResourcesAction зачисляет получателю floor(count × (100−это)/100). Caption печатает реальный % и абсолютную потерю.',
                'above_effect_text'  => 'При 50+ обмен крайне невыгоден (отдал 50 → получил ≤25) → игроки перестают пользоваться, фича мертва.',
                'below_effect_text'  => 'При 0 обмен 1:1 без потерь → бесплатное перекладывание объёма между ресурсами одной редкости, sink исчезает (можно бесконечно «искать» нужный ресурс).',
                'recommended_min'    => '5',
                'recommended_max'    => '25',
                'hard_min'           => '0',
                'hard_max'           => '90',
            ],
            [
                'setting_key'        => 'economy.shuffle.count_options',
                'value_type'         => 'string',
                'value_string'       => '5,10,25,50',
                'default_value_text' => '5,10,25,50',
                'rationale_text'     => 'CSV вариантов количеств для перемешивания (кнопки). 5,10,25,50 — лесенка от мелкой пробы до крупной партии. Минимум списка = порог доступности редкости (редкость показывается, только если есть ≥ минимума одного ресурса).',
                'effect_text'        => 'ShuffleResourcesAction строит кнопки количеств из этого списка (целые > 0 через запятую; сортируются, дубли убираются). Показываются только те, что игрок может позволить.',
                'above_effect_text'  => 'Слишком крупные значения (например 5000) → разовый риск перемешать огромную партию в случайный ресурс.',
                'below_effect_text'  => 'Только мелкие значения → обмен ощущается несущественным, слабый sink.',
                'hard_min'           => null,
                'hard_max'           => null,
            ],
        ];

        $defaults = [
            'category'        => 'economy',
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
            'economy.shuffle.enabled',
            'economy.shuffle.loss_pct',
            'economy.shuffle.count_options',
        ])->delete();
    }
}
