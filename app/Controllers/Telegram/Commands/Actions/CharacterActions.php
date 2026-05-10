<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class CharacterActions extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, ] = $this->getUserAndCharacter();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $this->navTarget()['chat_id'],
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
                    ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gather'],

                ],
                [
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ['text' => '📜 Выполнить квест', 'callback_data' => 'quest'],
                ]
            ]
        ];
        $encodedKeyboard = json_encode($keyboard);

        // Ответ на колбек-запрос для уведомления пользователя о приеме его запроса
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // #12 edit-in-place (ADR-018): меню действий персонажа — навигация → редактируем
        // текущее сообщение. editOrSend при любой ошибке edit упадёт обратно на новое.
        $imagePath = base_url('uploads/telegram/character_ready_to_act.png');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $encodedKeyboard,
        ]);
    }
}
