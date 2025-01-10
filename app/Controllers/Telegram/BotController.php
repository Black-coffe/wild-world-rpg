<?php

namespace App\Controllers\Telegram;

use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;

class BotController extends Controller
{
    private $telegram;

    public function __construct()
    {
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Регистрация команд
            $this->telegram->addCommandsPath(__DIR__ . '/Commands');

        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Обрабатывает входящие обновления от Telegram.
     */
    public function webhook()
    {
        try {
            $this->telegram->handle();
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function sendMessage($chatId, $message, $imagePath = null, $keyboard = null)
    {
        if ($imagePath) {
            // Если передан путь к изображению, отправляем фото
            $data = [
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $message,
                'parse_mode' => 'Markdown'
            ];
            if ($keyboard) {
                $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
            }
            return Request::sendPhoto($data);
        } else {
            // Если изображение не передано, отправляем обычное текстовое сообщение
            $data = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true
            ];
            if ($keyboard) {
                $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
            }
            return Request::sendMessage($data);
        }
    }

}
