<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

/**
 * ADR-103 Часть B Слой 2 — каталог обучающей квест-цепочки «Первые шаги выжившего».
 *
 * ЕДИНЫЙ источник истины для шагов цепочки: его читают
 *   • seed-миграция (создаёт quests с этими title_en / objective_type / prerequisite);
 *   • {@see OnboardingChainService} (назначение первого шага, completion-guidance);
 * → нет дрейфа между БД и кодом.
 *
 * Этапы (по росту новичка, ADR-103): двигаться → собрать ресурс → скрафтить →
 * захватить базу → ОТКРЫТЬ базу → построить первую постройку → продать излишки →
 * достичь 5 уровня (E3 ROADMAP-100: +2 шага поверх исходных шести). Шаги связаны линейно
 * (prerequisite = title_en предыдущего); первый назначается явно, остальные —
 * авто-advance движком цепочек (QuestChainService::advanceChain).
 *
 * `done_text` — обучающая подсказка, которую игрок получает ПОСЛЕ завершения шага:
 * ведёт к следующему конкретному действию (just-in-time, конституц. правило
 * ONBOARDING-COVERAGE). Самодостаточна в тексте (media-off инвариант).
 *
 * Объектив-типы шагов (все onboarding-only, гейтятся onboarding.quest_chain.enabled):
 *   explore_cells (always-on) / collect_resource / level_milestone (ADR-088) /
 *   craft_any / claim_base / open_base_screen / any_building / sell_any —
 *   onboarding-специфичные добавлены в
 *   {@see \App\TaskHandlers\Quests\QuestObjectiveHandler::objectiveMet()};
 *   sell_any опирается на форензик-лог продаж v0.51.389 (SELL_RESOURCE/BULK_SELL
 *   в action_log).
 */
class OnboardingChainCatalog
{
    /** Префикс title_en всех квестов цепочки — по нему handler опознаёт онбординг-шаги. */
    public const PREFIX = 'OnbStep';

    public const STEP_MOVE       = 'OnbStepMove';
    public const STEP_GATHER     = 'OnbStepGather';
    public const STEP_CRAFT      = 'OnbStepCraft';
    public const STEP_CLAIM_BASE = 'OnbStepClaimBase';
    public const STEP_OPEN_BASE  = 'OnbStepOpenBase';
    public const STEP_BUILD      = 'OnbStepBuild';
    public const STEP_SELL       = 'OnbStepSell';
    public const STEP_LEVEL5     = 'OnbStepLevel5';

    /** title_en первого шага цепочки (его назначает OnboardingChainService). */
    public const ROOT = self::STEP_MOVE;

