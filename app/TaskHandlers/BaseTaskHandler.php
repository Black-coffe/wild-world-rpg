<?php

namespace App\TaskHandlers;

use App\TaskHandlers\Contracts\TaskHandlerInterface;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * F2.9 — общий базовый класс task-handler'ов.
 *
 * Что даёт:
 *  - Инициализация Telegram-моста с lazy-getter — handler не платит
 *    стоимость `new Telegram()` если ничего не отправляет в чат.
 *  - Унифицированный `protected function safeSendMessage(...)` /
 *    `safeSendPhoto(...)` — ловят TelegramException, не дают handler'у
 *    упасть из-за rate-limit или сетевой ошибки.
 *  - Общий слот для DI зависимостей (`config('GameBalance')`, etc.)
 *    подключается один раз в `__construct`.
 *
 * Существующие handler'ы (~70 штук) пока его НЕ наследуют — они работают
 * и ничего трогать не нужно. Этот класс — для НОВЫХ handler'ов и для
 * ребилдов в F2.3/F2.7/F2.8.
 *
 * Сравни: `AttackPlayerAction` имеет 13 моделей в конструкторе —
 * BaseTaskHandler минимизирует boilerplate за счёт getters-on-demand.
 */
abstract class BaseTaskHandler implements TaskHandlerInterface
{
    private ?Telegram $telegram = null;

    /**
     * Lazy-getter Telegram-объекта. Не инициализируется пока не нужен —
     * экономит для handler'ов, которые только пишут в БД без отправки
     * сообщений (HealthRegenerationHandler, ResourceBankUpdateHandler,
     * etc.).
     */
    protected function telegram(): Telegram
    {
        if ($this->telegram === null) {
            $apiKey   = (string) getenv('telegram.API_KEY');
            $username = (string) getenv('telegram.BOT_USERNAME');
            try {
                $this->telegram = new Telegram($apiKey, $username);
                Request::initialize($this->telegram);
            } catch (TelegramException $e) {
                log_message('error', '[' . static::class . '] Telegram init: ' . $e->getMessage());
                // Возвращаем «пустышку», чтобы дальнейший код не падал.
                // safeSend* всё равно проверяет результат.
                $this->telegram = new Telegram('invalid', 'invalid');
            }
        }
        return $this->telegram;
    }

    /**
     * Безопасная отправка текста. Никогда не бросает наружу.
     *
     * @param int|string         $chatId
     * @param array<string,mixed> $extra доп.поля Telegram API
     */
    protected function safeSendMessage($chatId, string $text, array $extra = []): void
    {
        // Гарантируем что Telegram инициализирован.
        $this->telegram();
        try {
            $payload = array_merge([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ], $extra);
            $response = Request::sendMessage($payload);
            if (!$response->isOk()) {
                log_message('warning', '[' . static::class . '] Telegram sendMessage not ok: '
                    . $response->getDescription());
            }
        } catch (\Throwable $e) {
            log_message('error', '[' . static::class . '] sendMessage exception: ' . $e->getMessage());
        }
    }

    /**
     * Безопасная отправка фото с подписью.
     *
     * @param int|string $chatId
     * @param string $photoPath абсолютный путь или URL
     * @param array<string,mixed> $extra
     */
    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
        $this->telegram();
        try {
            $payload = array_merge([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photoPath),
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ], $extra);
            $response = \App\Services\Notifications\MediaSender::sendPhotoOrText($payload);
            if (!$response->isOk()) {
                log_message('warning', '[' . static::class . '] Telegram sendPhoto not ok: '
                    . $response->getDescription());
            }
        } catch (\Throwable $e) {
            log_message('error', '[' . static::class . '] sendPhoto exception: ' . $e->getMessage());
        }
    }

    /**
     * Контракт `TaskHandlerInterface::handle`. Конкретные handler'ы
     * переопределяют этот метод.
     */
    abstract public function handle(array $task = []): void;
}
