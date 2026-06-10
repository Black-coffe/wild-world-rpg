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
     *
     * F0.9 — верификация заголовка X-Telegram-Bot-API-Secret-Token.
     * Если в .env задан telegram.WEBHOOK_SECRET — проверяем что Telegram
     * прислал тот же токен в заголовке. Если значения не совпадают — 403.
     * Без этого любой, кто узнал webhook URL, мог постить fake updates.
     *
     * Если переменная не задана (legacy / dev) — проверка пропускается,
     * чтобы не сломать существующие установки. Чтобы включить:
     *   1) сгенерировать random-токен и положить в .env как
     *      telegram.WEBHOOK_SECRET = '<random_64_chars>'
     *   2) переустановить webhook у бота:
     *      curl -X POST https://api.telegram.org/bot<TOKEN>/setWebhook \
     *           -d "url=https://bot.wildworld.fun/telegram/webhook" \
     *           -d "secret_token=<random_64_chars>"
     */
    public function webhook()
    {
        $expected = getenv('telegram.WEBHOOK_SECRET');
        if (!empty($expected)) {
            $received = $this->request->getHeaderLine('X-Telegram-Bot-API-Secret-Token');
            if (!hash_equals($expected, $received)) {
                log_message('warning', '[Bot.webhook] secret_token mismatch — отклонено');
                return $this->response->setStatusCode(403)->setBody('forbidden');
            }
        }

        // E6 (ADR-108) Фаза 1 — достаём telegram_id ДО обработки, проставляем last_seen
        // ПОСЛЕ (в finally). Порядок важен: во время handle() код видит ПРЕДЫДУЩЕЕ
        // значение last_seen (основа digest «пока тебя не было», Ф2). Defensive — stamp
        // не должен влиять на обработку апдейта.
        $rawBody        = $this->request->getBody();
        $update         = is_string($rawBody) ? json_decode($rawBody, true) : null;
        $telegramUserId = is_array($update)
            ? \App\Services\Player\LastSeenService::extractTelegramId($update)
            : null;

        try {
            $this->telegram->handle();
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        } finally {
            if ($telegramUserId !== null) {
                (new \App\Services\Player\LastSeenService())->stampByTelegramId($telegramUserId);
            }
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
            return \App\Services\Notifications\MediaSender::sendPhotoOrText($data);
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
