<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-133 — GameSettings для «Оракул острова» (admin-tunable, ADR-024).
 *
 * Всё под killswitch oracle.enabled=false (dormant). category='economy' (как N5).
 * Каждый ключ с rich rationale (why/effect/above/below) — invariant ADR-024. Idempotent.
 */
class Adr133OracleGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            // ── Killswitch'и ─────────────────────────────────────────────
            [
                'setting_key'        => 'oracle.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Главный killswitch «Оракула острова» (пари-мютюэль рынок предсказаний на золото). false = dormant: кнопка в Развлечениях скрыта, крон-расчёт выходит мгновенно, ставки не принимаются.',
                'effect_text'        => 'true → фича активна (кнопка видна с L10, рынки открываются/закрываются/рассчитываются кроном). false → полностью выключена без редеплоя.',
                'above_effect_text'  => 'true (вкл): золото-ставочный рынок доступен игрокам — активировать только после Tier-3 на testbot + balance-review + анонса.',
                'below_effect_text'  => 'false (выкл): мгновенный откат всей механики (например при обнаружении эксплойта).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'oracle.weekly_market_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Включает недельный флагман-рынок «Гонка фракций». true — крон открывает его на каждую ISO-неделю.',
                'effect_text'        => 'false → недельный рынок не создаётся (остаётся только дневной, если включён).',
                'above_effect_text'  => 'true (вкл): главный «в долгую» рынок доступен.',
                'below_effect_text'  => 'false (выкл): убрать недельный рынок, не трогая дневной.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'oracle.daily_market_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Включает дневной микро-рынок «Сработает ли событие сегодня?» (держит ликвидность тёплой между недельными расчётами). true — крон открывает его на каждый день.',
                'effect_text'        => 'false → дневной рынок не создаётся (остаётся только недельный, если включён).',
                'above_effect_text'  => 'true (вкл): «в короткую» рынок доступен ежедневно.',
                'below_effect_text'  => 'false (выкл): убрать дневной рынок, не трогая недельный.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],

            // ── Экономика ставок ─────────────────────────────────────────
            [
                'setting_key'        => 'oracle.rake_pct',
                'value_type'         => 'float',
                'value_float'        => 0.08,
                'default_value_text' => '0.08',
                'rationale_text'     => 'Доля банка, которую дом забирает и СЖИГАЕТ на расчёте (единственный сток механики; остальное делится между угадавшими). 0.08 = 8% — мягче существующих игр (Угадай EV −16.7%), т.к. Оракул это мета-скилл, а не рулетка.',
                'effect_text'        => 'На расчёте: рейк = floor(банк × это), к дележу = банк − рейк, выплата_i = ставка_i × (к_дележу / пул_победы). Инвариант Σвыплат ≤ Σставок.',
                'above_effect_text'  => 'При 0.20+ дом сжигает 20%+ банка → сильный сток золота, но игрокам кисло (низкие котировки), участие падает.',
                'below_effect_text'  => 'При 0.02 почти нет стока → механика перестаёт бороться с инфляцией золота.',
                'recommended_min'    => '0.05',
                'recommended_max'    => '0.12',
                'hard_min'           => '0',
                'hard_max'           => '0.25',
            ],
            [
                'setting_key'        => 'oracle.min_stake',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Минимальная ставка за рынок. 50 = порогу Угадая — отсекает копеечные ставки, не пугая раннего игрока (гейт L10 уже отсекает совсем новичков).',
                'effect_text'        => 'Ставка ниже этого значения отклоняется при размещении.',
                'above_effect_text'  => 'При 1000+ ставки доступны лишь богатым → тонкий пул, мало участников.',
                'below_effect_text'  => 'При 1 микроставки засоряют пул и расчёт.',
                'recommended_min'    => '10',
                'recommended_max'    => '500',
                'hard_min'           => '1',
                'hard_max'           => '1000000',
            ],
            [
                'setting_key'        => 'oracle.max_stake',
                'value_type'         => 'int',
                'value_int'          => 5000,
                'default_value_text' => '5000',
                'rationale_text'     => 'Максимальная ставка за рынок на игрока. 5000 не даёт киту доминировать тонкий пул (~147 игроков) и искажать котировки.',
                'effect_text'        => 'Сумма всех ставок игрока на один рынок не может превысить это значение.',
                'above_effect_text'  => 'При 100000+ один кит формирует весь пул → котировки бессмысленны, остальные не участвуют.',
                'below_effect_text'  => 'При 100 даже середняку тесно → банк мал, призы символические.',
                'recommended_min'    => '1000',
                'recommended_max'    => '20000',
                'hard_min'           => '1',
                'hard_max'           => '100000000',
            ],
            [
                'setting_key'        => 'oracle.bet_options',
                'value_type'         => 'string',
                'value_string'       => '50,100,250,500,1000',
                'default_value_text' => '50,100,250,500,1000',
                'rationale_text'     => 'CSV кнопок быстрых ставок. Лесенка от минимума до крупной. Значения должны лежать между min_stake и max_stake.',
                'effect_text'        => 'OracleAction строит кнопки ставок из этого списка (целые через запятую).',
                'above_effect_text'  => 'Слишком крупные кнопки → разовый риск больших потерь золота.',
                'below_effect_text'  => 'Только мелкие → банк символический, механика вялая.',
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'oracle.min_level',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Минимальный уровень для входа. 10 — новичок без накоплений не сливает старт на ставках; для <L10 показывается lock-кнопка с объяснением (UX-discoverability).',
                'effect_text'        => 'OracleAction для персонажа ниже уровня показывает lock-экран с порогом, ставки не принимаются.',
                'above_effect_text'  => 'При 30+ рынок доступен лишь поздней игре → мало участников, тонкий пул.',
                'below_effect_text'  => 'При 1 совсем новичок может слить стартовое золото на ставках → выжигает онбординг.',
                'recommended_min'    => '5',
                'recommended_max'    => '20',
                'hard_min'           => '1',
                'hard_max'           => '999',
            ],
            [
                'setting_key'        => 'oracle.reserve_floor',
                'value_type'         => 'int',
                'value_int'          => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'Золото-резерв, ниже которого ставку поставить нельзя (защита от слива всего золота под ноль). 0 — выключено в MVP; на будущее — буфер на налог/еду.',
                'effect_text'        => 'Ставка отклоняется, если после её списания золото упало бы ниже этого значения.',
                'above_effect_text'  => 'При 5000 игрок не может ставить, пока не накопит резерв сверх 5000 → защищает от разорения, но ограничивает.',
                'below_effect_text'  => 'При 0 защиты нет — можно поставить всё доступное золото (с учётом max_stake).',
                'recommended_min'    => '0',
                'recommended_max'    => '2000',
                'hard_min'           => '0',
                'hard_max'           => '1000000',
            ],
            [
                'setting_key'        => 'oracle.min_pool_to_settle',
                'value_type'         => 'int',
                'value_int'          => 500,
                'default_value_text' => '500',
                'rationale_text'     => 'Если общий банк рынка ниже этого значения (или пул односторонний / у победного исхода нет ставок) — рынок аннулируется и ставки возвращаются ПОЛНОСТЬЮ (без рейка). Защита от вялых недель при тонкой ликвидности (~147 игроков).',
                'effect_text'        => 'На расчёте: банк < это → void + полный возврат всем ставившим.',
                'above_effect_text'  => 'При 50000 почти каждый рынок аннулируется → механика «мёртвая» (BUILT-BUT-DEAD-риск).',
                'below_effect_text'  => 'При 0 даже рынок с одной ставкой «рассчитывается» → котировки бессмысленны, ощущение «сам против себя».',
                'recommended_min'    => '100',
                'recommended_max'    => '5000',
                'hard_min'           => '0',
                'hard_max'           => '10000000',
            ],
            [
                'setting_key'        => 'oracle.house_seed_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Разрешает дому подкладывать стартовую ликвидность в пул (последнее средство). false в MVP — иначе реинтродукция фонтан-риска. Включение требует дисклоуза в UI «банк дома».',
                'effect_text'        => 'true → дом может добавлять seed-ставку в тонкий пул. false → только золото игроков (чистый пари-мютюэль).',
                'above_effect_text'  => 'true (вкл): риск, что дом-вложения раздувают выплаты → потенциальный фонтан золота.',
                'below_effect_text'  => 'false (выкл): гарант-сток (Σвыплат ≤ Σставок игроков), но вялый рынок может аннулироваться чаще.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],

            // ── Расписание (недельный) ───────────────────────────────────
            [
                'setting_key'        => 'oracle.weekly_lock_day',
                'value_type'         => 'int',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => 'День недели закрытия ставок недельного рынка (1=Пн … 7=Вс, date(N)). 6=Суббота — ставки идут Пн–Сб, расчёт в Вс.',
                'effect_text'        => 'Недельный рынок переходит open→locked в этот день в час weekly_lock_hour.',
                'above_effect_text'  => 'Позже (7=Вс) → окно ставок длиннее, но ближе к расчёту = меньше времени на реакцию.',
                'below_effect_text'  => 'Раньше (1=Пн) → почти нет окна ставок.',
                'recommended_min'    => '4',
                'recommended_max'    => '7',
                'hard_min'           => '1',
                'hard_max'           => '7',
            ],
            [
                'setting_key'        => 'oracle.weekly_lock_hour',
                'value_type'         => 'int',
                'value_int'          => 21,
                'default_value_text' => '21',
                'rationale_text'     => 'Час (серверное время, 0–23) закрытия ставок недельного рынка.',
                'effect_text'        => 'В weekly_lock_day в этот час рынок переходит в locked.',
                'above_effect_text'  => 'Поздний час → ближе к расчёту.',
                'below_effect_text'  => 'Ранний час → короче окно ставок в день закрытия.',
                'hard_min'           => '0',
                'hard_max'           => '23',
            ],
            [
                'setting_key'        => 'oracle.weekly_settle_day',
                'value_type'         => 'int',
                'value_int'          => 7,
                'default_value_text' => '7',
                'rationale_text'     => 'День недели расчёта (снятия котировки) недельного рынка (1=Пн … 7=Вс). 7=Воскресенье.',
                'effect_text'        => 'Недельный рынок рассчитывается (locked→settled/void) в этот день в час weekly_settle_hour.',
                'above_effect_text'  => 'Должен быть ≥ weekly_lock_day (иначе расчёт раньше закрытия).',
                'below_effect_text'  => 'Раньше закрытия → логическая ошибка расписания.',
                'recommended_min'    => '6',
                'recommended_max'    => '7',
                'hard_min'           => '1',
                'hard_max'           => '7',
            ],
            [
                'setting_key'        => 'oracle.weekly_settle_hour',
                'value_type'         => 'int',
                'value_int'          => 23,
                'default_value_text' => '23',
                'rationale_text'     => 'Час (0–23) расчёта недельного рынка.',
                'effect_text'        => 'В weekly_settle_day в этот час недельный рынок рассчитывается и рассылает результаты.',
                'above_effect_text'  => 'Поздний час — ближе к концу дня.',
                'below_effect_text'  => 'Ранний час расчёта.',
                'hard_min'           => '0',
                'hard_max'           => '23',
            ],

            // ── Расписание (дневной) ─────────────────────────────────────
            [
                'setting_key'        => 'oracle.daily_lock_hour',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'Час (0–23) закрытия ставок дневного рынка «событие сегодня?». 20 — ставки идут до вечера.',
                'effect_text'        => 'Дневной рынок переходит open→locked в этот час.',
                'above_effect_text'  => 'Позже → длиннее окно ставок, но меньше времени до конца дня.',
                'below_effect_text'  => 'Раньше → короткое окно ставок.',
                'hard_min'           => '0',
                'hard_max'           => '23',
            ],
            [
                'setting_key'        => 'oracle.daily_settle_hour',
                'value_type'         => 'int',
                'value_int'          => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'Час СЛЕДУЮЩЕГО дня (0–23), когда рассчитывается дневной рынок (исход «было ли событие сегодня» известен по итогам прошедшего дня). 0 = сразу после полуночи.',
                'effect_text'        => 'Дневной рынок рассчитывается на следующий день в этот час.',
                'above_effect_text'  => 'Поздний час → результат приходит позже.',
                'below_effect_text'  => '0 — расчёт сразу после смены суток.',
                'hard_min'           => '0',
                'hard_max'           => '23',
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
            'oracle.enabled',
            'oracle.weekly_market_enabled',
            'oracle.daily_market_enabled',
            'oracle.rake_pct',
            'oracle.min_stake',
            'oracle.max_stake',
            'oracle.bet_options',
            'oracle.min_level',
            'oracle.reserve_floor',
            'oracle.min_pool_to_settle',
            'oracle.house_seed_enabled',
            'oracle.weekly_lock_day',
            'oracle.weekly_lock_hour',
            'oracle.weekly_settle_day',
            'oracle.weekly_settle_hour',
            'oracle.daily_lock_hour',
            'oracle.daily_settle_hour',
        ])->delete();
    }
}
