<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class EntertaimentAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $text = "🎮 *Развлечения*\n\n"
            . "Перед вами мир азарта, везения и разочарования. \nВо что желаете поиграть сегодня❓\n\n"
            . "Выберите тип игры, чтобы развеяться и приятно провести время 👇\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛞 Колесо фортуны', 'callback_data' => 'WheelOfFortune'],
                    ['text' => '🎲 Угадай число', 'callback_data' => 'GuessNumber']
                ],
                [
                    ['text' => '✂️ Камень-ножницы-бумага', 'callback_data' => 'RockPaperScissors'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/fun_games.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): меню развлечений — навигация → редактируем
        // текущее сообщение. editOrSend при любой ошибке edit упадёт обратно на новое.
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