    /**
     * Упорядоченные шаги цепочки. prerequisite выводится из порядка (предыдущий title_en).
     *
     * @return list<array{
     *   title_en:string, title_ru:string, objective_type:string,
     *   objective_target:?string, objective_qty:int, reward:int,
     *   description:string, done_text:string
     * }>
     */
    public static function steps(): array
    {
        return [
            [
                'title_en'         => self::STEP_MOVE,
                'title_ru'         => 'Первая вылазка',
                'objective_type'   => 'explore_cells',
                'objective_target' => null,
                'objective_qty'    => 3,
                'reward'           => 150,
                'description'      => 'Пустошь не изучить, стоя на месте. Открой движение (кнопка «Двигаться») и жми стрелку направления — переместись на 3 новые клетки. Для дальних переходов есть «Поход». Каждый шаг открывает кусочек карты вокруг.',
                'done_text'        => "🧭 *Ты освоил движение!* Карта вокруг приоткрылась.\n\n"
                    . "Дальше — *добыча*. Встань на клетку и жми кнопки сбора ресурсов: вода попадается чаще всего, с неё и начни.\n\n"
                    . "_Следующий шаг цепочки уже в «Активных квестах»._",
            ],
            [
                'title_en'         => self::STEP_GATHER,
                'title_ru'         => 'Дары пустоши',
                'objective_type'   => 'collect_resource',
                'objective_target' => 'Water',
                'objective_qty'    => 5,
                'reward'           => 150,
                'description'      => 'Без ресурсов не выжить. Собери 5 единиц воды — самый частый и нужный ресурс. Жми кнопки добычи на клетке, где есть вода.',
                'done_text'        => "💧 *Первый ресурс в кармане!*\n\n"
                    . "Теперь — *крафт*. Напиши «крафт» (или жми *«Крафт»* в нижнем меню) и собери любой простой предмет: например, *Повязку* или *Факел*.\n\n"
                    . "_Мастерская для базовых вещей не нужна — крафт-хаб открывается сразу._",
            ],
            [
                'title_en'         => self::STEP_CRAFT,
                'title_ru'         => 'Руки помнят',
                'objective_type'   => 'craft_any',
                'objective_target' => null,
                'objective_qty'    => 1,
                'reward'           => 150,
                'description'      => 'Выживший умеет делать вещи из хлама. Скрафти любой предмет — открой «Крафт» в нижнем меню и собери что-нибудь простое.',
                'done_text'        => "🛠 *Первый крафт готов!*\n\n"
                    . "Пора обзавестись *своей базой*. Найди подходящую клетку, открой *«База»* в нижнем меню и нажми *«🏕 Разбить лагерь»*.\n\n"
                    . "_База — это твой дом: на ней строятся Склад, Теплица и оборона._",
            ],
            [
                'title_en'         => self::STEP_CLAIM_BASE,
                'title_ru'         => 'Свой угол',
                'objective_type'   => 'claim_base',
                'objective_target' => null,
                'objective_qty'    => 1,
                'reward'           => 200,
                'description'      => 'Дом — половина выживания. Разбей свой лагерь: открой «База» в нижнем меню и нажми «Разбить лагерь» на подходящей клетке.',
                'done_text'        => "🏕 *База твоя!*\n\n"
                    . "Теперь самое важное — *открой её*. Нажми кнопку *«База»* в нижнем меню: увидишь свои постройки, оборону и кнопку строительства.\n\n"
                    . "_База открывается кнопкой «База», а не просто стоянием на клетке._",
            ],
            [
                'title_en'         => self::STEP_OPEN_BASE,
                'title_ru'         => 'Дом, милый дом',
                'objective_type'   => 'open_base_screen',
                'objective_target' => null,
                'objective_qty'    => 1,
                'reward'           => 150,
                'description'      => 'Открой экран своей базы — нажми кнопку «База» в нижнем меню. Там живут все твои постройки и оборона; отсюда же строятся новые.',
                'done_text'        => "🏠 *Ты на базе!*\n\n"
                    . "Последний шаг — *построй первую постройку*. На экране базы нажми *«🏗 Строить»* и выбери, что возвести (Склад, Теплицу и др.).\n\n"
                    . "_Постройки ставятся только тут, на базе — в магазине их нет._",
            ],
            [
                'title_en'         => self::STEP_BUILD,
                'title_ru'         => 'Первый камень',
                'objective_type'   => 'any_building',
                'objective_target' => null,
                'objective_qty'    => 1,
                'reward'           => 300,
                'description'      => 'База без построек — просто клочок земли. Построй первую постройку: на экране базы нажми «Строить» и выбери здание.',
                'done_text'        => "🏗 *Первая постройка стоит!*\n\n"
                    . "Теперь — *экономика*. Излишки ресурсов превращаются в золото: открой *«Перс» → «🛒 Магазин» → «💰 Продать ресы»* и продай что-нибудь.\n\n"
                    . "_Золото уходит на постройки, ремонт и налоги — лишним не будет._",
            ],
            [
                'title_en'         => self::STEP_SELL,
                'title_ru'         => 'Первая сделка',
                'objective_type'   => 'sell_any',
                'objective_target' => null,
                'objective_qty'    => 1,
                'reward'           => 200,
                'description'      => 'Излишки — это золото. Продай любые ресурсы: «Перс» → «Магазин» → «Продать ресы». Золото пригодится на постройки, ремонт и налоги.',
                'done_text'        => "💰 *Первое золото заработано!*\n\n"
                    . "Остался последний рывок: *дорасти до 5 уровня*. Уровень растёт сам — от разведки, добычи, крафта и заданий.\n\n"
                    . "_Продолжай выживать — следующий шаг уже в «Активных квестах»._",
            ],
            [
                'title_en'         => self::STEP_LEVEL5,
                'title_ru'         => 'Закалённый новичок',
                'objective_type'   => 'level_milestone',
                'objective_target' => null,
                'objective_qty'    => 5,
                'reward'           => 500,
                'description'      => 'Покажи пустоши, что ты не случайный гость: достигни 5 уровня. Опыт капает за разведку, добычу, крафт и выполнение заданий.',
                'done_text'        => "🏁 *Поздравляю — «Первые шаги выжившего» пройдены полностью!*\n\n"
                    . "Ты умеешь двигаться, добывать, крафтить, строить и торговать. Дальше пустошь откроет: *еду и ферму*, *бой с тварями*, поселения NPC, а на 10 уровне — *выбор фракции*.\n\n"
                    . "_В «Квестах» уже ждут новые цели. Удачи на пустоши, выживший._",
            ],
        ];
    }

    /**
     * Completion-guidance шага по его title_en (текст «что делать дальше» + клавиатура).
     * null — если title_en не принадлежит онбординг-цепочке.
     *
     * @return array{text:string, reply_markup:string}|null
     */
    public static function completion(string $titleEn): ?array
    {
        foreach (self::steps() as $step) {
            if ($step['title_en'] === $titleEn) {
                return [
                    'text'         => $step['done_text'],
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[
                            ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
                            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        }

        return null;
    }

    /** Является ли квест (по title_en) шагом онбординг-цепочки. */
    public static function isChainQuest(string $titleEn): bool
    {
        return str_starts_with($titleEn, self::PREFIX);
    }

    /**
     * Шаг цепочки по его title_en (или null). Нужен авто-эскалации (E4): по застрявшему
     * шагу достаём `description` текущей задачи, чтобы пере-подсказать «что делать сейчас».
     *
     * @return array{
     *   title_en:string, title_ru:string, objective_type:string,
     *   objective_target:?string, objective_qty:int, reward:int,
     *   description:string, done_text:string
     * }|null
     */
    public static function find(string $titleEn): ?array
    {
        foreach (self::steps() as $step) {
            if ($step['title_en'] === $titleEn) {
                return $step;
            }
        }

        return null;
    }
}
