<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class ShopAction extends BaseAction
{
    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
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

        $text = "🏪 *Добро пожаловать в лавку странствующих торговцев!* 🏪\n\n"
            . "Здесь звучит монетный звон, и каждый предмет исписан историями далёких странствий. 🌍✨\n\n"
            . "Если ты не боишься рыночной суеты и готов выторговать себе достойную цену, тогда твои способности будут здесь оценены по достоинству. 🤝💰\n\n"
            . "Выбери, что ты хочешь сделать: приступить к торгам за редкие артефакты или продать найденные сокровища, чтобы наполнить свой кошель звонкой монетой? Время показать, на что ты способен! 🎩🔔\n\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 Продать ресы', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить ресы', 'callback_data' => 'buy']
                ],
                [
                    ['text' => '💰 Продать крафт', 'callback_data' => 'sellCraft'],
                    ['text' => '🛍️ Купить крафт', 'callback_data' => 'buyCraft']
                ],
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/vendor_kiosk_in_the_game_world.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

    }
}
