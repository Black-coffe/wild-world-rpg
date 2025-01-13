<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
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
            . "🔤 Разрешены: латинские буквы (A–Z, a–z), цифры (0–9) и знак подчёркивания (\_).\n"
            . "🚫 Запрещены: кириллические *(русские, украинские)* буквы, а также пробелы, специальные символы и нецензурная лексика.\n\n"
            . "🤖 Я буду рядом, чтобы помочь ответами на вопросы, а пока введи своё будущее имя и готовься к путешествию! 🚀\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 Задать имя', 'callback_data' => 'setName'],
                    ['text' => '⚙️ Автогенерация имени', 'callback_data' => 'autoGenerateName'],
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode($keyboard),
        ]);

    }
}
