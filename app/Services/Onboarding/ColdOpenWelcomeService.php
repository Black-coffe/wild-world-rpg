<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Services\Logging\ActionOrigin;

/**
 * Ядро первого экрана новичка (web-onboarding-01, ADR-190): упорядоченный список сообщений,
 * которые бот шлёт в ленту, а веб кладёт на экран. Рендерер (StartCommand / веб) только идёт
 * по модели — решений о тексте, порядке и кнопках он не принимает.
 *
 * Бот читает флаги `onboarding.cold_open_v2.*` ровно так, как раньше StartCommand: `start_greeting`
 * и `menu_defer` — через {@see ColdOpenGreetingService}, режим «одно окно» — из `$texts['singleScreen']`
 * (сервис пайка прочитал его до размещения приманки и встречающего).
 *
 * Веб: одно окно, одна кнопка «первый шаг», без носителя reply-меню и без бот-лексики.
 */
class ColdOpenWelcomeService
{
    public const CLIENT_BOT = 'bot';
    public const CLIENT_WEB = 'web';

    private const ERROR_TEXT = 'Извините, произошла ошибка при попытке определить локацию для спавна. Пожалуйста, попробуйте ещё раз.';

    private ColdOpenGreetingService $greeting;

    public function __construct(?ColdOpenGreetingService $greeting = null)
    {
        $this->greeting = $greeting ?? new ColdOpenGreetingService();
    }

    /**
     * @param array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string} $texts
     *        форма {@see \App\Services\Player\CharacterProvisioningService::lastTexts()}
     *
     * @return list<array{role: string, text: string, parse_mode: ?string, inline_keyboard: list<list<array{text: string, callback_data: string}>>}>
     */
    public function build(string $client, array $texts): array
    {
        return $client === self::CLIENT_WEB ? $this->buildWeb($texts) : $this->buildBot($texts);
    }

    /**
     * @param array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string} $texts
     *
     * @return list<array{role: string, text: string, parse_mode: ?string, inline_keyboard: list<list<array{text: string, callback_data: string}>>}>
     */
    private function buildBot(array $texts): array
    {
        $starterKit = $texts['starterKit'];
        $signal     = $texts['signal'];
        $greeter    = $texts['greeter'];

        // Ошибка спавна: текст по умолчанию, пустая клавиатура; пайки/сигнал/встречающий,
        // если они всё же есть, уходят перед ней, как и раньше.
        $text     = self::ERROR_TEXT;
        $keyboard = [];
        $role     = 'error';

        if ($texts['spawned']) {
            $role = 'welcome';

            if ($this->greeting->greetingEnabled()) {
                $text = $this->greeting->greeting();
            } else {
                $text = $this->legacyGreeting();
            }

            // ADR-168: метка `cold` — это первый экран (иначе вход неотличим от прочих мест,
            // где та же кнопка встречается позже).
            $keyboard = [
                [
                    ['text' => '🤔 Задать имя персонажа', 'callback_data' => ActionOrigin::tag('setCharacterName', ActionOrigin::FROM_COLDOPEN)],
                ],
            ];

            // «Одно окно вместо пяти»: паёк/сигнал/встречающий въезжают секциями в welcome.
            // Не влезло в одно сообщение → честный откат на раздельную отправку.
            if ($texts['singleScreen']) {
                $composed = $this->greeting->composeWelcome([
                    $this->greeting->greetingSingleScreen(),
                    $starterKit,
                    $signal,
                    $greeter,
                    $this->greeting->closingSingleScreen(),
                ]);

                if ($this->greeting->fitsSingleMessage($composed)) {
                    $text     = $composed;
                    $keyboard = [
                        [
                            ['text' => '🧭 Сделать первый шаг', 'callback_data' => ActionOrigin::tag('move', ActionOrigin::FROM_COLDOPEN)],
                        ],
                        [
                            ['text' => '✏️ Назвать героя', 'callback_data' => ActionOrigin::tag('setCharacterName', ActionOrigin::FROM_COLDOPEN)],
                        ],
                    ];

                    $starterKit = null;
                    $signal     = null;
                    $greeter    = null;
                }
            }
        }

        // N4 (ADR-039): постоянное меню шлётся только новичку; рендерер вешает на это сообщение reply-клавиатуру.
        $messages = [
            ['role' => 'menu_carrier', 'text' => $this->greeting->menuAttachText(), 'parse_mode' => null, 'inline_keyboard' => []],
        ];
        foreach (['kit' => $starterKit, 'signal' => $signal, 'greeter' => $greeter] as $kind => $section) {
            if ($section !== null) {
                $messages[] = ['role' => $kind, 'text' => $section, 'parse_mode' => 'Markdown', 'inline_keyboard' => []];
            }
        }
        $messages[] = ['role' => $role, 'text' => $text, 'parse_mode' => 'Markdown', 'inline_keyboard' => $keyboard];

        return $messages;
    }

