<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\TelegramUserModel;


class CharacterGoActions
{
    protected $callbackQuery;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
    }


    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Шаг 1: Определение ID телеграм пользователя
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // Шаг 2: Поиск пользователя в базе
        $telegramUserModel = new TelegramUserModel();
        $user = $telegramUserModel->where('telegram_id', $telegramUserId)->first();

        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных.',
            ]);
        }


        $text = "*Привет, герой! 🙋‍♂️* 👋\n\n"
            . "**Скучно сидеть сложа руки? Не беда!** 🥱\n\n"
            . "**Мы найдем работу для самых трудолюбивых героев!** 💪\n\n"
            . "**Выбирай, чем хочешь заняться сегодня:**\n\n"
            . "**Пусть тебе сопутствует удача!** 🍀\n\n"
            . "**P.S.** Делись своими достижениями в чате! 🗣️\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🚜 Переехать', 'callback_data' => 'move'],

                ],
                [
                    ['text' => '🏕️ Окопаться', 'callback_data' => 'entrench'],
                    ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
                ],
                [
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gather'],
                ]
            ]
        ];
        $encodedKeyboard = json_encode($keyboard);

        // Ответ на колбек-запрос для уведомления пользователя о приеме его запроса
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);


        // Шаг 5: Формирование и отправка сообщения с результатами
        $imagePath = base_url('uploads/telegram/character_ready_to_act.png'); // Укажите актуальный путь к изображению
        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => $encodedKeyboard
        ]);

    }
}
