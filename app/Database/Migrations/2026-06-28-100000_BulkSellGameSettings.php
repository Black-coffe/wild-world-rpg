<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-096 — Оптовая продажа ресурсов: 2 economy.bulk_sell.* GameSettings (admin-tunable, ADR-024).
 *
 * «Продать N% всех ресурсов» (BulkSellAction) на экранах продажи. Killswitch +
 * варианты процентов (CSV). Каждый ключ с rich rationale (why/effect/above/below) —
 * invariant ADR-024. category='economy'. Idempotent.
 *
 * WipeManifest: только seed в game_settings (KEEP) — новых таблиц/player-колонок нет.
 */
class BulkSellGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'economy.bulk_sell.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch оптовой продажи («Продать N% всех ресурсов»). true — кнопки видны на экранах продажи (выбор редкости и внутри редкости).',
                'effect_text'        => 'false → кнопки оптовой продажи не показываются, BulkSellAction отвечает «временно недоступно». Поштучная продажа не затрагивается.',
                'above_effect_text'  => 'true (вкл): игрок может одним подтверждением продать 10/25/50% всех (или одной редкости) ресурсов сразу.',
                'below_effect_text'  => 'false (выкл): мгновенный откат фичи без редеплоя (при эксплойте/жалобах на случайную распродажу).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.bulk_sell.percent_options',
                'value_type'         => 'string',
                'value_string'       => '10,25,50',
                'default_value_text' => '10,25,50',
                'rationale_text'     => 'CSV вариантов доли для оптовой продажи (кнопки «💰 N%»). 10,25,50 — из ТЗ Asana: лёгкая чистка излишков (10%), заметная партия (25%), агрессивная распродажа половины (50%). Доля берётся от каждого запаса (floor), продаются только ходовые ресурсы (sell_price>0).',
                'effect_text'        => 'BulkSellAction строит кнопки процентов из этого списка (целые 1..100 через запятую; сортируются, дубли убираются) на экранах SellAction (scope=все) и SellResourceAction (scope=редкость). Значение вне списка в callback отклоняется.',
                'above_effect_text'  => 'Добавить 100 → кнопка «продать ВСЁ» одним тапом (после подтверждения): удобно, но повышает риск опустошить инвентарь.',
                'below_effect_text'  => 'Только мелкие доли (например «5,10») → оптовая продажа ощущается несущественной, игроки продолжат кликать поштучно.',
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
            'economy.bulk_sell.enabled',
            'economy.bulk_sell.percent_options',
        ])->delete();
    }
}