    /**
     * @param array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string} $texts
     *
     * @return list<array{role: string, text: string, parse_mode: ?string, inline_keyboard: list<list<array{text: string, callback_data: string}>>}>
     */
    private function buildWeb(array $texts): array
    {
        if (! $texts['spawned']) {
            return [['role' => 'error', 'text' => self::ERROR_TEXT, 'parse_mode' => 'Markdown', 'inline_keyboard' => []]];
        }

        $keyboard = [
            [
                ['text' => '🧭 Сделать первый шаг', 'callback_data' => 'move'],
            ],
        ];

        // Пайка кладёт навигационный хвост по бот-флагу «одно окно»; веб — всегда одно окно.
        $texts['signal']  = self::withoutNavHint($texts['signal']);
        $texts['greeter'] = self::withoutNavHint($texts['greeter']);

        $composed = $this->greeting->composeWelcome([
            $this->greeting->greetingSingleScreen(),
            $texts['starterKit'],
            $texts['signal'],
            $texts['greeter'],
            $this->greeting->closingWeb(),
        ]);

        if ($this->greeting->fitsSingleMessage($composed)) {
            return [['role' => 'welcome', 'text' => $composed, 'parse_mode' => 'Markdown', 'inline_keyboard' => $keyboard]];
        }

        // Контент разросся: секции отдельными сообщениями, кнопка остаётся на последнем.
        $messages = [];
        foreach (['kit' => $texts['starterKit'], 'signal' => $texts['signal'], 'greeter' => $texts['greeter']] as $kind => $section) {
            if ($section !== null) {
                $messages[] = ['role' => $kind, 'text' => $section, 'parse_mode' => 'Markdown', 'inline_keyboard' => []];
            }
        }
        $messages[] = [
            'role'            => 'welcome',
            'text'            => $this->greeting->composeWelcome([$this->greeting->greetingSingleScreen(), $this->greeting->closingWeb()]),
            'parse_mode'      => 'Markdown',
            'inline_keyboard' => $keyboard,
        ];

        return $messages;
    }

    /**
     * Убирает бот-хвост «_Жми «…» внизу экрана …_» (ColdOpenSignalService / NewbieGreeterService,
     * `withNavHint`): в вебе нижнего меню-клавиатуры нет.
     */
    private static function withoutNavHint(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $stripped = preg_replace('/\n*_Жми «[^»\n]*» внизу экрана[^\n]*_\s*$/u', '', $text);

        return is_string($stripped) ? $stripped : $text;
    }

    /** Легаси-приветствие (121 слово), пока `start_greeting` выключен. Байт в байт как в StartCommand до переноса. */
    private function legacyGreeting(): string
    {
        return "🤖 *Wild World — выживание на острове после глобальной катастрофы.* 🌍\n\n"
            . "Меня зовут *Роби*, я — твой проводник. Мы оказались на остатке земли, где всё рухнуло: цивилизация ушла, выжившие сами разбираются, как жить дальше.\n\n"
            . "Что тебя ждёт:\n"
            . "• 9 биомов — от спокойных полей до вулканических земель и подземелий\n"
            . "• Сбор ресурсов, крафт, постройка лагеря — всё из подручного хлама\n"
            . "• События мира: погода, болезни, рейдеры, редкие находки\n"
            . "• На lvl 10 — выбор одной из 4 фракций. У каждой свой путь: доминирование, анархия, научный прорыв или мирное возрождение.\n\n"
            . "Не все выжившие дружелюбны — продумывай, как защитить себя и базу.\n\n"
            . "Сейчас покажу несколько подсказок, чтобы ты не блуждал в первый час. Можно прервать в любой момент — но советую дойти до конца.\n\n"
            . "Вопросы и стратегии обсуждаем в [общем чате](https://t.me/wild_world_info).\n\n"
            . "Придумай герою имя и поехали.";
    }
}
