<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Services\Notifications\MediaSender;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class SetCharacterNameAction extends BaseAction
{
    protected $characterModel;
    protected $exploredCellsModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $characterResourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Формируем сообщение
        $text = "🤖 Это я – *Роби*, тебе помочь с твоим никнеймом?\n\n"
            . "✨ _Сейчас тебе нужно выбрать имя, под которым ты станешь известен всем на этом острове._\n"
            . "_Имя — это не просто символ, а твой путь к влиянию и славе. Оно определит тебя среди выживших и станет знаком силы, выносливости и ума!_\n\n"
            . "📜 *Правила выбора имени:*\n\n"
            . "🆔 Длина: от 3 до 20 символов.\n"
            . "🔤 Разрешены: буквы любого языка (кириллица, латиница и др.), цифры (0–9) и знак подчёркивания (\_).\n"
            . "🚫 Запрещены: пробелы, эмодзи, специальные символы и нецензурная лексика.\n\n"
            . "🤖 Я буду рядом, чтобы помочь ответами на вопросы, а пока введи своё будущее имя и готовься к путешествию! 🚀\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 Задать имя', 'callback_data' => 'setName'],
                    ['text' => '⚙️ Автогенерация имени', 'callback_data' => 'autoGenerateName'],
                ],
            ]
        ];

        // Слайс «Первые 3 минуты» (замер 2026-07-24), шаг 2: экран регламента → одна строка.
        //
        // Замер: из 45 новичков когорты 18 нажали «Задать имя», но до ввода дошли 13 —
        // регламент на восемь строк съедал 28% уже согласившихся. Правила при этом нужны
        // (валидатор отклонит пробелы/эмодзи), поэтому не выбрасываем их, а сворачиваем в
        // одну фразу и добавляем честный выход в мир: имя — не ворота.
        //
        // Имя уже есть (автоподстановка из username при создании персонажа) → показываем
        // текущее. MarkdownSafe обязателен: реальные ники вида `mr_razrab` содержат `_`,
        // непарный символ валит парсинг ВСЕГО сообщения → 400 → тихий не-сенд.
        if ((new \App\Services\Onboarding\ColdOpenGreetingService())->singleScreenEnabled()) {
            $currentName = \App\Services\Display\MarkdownSafe::name(
                is_string($character['name'] ?? null) ? $character['name'] : ''
            );

            $text = "✏️ *Имя героя*\n\n"
                . "Сейчас тебя зовут *{$currentName}*. Можно оставить так — или придумать своё.\n\n"
                . "Требования простые: 3–20 символов, без пробелов и эмодзи.";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📝 Ввести своё', 'callback_data' => 'setName'],
                        ['text' => '🎲 Придумай за меня', 'callback_data' => 'autoGenerateName'],
                    ],
                    [
                        ['text' => '🧭 Оставить и в мир', 'callback_data' => 'move'],
                    ],
                ]
            ];
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): экран выбора имени — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке/клике с photo).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode($keyboard),
        ]);

    }
}
