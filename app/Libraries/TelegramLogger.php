<?php

namespace App\Libraries;

use CodeIgniter\Log\Handlers\BaseHandler;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Telegram;

class TelegramLogger extends BaseHandler {
    protected $handles = [
        'critical', 'alert', 'emergency', 'debug', 'error', 'info', 'notice', 'warning',
    ];

    protected $telegramBotToken = 'YOUR_BOT_TOKEN';
    protected $chatId;

    public function __construct($config = null) {
        parent::__construct($config);

        $this->telegramBotToken = getenv('telegram.API_KEY');
        $this->chatId = getenv('telegram.MY_CHAT_ID');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($this->telegramBotToken, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function handle($level, $message): bool {
        if (in_array($level, $this->handles)) {
            // Сообщение для отправки в Telegram
            $text = strtoupper($level) . ": " . $message;

            try {
                Request::sendMessage(['chat_id' => $this->chatId, 'text' => $text]);
                return true;
            } catch (\Exception $e) {
                // Запасной план: запись в системный лог, если сообщение не может быть отправлено в Telegram
                log_message('error', 'TelegramLogger failed to send log: ' . $e->getMessage());
            }
        }

        return false;
    }
}
