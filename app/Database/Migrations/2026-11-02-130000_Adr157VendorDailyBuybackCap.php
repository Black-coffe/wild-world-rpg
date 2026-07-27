<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-157 шаг 2 — суточный лимит выкупа у NPC-торговца: 2 GameSettings.
 *
 * Первый шаг ADR-157 закрыл разгон цены продажи, но базовые цены части предметов
 * остались арбитражем сами по себе: ВСЕ входы ряда рецептов покупаются у того же
 * торговца, причём сырьё отпускается без лимита стока. Замер 2026-07-27: печатают
 * золото 22 позиции из 39; худшая — «Рыбные консервы» (входы ~5.6 золота → выкуп
 * ~374, то есть ×67 за круг), далее Сухпаёк ×32, Тушёнка ×28, Уха ×28, Сытное рагу
 * ×19, «Верстак 1» ×2.7 (+83 458 с единицы).
 *
 * Правка базовых цен обрушила бы доход честных поваров (цена еды отражает пользу,
 * а не себестоимость), поэтому выбран рычаг с минимальным радиусом поражения.
 *
 * Движок — App\Services\Economy\VendorDailyLimitService, точка применения —
 * SellCraftConfirmAction. Учитываются только крафтовые предметы (`transactions`);
 * продажа сырья идёт мимо и под лимит не подпадает (там честный спред 1.05/0.95).
 *
 * WipeManifest: только seed в game_settings (KEEP) — новых таблиц нет, счётчик
 * считается из уже классифицированной `transactions`.
 */
class Adr157VendorDailyBuybackCap extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'economy.trade.daily_buyback_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch суточного лимита выкупа. true — торговец тратит на одного выжившего не больше economy.trade.daily_buyback_cap золота за скользящие 24 часа. Нужен, потому что у 22 позиций из 39 все входы рецепта покупаются у того же торговца без лимита стока, и цикл «закупил вход → скрафтил → сдал» прибылен без потолка.',
                'effect_text'        => 'При true SellCraftConfirmAction перед сделкой сверяет сумму с остатком суток (VendorDailyLimitService) и при превышении отказывает с объяснением, сколько торговец ещё может потратить. Продажа сырья не затрагивается.',
                'above_effect_text'  => 'true (вкл): промышленные циклы упираются в потолок в тот же день; честный игрок, сдающий улов или партию крафта, лимита не замечает.',
                'below_effect_text'  => 'false (выкл): мгновенный откат без редеплоя, но круг «купил вход → сдал результат» снова печатает золото без ограничений.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.trade.daily_buyback_cap',
                'value_type'         => 'float',
                'value_float'        => 50000.0,
                'default_value_text' => '50000',
                'rationale_text'     => 'Сколько золота торговец готов отдать одному выжившему за скользящие сутки. 50 000 выбрано по замеру прода 2026-07-27: без двух персонажей, крутивших петлю, за всю историю набрался 81 «день продаж», средний 20 903 золота, и лишь 9 дней превысили 50 000 — причём и они шли по прежней завышенной формуле, то есть после фикса были бы ниже. Порог покрывает честную игру и при этом упирает промышленный цикл в потолок в тот же день.',
                'effect_text'        => 'VendorDailyLimitService::remaining = cap − сумма продаж крафта за последние 24 часа (таблица transactions). Окно скользящее, не календарное — иначе цикл удваивается на стыке полуночи. Продажа сырья не учитывается.',
                'above_effect_text'  => 'Выше (например 200 000): лимит перестаёт что-либо ограничивать — «Рыбные консервы» дают ~368 золота с единицы, и потолок будет достигаться только очень длинной серией крафта.',
                'below_effect_text'  => 'Ниже (например 10 000): в потолок начнут упираться обычные игроки — 9 исторических дней из 81 были выше 50 000, при 10 000 таких дней было бы более трети.',
                'recommended_min'    => '25000',
                'recommended_max'    => '150000',
                'hard_min'           => '1000',
                'hard_max'           => '10000000',
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
            'economy.trade.daily_buyback_enabled',
            'economy.trade.daily_buyback_cap',
        ])->delete();
    }
}
