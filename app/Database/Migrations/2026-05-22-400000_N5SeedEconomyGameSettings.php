<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * N5 (ADR-040) — 20 economy.* GameSettings (constitutional admin-tunable, ADR-024).
 *
 * Выносит хардкод золото-порогов магазина и весь баланс 3 мини-игр (Угадай-число,
 * Колесо фортуны, Камень-ножницы-бумага) в live-tunable admin-фреймворк. Параллельно
 * мини-игры переписаны на ЧЕСТНЫЕ механики (см. action-handler'ы): Угадай — реальный
 * 1-из-N (шанс 1/options_count), КНБ — честные 33/33/33 (скрытый 40%-cap убран),
 * азартные игры больше не трогают навыки. Caption'ы рендерят значения отсюда динамически
 * → текст не может разойтись с механикой. Каждый ключ с rich rationale (why/effect/
 * above/below) — invariant ADR-024. category='economy' (новая). Idempotent.
 */
class N5SeedEconomyGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            // ── Магазин: золото-пороги входа ──────────────────────────────
            [
                'setting_key'        => 'economy.shop.buy_craft_min_gold',
                'value_type'         => 'int',
                'value_int'          => 1000,
                'default_value_text' => '1000',
                'rationale_text'     => 'Минимум золота, чтобы открыть покупку крафт-предметов (BuyCraftAction). 1000 — крафт-предметы дорогие, порог отсекает новичков без накоплений и держит крафт-рынок поздней игрой.',
                'effect_text'        => 'BuyCraftAction отклоняет вход в покупку, если gold < этого значения, с текстом порога (динамическим).',
                'above_effect_text'  => 'При 10000+ покупка крафта доступна лишь верхушке экономики → рынок крафта почти мёртв.',
                'below_effect_text'  => 'При 0 любой новичок скупает крафт за золото минуя производство → обесценивает крафт-цепочки.',
                'recommended_min'    => '100',
                'recommended_max'    => '5000',
                'hard_min'           => '0',
                'hard_max'           => '1000000',
            ],
            [
                'setting_key'        => 'economy.shop.buy_resource_min_gold',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Минимум золота, чтобы открыть покупку ресурсов (BuyResourceAction). 10 — низкий барьер: покупка ресурсов это базовый ранний sink золота, должна быть доступна почти сразу.',
                'effect_text'        => 'BuyResourceAction отклоняет вход в покупку ресурсов, если gold < этого значения.',
                'above_effect_text'  => 'При 1000+ ранняя покупка ресурсов недоступна → новичок лишён базового золото-sink.',
                'below_effect_text'  => 'При 0 порога нет — доступно с нулём золота (фактически открывает экран без смысла, т.к. покупка всё равно требует золота).',
                'recommended_min'    => '0',
                'recommended_max'    => '500',
                'hard_min'           => '0',
                'hard_max'           => '1000000',
            ],

            // ── Угадай число (честный 1-из-N) ─────────────────────────────
            [
                'setting_key'        => 'economy.guess.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch мини-игры «Угадай число». true — игра доступна из меню Развлечения.',
                'effect_text'        => 'false → GuessNumberAction отвечает «игра временно недоступна», ставки не принимаются.',
                'above_effect_text'  => 'true (вкл): азартная мини-игра на золото доступна.',
                'below_effect_text'  => 'false (выкл): мгновенный откат игры без редеплоя (например при обнаружении эксплойта).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.guess.min_gold_to_play',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Минимум золота для входа в игру. 50 = максимальной ставке — гарантирует, что любая выбранная ставка по карману (плюс per-bet проверка в коде). Чинит прежнюю несостыковку «гейт 50 при минимальной ставке 5».',
                'effect_text'        => 'GuessNumberAction отклоняет вход, если gold < этого значения. Дополнительно код не даёт поставить больше, чем есть.',
                'above_effect_text'  => 'При 1000+ игра доступна лишь богатым → ранний игрок не может развлечься.',
                'below_effect_text'  => 'При 0 можно зайти с нулём (но ставку всё равно надо иметь — per-bet check).',
                'recommended_min'    => '5',
                'recommended_max'    => '500',
                'hard_min'           => '0',
                'hard_max'           => '100000',
            ],
            [
                'setting_key'        => 'economy.guess.options_count',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Сколько чисел показать игроку = ЧЕСТНЫЙ шанс выигрыша 1/N. 3 → 33.3% (как и обещает текст «1 из 3»). Шанс честен by-construction: компьютер реально загадывает одно из N, соврать о вероятности невозможно.',
                'effect_text'        => 'GuessNumberAction показывает N кнопок-чисел; секрет = rand(1,N); победа если выбор совпал. P(win)=1/N. Caption печатает реальный 1/N и %.',
                'above_effect_text'  => 'При 6+ шанс падает до ≤16.7% → игра жёстче, дом-эдж растёт даже при том же payout.',
                'below_effect_text'  => 'При 2 шанс 50% → почти безубыточно при payout≥2, золото-sink исчезает.',
                'recommended_min'    => '2',
                'recommended_max'    => '6',
                'hard_min'           => '2',
                'hard_max'           => '12',
            ],
            [
                'setting_key'        => 'economy.guess.payout_multiplier',
                'value_type'         => 'float',
                'value_float'        => 1.5,
                'default_value_text' => '1.5',
                'rationale_text'     => 'Выигрыш = ставка × это (ставка при победе не списывается). Единственный источник дом-эджа при честном 1/3. 1.5 → EV = 0.33×1.5−0.67 = −0.17 (умеренный честный азарт: игрок иногда в плюсе, казино выигрывает в длину).',
                'effect_text'        => 'GuessNumberAction при победе начисляет round(ставка × это) золота; при проигрыше списывает ставку.',
                'above_effect_text'  => 'При 2.0+ (с N=3) EV≥0 → можно фармить золото на везении, sink исчезает.',
                'below_effect_text'  => 'При 1.2 (с N=3) EV=−0.6 → жёсткий sink, игроку почти всегда больно.',
                'recommended_min'    => '1.2',
                'recommended_max'    => '2.5',
                'hard_min'           => '1.0',
                'hard_max'           => '10.0',
            ],
            [
                'setting_key'        => 'economy.guess.bet_options',
                'value_type'         => 'string',
                'value_string'       => '5,10,25,50',
                'default_value_text' => '5,10,25,50',
                'rationale_text'     => 'CSV вариантов ставок (кнопки). 5,10,25,50 — лесенка от мелкой пробы до крупного риска. Максимум должен быть ≤ min_gold_to_play (иначе ставка недоступна по гейту).',
                'effect_text'        => 'GuessNumberAction строит кнопки ставок из этого списка (целые числа через запятую).',
                'above_effect_text'  => 'Слишком крупные ставки (например 5000) → разовый риск огромных потерь золота.',
                'below_effect_text'  => 'Только мелкие ставки → игра ощущается несерьёзной, слабый sink.',
                'hard_min'           => null,
                'hard_max'           => null,
            ],

            // ── Колесо фортуны ────────────────────────────────────────────
            [
                'setting_key'        => 'economy.wheel.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch мини-игры «Колесо фортуны».',
                'effect_text'        => 'false → FortuneWheelAction отвечает «игра временно недоступна».',
                'above_effect_text'  => 'true (вкл): азартная игра на золото с наградой ресурсами доступна.',
                'below_effect_text'  => 'false (выкл): мгновенный откат без редеплоя.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.wheel.win_chance.bet_1',
                'value_type'         => 'float',
                'value_float'        => 0.50,
                'default_value_text' => '0.50',
                'rationale_text'     => 'Шанс (0..1) выигрыша при ставке 1 монета (массовые ресурсы). 0.50 — самая дешёвая ставка, частая награда массовым (низкоредким) ресурсом. Раскрыт в caption.',
                'effect_text'        => 'FortuneWheelAction: победа при rand(0,99) < это×100. Caption печатает реальный %.',
                'above_effect_text'  => 'При 1.0 ставка 1 всегда выигрывает → бесконечный источник массового ресурса за 0 (золото на победе не списывается).',
                'below_effect_text'  => 'При 0 ставка 1 всегда проигрывает → дешёвая ставка бессмысленна.',
                'recommended_min'    => '0.30',
                'recommended_max'    => '0.70',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.wheel.win_chance.bet_5',
                'value_type'         => 'float',
                'value_float'        => 0.35,
                'default_value_text' => '0.35',
                'rationale_text'     => 'Шанс выигрыша при ставке 5 монет (частые ресурсы средней редкости). 0.35 — выше награда, ниже шанс.',
                'effect_text'        => 'FortuneWheelAction: победа при rand(0,99) < это×100.',
                'above_effect_text'  => 'При 0.7+ средне-редкие ресурсы сыплются слишком легко → инфляция.',
                'below_effect_text'  => 'При 0.05 ставка 5 почти всегда проигрыш → игроки её избегают.',
                'recommended_min'    => '0.20',
                'recommended_max'    => '0.50',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.wheel.win_chance.bet_10',
                'value_type'         => 'float',
                'value_float'        => 0.15,
                'default_value_text' => '0.15',
                'rationale_text'     => 'Шанс выигрыша при ставке 10 монет (редкие ресурсы). 0.15 — редкая награда требует риска.',
                'effect_text'        => 'FortuneWheelAction: победа при rand(0,99) < это×100.',
                'above_effect_text'  => 'При 0.5+ редкие ресурсы становятся обыденными → ломает редкость-экономику.',
                'below_effect_text'  => 'При 0.02 ставка 10 — почти гарантированный слив золота.',
                'recommended_min'    => '0.08',
                'recommended_max'    => '0.30',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.wheel.win_chance.bet_50',
                'value_type'         => 'float',
                'value_float'        => 0.07,
                'default_value_text' => '0.07',
                'rationale_text'     => 'Шанс выигрыша при ставке 50 монет (уникальные ресурсы редкости 2). 0.07 — джекпот-ставка: редчайшая награда, высокий риск.',
                'effect_text'        => 'FortuneWheelAction: победа при rand(0,99) < это×100.',
                'above_effect_text'  => 'При 0.3+ уникальные ресурсы добываются рулеткой регулярно → обесценивает топ-лут.',
                'below_effect_text'  => 'При 0.01 ставка 50 — почти чистый слив 50 золота, мало кто рискнёт.',
                'recommended_min'    => '0.03',
                'recommended_max'    => '0.20',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.wheel.quantity.bet_1',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'Сколько ресурса выдаётся при выигрыше ставки 1. 1 — массовый ресурс, мелкая дешёвая награда.',
                'effect_text'        => 'FortuneWheelAction начисляет это число выпавшего ресурса. Caption печатает «×это».',
                'above_effect_text'  => 'При 50+ дешёвая ставка заваливает ресурсом → инфляция массового.',
                'below_effect_text'  => 'При 1 — минимум (нельзя ниже по hard_min).',
                'recommended_min'    => '1',
                'recommended_max'    => '10',
                'hard_min'           => '1',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'economy.wheel.quantity.bet_5',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Сколько ресурса при выигрыше ставки 5. 3 — частые ресурсы.',
                'effect_text'        => 'FortuneWheelAction начисляет это число. Caption печатает «×это».',
                'above_effect_text'  => 'При 50+ инфляция средне-редких ресурсов.',
                'below_effect_text'  => 'При 1 награда не оправдывает ставку 5.',
                'recommended_min'    => '1',
                'recommended_max'    => '15',
                'hard_min'           => '1',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'economy.wheel.quantity.bet_10',
                'value_type'         => 'int',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => 'Сколько ресурса при выигрыше ставки 10. 6 — узаконенное фактическое значение (код давал 6; прежний текст врал «x5»). Без stealth-нерфа: игроки уже получают 6, caption теперь честно показывает 6.',
                'effect_text'        => 'FortuneWheelAction начисляет это число редкого ресурса. Caption печатает «×это».',
                'above_effect_text'  => 'При 50+ редкие ресурсы сыплются пачками → ломает редкость.',
                'below_effect_text'  => 'При 1 редкая ставка 10 невыгодна.',
                'recommended_min'    => '1',
                'recommended_max'    => '20',
                'hard_min'           => '1',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'economy.wheel.quantity.bet_50',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Сколько ресурса при выигрыше ставки 50. 10 — узаконенное фактическое значение (код давал 10; прежний текст врал «x8»). Без stealth-нерфа: caption теперь честно показывает 10.',
                'effect_text'        => 'FortuneWheelAction начисляет это число уникального ресурса. Caption печатает «×это».',
                'above_effect_text'  => 'При 50+ джекпот заваливает топ-ресурсом → обесценивает.',
                'below_effect_text'  => 'При 1 джекпот-ставка 50 не оправдывает риск.',
                'recommended_min'    => '1',
                'recommended_max'    => '25',
                'hard_min'           => '1',
                'hard_max'           => '100',
            ],

            // ── Камень-ножницы-бумага (честный 33/33/33) ──────────────────
            [
                'setting_key'        => 'economy.rps.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch мини-игры «Камень-ножницы-бумага».',
                'effect_text'        => 'false → RockPaperScissorsAction отвечает «игра временно недоступна».',
                'above_effect_text'  => 'true (вкл): честная игра на навык доступна.',
                'below_effect_text'  => 'false (выкл): мгновенный откат без редеплоя.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.rps.min_skill_to_play',
                'value_type'         => 'float',
                'value_float'        => 0.05,
                'default_value_text' => '0.05',
                'rationale_text'     => 'Минимум каждого из 4 навыков (опыт/сила/ловкость/интеллект), чтобы войти. 0.05 — крошечный порог: защита от игры в минус с нулевыми навыками (навык = ставка КНБ).',
                'effect_text'        => 'RockPaperScissorsAction отклоняет вход, если любой из 4 навыков < этого значения.',
                'above_effect_text'  => 'При 5+ ранний игрок с малыми навыками не допущен → игра только для прокачанных.',
                'below_effect_text'  => 'При 0 можно играть с нулевыми навыками (терять нечего — max(0)).',
                'recommended_min'    => '0',
                'recommended_max'    => '1',
                'hard_min'           => '0',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'economy.rps.win_gain',
                'value_type'         => 'float',
                'value_float'        => 0.01,
                'default_value_text' => '0.01',
                'rationale_text'     => 'Сколько навыка (выбранного игроком) прибавляется при ЧЕСТНОЙ победе (33/33/33). 0.01 — мелкий прирост; КНБ это азарт, а не основной канал прокачки.',
                'effect_text'        => 'RockPaperScissorsAction при победе добавляет это к выбранному навыку. Раскрыто в тексте.',
                'above_effect_text'  => 'При 0.5+ КНБ становится быстрым качем навыков → обходит основную прогрессию.',
                'below_effect_text'  => 'При 0 победа ничего не даёт → нет смысла играть.',
                'recommended_min'    => '0.005',
                'recommended_max'    => '0.05',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'economy.rps.loss_penalty',
                'value_type'         => 'float',
                'value_float'        => 0.02,
                'default_value_text' => '0.02',
                'rationale_text'     => 'Сколько навыка теряется при поражении. 0.02 > win_gain 0.01 — АСИММЕТРИЯ создаёт честный sink (EV/игру = (0.01−0.02)/3 = −0.0033 навыка). Заменяет прежний скрытый 40%-cap прозрачным механизмом.',
                'effect_text'        => 'RockPaperScissorsAction при поражении вычитает это из выбранного навыка (max 0). Ничья — без изменений. Раскрыто в тексте.',
                'above_effect_text'  => 'При 0.5+ один проигрыш сжигает много навыка → игра слишком наказывает, отпугивает.',
                'below_effect_text'  => 'При значении ≤ win_gain sink исчезает (EV≥0) → КНБ становится бесплатным качем.',
                'recommended_min'    => '0.01',
                'recommended_max'    => '0.05',
                'hard_min'           => '0',
                'hard_max'           => '1',
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
            'economy.shop.buy_craft_min_gold',
            'economy.shop.buy_resource_min_gold',
            'economy.guess.enabled',
            'economy.guess.min_gold_to_play',
            'economy.guess.options_count',
            'economy.guess.payout_multiplier',
            'economy.guess.bet_options',
            'economy.wheel.enabled',
            'economy.wheel.win_chance.bet_1',
            'economy.wheel.win_chance.bet_5',
            'economy.wheel.win_chance.bet_10',
            'economy.wheel.win_chance.bet_50',
            'economy.wheel.quantity.bet_1',
            'economy.wheel.quantity.bet_5',
            'economy.wheel.quantity.bet_10',
            'economy.wheel.quantity.bet_50',
            'economy.rps.enabled',
            'economy.rps.min_skill_to_play',
            'economy.rps.win_gain',
            'economy.rps.loss_penalty',
        ])->delete();
    }
}
