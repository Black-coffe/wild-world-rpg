<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-157 — цены NPC-торговца на крафтовые предметы: 7 economy.trade.* GameSettings.
 *
 * Закрывает инвертированный спред: до фикса при нейтральной карме продажа шла
 * по той же цене, что и покупка, складской бонус (ADR-085) делал продажу дороже
 * покупки, а карма росла от суммы продажи без потолка — цена продажи разгоняла
 * сама себя до зажима 10.5× базовой. На проде это дало 77.4 млн золота одному
 * персонажу за две недели (48 продаж «Верстака 1»).
 *
 * Числа читает App\Services\Economy\TradePricingService. Инвариант «продажа
 * всегда дешевле покупки» захардкожен в сервисе и НЕ отключается настройками —
 * ключи задают только силу эффекта.
 *
 * WipeManifest: только seed в game_settings (KEEP) — новых таблиц/player-колонок нет.
 */
class Adr157TradePricingInvariant extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'economy.trade.karma_min',
                'value_type'         => 'float',
                'value_float'        => 0.0,
                'default_value_text' => '0',
                'rationale_text'     => 'Нижняя граница Кармы торговли. Карма растёт от продаж и падает от покупок; без пола она уходила в минус (на проде был персонаж с −100.89), что давало бессмысленно штрафные цены и запутывало игрока. 0 = «торговец тебе не доверяет», хуже уже некуда.',
                'effect_text'        => 'TradePricingService::normalizeKarma прижимает карму к этой границе и при чтении цены, и при записи после сделки.',
                'above_effect_text'  => 'Выше (например 50): штрафной коридор сужается, плохая репутация почти не наказывается — торговля перестаёт быть механикой с последствиями.',
                'below_effect_text'  => 'Ниже 0: возвращается отрицательная карма, множитель покупки упирается в hard-потолок и экран показывает абсурдные цены.',
                'recommended_min'    => '0',
                'recommended_max'    => '100',
                'hard_min'           => '0',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'economy.trade.karma_max',
                'value_type'         => 'float',
                'value_float'        => 200.0,
                'default_value_text' => '200',
                'rationale_text'     => 'Верхняя граница Кармы торговли. Именно её отсутствие было корнем эксплойта: карма росла на «цена × 0.0002» с каждой продажи, а цена продажи росла от кармы — петля разгоняла сама себя до 15 592 при норме 100. 200 = вдвое выше нейтрали, потолок достижим за разумное число честных сделок.',
                'effect_text'        => 'Потолок кармы при чтении цены и при записи после сделки. При 200 множитель продажи упирается в свой максимум, дальше репутация не даёт ничего.',
                'above_effect_text'  => 'Выше (например 1000): путь к лучшим ценам длиннее, но возвращается разгон — чем больше продал, тем дороже продаёшь дальше.',
                'below_effect_text'  => 'Ниже (например 120): лучшая цена достигается за несколько сделок, карма перестаёт быть целью и превращается в формальность.',
                'recommended_min'    => '120',
                'recommended_max'    => '500',
                'hard_min'           => '100',
                'hard_max'           => '1000',
            ],
            [
                'setting_key'        => 'economy.trade.sell_multiplier_min',
                'value_type'         => 'float',
                'value_float'        => 0.50,
                'default_value_text' => '0.50',
                'rationale_text'     => 'Минимальный множитель цены продажи (при нулевой карме). 0.50 — половина базовой цены: заметный штраф за испорченную репутацию, но не грабёж, при котором продавать бессмысленно.',
                'effect_text'        => 'Пол множителя в TradePricingService::sellMultiplier. Игрок с нулевой кармой получает за предмет половину базовой цены.',
                'above_effect_text'  => 'Выше (0.80): штраф за плохую карму почти не чувствуется, репутация перестаёт влиять на решения.',
                'below_effect_text'  => 'Ниже (0.20): испорченная карма фактически закрывает торговлю; игрок, случайно просевший на закупках, оказывается отрезан от sink-механики.',
                'recommended_min'    => '0.30',
                'recommended_max'    => '0.80',
                'hard_min'           => '0.05',
                'hard_max'           => '1.00',
            ],
            [
                'setting_key'        => 'economy.trade.sell_multiplier_max',
                'value_type'         => 'float',
                'value_float'        => 1.00,
                'default_value_text' => '1.00',
                'rationale_text'     => 'Максимальный множитель цены продажи ОТ КАРМЫ. 1.00 = сама по себе репутация не поднимает цену выше базовой: хорошая карма возвращает честную цену, но не создаёт премию. Прежний потолок 10.5× и был печатным станком — «Верстак 1» с базой 132 000 уходил за 1 386 000 при себестоимости 20 000 золота. Внешние бонусы (складской ADR-085, +10%) применяются поверх и ограничены инвариантом спреда.',
                'effect_text'        => 'Потолок множителя в TradePricingService::sellMultiplier. Нейтральная карма (100) даёт ровно 1.00 — доход честных игроков не изменился относительно поведения до фикса.',
                'above_effect_text'  => 'Выше 1.00: торговец платит больше базовой цены — возвращается премия за карму, а вместе с ней и мотив крутить круг «крафт → продажа» ради золота, а не ради предметов.',
                'below_effect_text'  => 'Ниже 1.00: продажа крафта режется всем игрокам сразу, включая тех, кто ничего не эксплуатировал; это уже не защита, а нерф экономики.',
                'recommended_min'    => '0.90',
                'recommended_max'    => '1.00',
                'hard_min'           => '0.50',
                'hard_max'           => '1.50',
            ],
            [
                'setting_key'        => 'economy.trade.buy_multiplier_min',
                'value_type'         => 'float',
                'value_float'        => 1.25,
                'default_value_text' => '1.25',
                'rationale_text'     => 'Минимальный множитель цены покупки (при максимальной карме). 1.25 — торговец в лучшем случае просит на четверть выше базовой цены. Это и есть источник спреда: покупка всегда дороже продажи, поэтому круг «купил → продал» гарантированно убыточен.',
                'effect_text'        => 'Пол множителя в TradePricingService::buyMultiplier. До фикса пол был 0.33 — покупка вчетверо дешевле базовой при продаже вдесятеро дороже.',
                'above_effect_text'  => 'Выше (1.60): покупка у торговца становится невыгодной почти всегда, ассортимент лавки превращается в декорацию.',
                'below_effect_text'  => 'Ниже 1.10: спред схлопывается, круг «купил → продал» приближается к безубыточности, и любая будущая авто-кнопка превращает его в станок.',
                'recommended_min'    => '1.15',
                'recommended_max'    => '1.60',
                'hard_min'           => '1.05',
                'hard_max'           => '3.00',
            ],
            [
                'setting_key'        => 'economy.trade.buy_multiplier_max',
                'value_type'         => 'float',
                'value_float'        => 3.00,
                'default_value_text' => '3.00',
                'rationale_text'     => 'Максимальный множитель цены покупки (при нулевой карме). 3.00 — тройная цена как потолок наказания за испорченную репутацию.',
                'effect_text'        => 'Потолок множителя в TradePricingService::buyMultiplier.',
                'above_effect_text'  => 'Выше (5.00): игрок с просевшей кармой видит числа, которые читаются как поломка интерфейса, а не как штраф.',
                'below_effect_text'  => 'Ниже (1.50): разница между хорошей и плохой репутацией почти исчезает, карма теряет смысл.',
                'recommended_min'    => '2.00',
                'recommended_max'    => '4.00',
                'hard_min'           => '1.10',
                'hard_max'           => '10.00',
            ],
            [
                'setting_key'        => 'economy.trade.min_spread_pct',
                'value_type'         => 'float',
                'value_float'        => 0.10,
                'default_value_text' => '0.10',
                'rationale_text'     => 'Минимальный разрыв между ценой покупки и ценой продажи. Итоговая цена продажи (уже со складским бонусом ADR-085) не может превышать цену покупки минус этот процент. 0.10 совпадает с потерей 10% у «Перемешать ресурсы» (ADR-093), где потеря введена ровно против бесплатной прачечной.',
                'effect_text'        => 'Последний шаг TradePricingService::sellUnitPrice. Применяется ПОСЛЕ всех внешних бонусов, поэтому ни складской бонус, ни будущие модификаторы не могут сделать продажу выгоднее покупки.',
                'above_effect_text'  => 'Выше (0.30): торговля через NPC становится ощутимо затратной, игроки перестают пользоваться лавкой для перекладывания излишков.',
                'below_effect_text'  => 'Ниже 0.05: круг «купил → продал» становится почти безубыточным; сервис не даст уйти ниже 1% (SPREAD_FLOOR), но и 1-2% — это приглашение крутить круг ботом.',
                'recommended_min'    => '0.08',
                'recommended_max'    => '0.30',
                'hard_min'           => '0.01',
                'hard_max'           => '0.50',
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
            'economy.trade.karma_min',
            'economy.trade.karma_max',
            'economy.trade.sell_multiplier_min',
            'economy.trade.sell_multiplier_max',
            'economy.trade.buy_multiplier_min',
            'economy.trade.buy_multiplier_max',
            'economy.trade.min_spread_pct',
        ])->delete();
    }
}
