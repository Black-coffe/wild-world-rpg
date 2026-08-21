<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * craft-shortfall-buy-07 (`docs/specs/craft-shortfall-buy/`) — шесть ключей баланса докупки
 * недостающего сырья у торговца при нехватке на крафт (`CraftShortfallBuyService`, story 06;
 * экран — story 08/09). Формула из plan.md §Contracts: `base = Σ gap_i × unitPrice_i` (только
 * покупаемые позиции), `full = Σ need_i × unitPrice_i`, `share = base/full`,
 * `markup = base_markup_pct + slope_markup_pct × share`,
 * `total = max(base + min_markup_gold, ceil(base × (1 + markup)))`.
 *
 * `craft.shortfall_buy.enabled` — мастер-killswitch, default false: экран нехватки не показывает
 * блок докупки, пока фича не прошла Tier-3 смок на preprod (ADR по спеке craft-shortfall-buy).
 * `craft.shortfall_buy.profit_gate_enabled` — выключен по решению владельца (plan.md
 * `## Approved`): гейт рентабельности не защищает уже открытый цикл «купил сырьё → собрал →
 * продал» (замер story 12), поэтому в первой версии не включается.
 * `craft.shortfall_buy.max_units_per_purchase` — единственная защита от превращения докупки
 * в закупку впрок: лимит 1 шт. за сделку, ручка в админке до hard_max 10.
 *
 * Idempotent по `setting_key` (как SeedMarketDecayGameSettings). `game_settings` = KEEP
 * (WipeManifest не трогаем — новых таблиц/колонок нет).
 */
class SeedCraftShortfallBuyGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'craft.shortfall_buy.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Мастер-killswitch блока «Докупить у торговца» на экране нехватки крафта. false (default) — блок не показывается, экран нехватки ведёт себя как до этой задачи. true — включается после Tier-3 смока на preprod-testbot.',
                'effect_text'        => 'CraftShortageService гейтит рендер блока докупки и приём callback craftBuy_*/craftBuyGo_*/craftBuyOnly_* этим ключом.',
                'above_effect_text'  => 'true — игрок с нехваткой сырья получает выход в один тап: докупить недостающее у торговца и стартовать крафт.',
                'below_effect_text'  => 'false — экран нехватки остаётся тупиком без выхода, как до story craft-shortfall-buy: игрок видит «Недостаточно ресурсов» без действия.',
            ],
            [
                'setting_key'        => 'craft.shortfall_buy.base_markup_pct',
                'value_type'         => 'int',
                'value_int'          => 15,
                'default_value_text' => '15',
                'rationale_text'     => 'Базовая наценка торговца за срочность, в процентах от base-стоимости недостающего сырья. Крон пересчитывает buy_price = base × factor × 1.05, sell_price = base × factor × 0.95 — покупка уже на 10.5% хуже продажи добытого сырья даже при нулевой наценке докупки. 15% сверху делает разницу заметной с первой же докупаемой единицы, сохраняя добычу выгоднее покупки. hard_min = 5: якорь строки экрана «Добыть почти всегда дешевле, чем купить» остаётся правдой при любом допустимом значении — 5 пунктов держат запас поверх встроенных 10.5%.',
                'effect_text'        => 'CraftShortfallBuyService::quote() использует как стартовый член формулы markup = base_markup_pct + slope_markup_pct × share, применяемой к base-стоимости недостающих позиций.',
                'above_effect_text'  => 'Выше 15% — докупка мелкой недостачи (share близко к 0) заметно дороже, добыча самому становится выгоднее даже для одной единицы, что снижает востребованность фичи.',
                'below_effect_text'  => 'Ниже hard_min = 5 сохранение запрещено: при 5 пунктах суммарная разница покупка/продажа сырья держится на 15.5%+, ниже — запас истончается и при живых колебаниях цены (ADR-175) добыча может перестать быть очевидно дешевле покупки, ломая честность строки экрана.',
                'recommended_min'    => '10',
                'recommended_max'    => '30',
                'hard_min'           => '5',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'craft.shortfall_buy.slope_markup_pct',
                'value_type'         => 'int',
                'value_int'          => 35,
                'default_value_text' => '35',
                'rationale_text'     => 'Наклон наценки по доле докупки (share = base/full). 35 — при докупке почти всей сборки (share → 1) наценка вырастает на дополнительные ~35 пунктов сверх base_markup_pct, делая покупку рецепта целиком заметно дороже, чем добор одной недостающей позиции — так добрать мелочь дёшево, а купить рецепт целиком дороже, чем в магазине.',
                'effect_text'        => 'CraftShortfallBuyService::quote() умножает slope_markup_pct на share и прибавляет к base_markup_pct в формуле markup = base_markup_pct + slope_markup_pct × share.',
                'above_effect_text'  => 'Выше 35% — докупка большой доли рецепта дорожает резче, что сильнее отталкивает от использования фичи как замены полноценной добычи/сборки.',
                'below_effect_text'  => 'Ниже 35% — наценка почти не растёт с долей докупки, докупка всей сборки становится почти так же дёшева, как добор одной позиции, и фича начинает конкурировать с добычей вместо того, чтобы её дополнять.',
                'recommended_min'    => '15',
                'recommended_max'    => '60',
                'hard_min'           => '0',
                'hard_max'           => '200',
            ],
            [
                'setting_key'        => 'craft.shortfall_buy.min_markup_gold',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'Минимальная наценка в золоте поверх base-стоимости, чтобы округление процента не съедало наценку до нуля на дешёвом сырье (total = max(base + min_markup_gold, ceil(base × (1 + markup)))). 1 золото — символическая плата за срочность даже на однокопеечной позиции, без искажения выгоды крупных докупок.',
                'effect_text'        => 'CraftShortfallBuyService::quote() берёт максимум из (base + min_markup_gold) и (base × (1 + markup)) при расчёте total.',
                'above_effect_text'  => 'Выше 1 — на дешёвом сырье наценка становится заметнее процентной, докупка одной копеечной позиции ощутимо дороже её базовой цены.',
                'below_effect_text'  => 'Ниже 1 (то есть 0) — при округлении процентной наценки вниз на дешёвом сырье докупка может стоить ровно как база, то есть без платы за срочность вовсе.',
                'recommended_min'    => '0',
                'recommended_max'    => '5',
                'hard_min'           => '0',
                'hard_max'           => '50',
            ],
            [
                'setting_key'        => 'craft.shortfall_buy.max_units_per_purchase',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'Лимит штук рецепта, докупаемых одной сделкой. От превращения докупки в закупку впрок защищает не доля, а именно этот лимит: 1 в первой версии — докупка остаётся доводкой одной начатой сборки, не способом заготовить сырьё на несколько будущих крафтов разом.',
                'effect_text'        => 'CraftShortfallBuyService::quote() и экран подтверждения (story 09) ограничивают запрашиваемое qty этим значением; выше лимита кнопка докупки недоступна для данного qty.',
                'above_effect_text'  => 'Выше 1 докупка перестаёт быть доводкой одной сборки и становится закупкой партии: игрок получает возможность одной сделкой докупить сырьё сразу на несколько будущих крафтов, минуя ценовой сигнал добычи как основной источник сырья.',
                'below_effect_text'  => 'Ниже 1 (то есть 0) фича фактически выключена: докупить нельзя ни одной единицы даже для доводки текущей сборки.',
                'recommended_min'    => '1',
                'recommended_max'    => '3',
                'hard_min'           => '0',
                'hard_max'           => '10',
            ],
            [
                'setting_key'        => 'craft.shortfall_buy.profit_gate_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Гейт рентабельности (запрет докупки, если стоимость докупленного сырья выше ожидаемой ценности итогового предмета) выкатывается выключенным по решению владельца (plan.md `## Approved`): замер (story 12) показал, что цикл «купил сырьё у торговца → собрал → продал» уже открыт и дешевле докупки, поэтому гейт не закрывает существующую дыру, а только мешает докупке ради самой сборки.',
                'effect_text'        => 'CraftShortfallBuyService::quote() пропускает проверку profit_gate и не выставляет refusal = profit_gate, пока флаг выключен.',
                'above_effect_text'  => 'true — докупка недостающего блокируется, если суммарная стоимость покупки сырья превышает ожидаемую ценность собранного предмета; включать только по данным замера story 12, а не заранее.',
                'below_effect_text'  => 'false (default) — гейт не проверяется, докупка доступна независимо от соотношения цены сырья и ценности предмета; риск компенсируется лимитом штук и наценкой, а не этим флагом.',
            ],
        ];

        $defaults = [
            'category'        => 'craft',
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
            'craft.shortfall_buy.enabled',
            'craft.shortfall_buy.base_markup_pct',
            'craft.shortfall_buy.slope_markup_pct',
            'craft.shortfall_buy.min_markup_gold',
            'craft.shortfall_buy.max_units_per_purchase',
            'craft.shortfall_buy.profit_gate_enabled',
        ])->delete();
    }
}
