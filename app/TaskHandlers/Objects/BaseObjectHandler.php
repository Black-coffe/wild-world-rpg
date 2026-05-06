<?php

namespace App\TaskHandlers\Objects;

use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * v0.51.39 (F2.9 batch-4): спільний базовий клас для object discovery handlers.
 *
 * Parallel до `BaseTaskHandler` — але для іншої сім'ї хендлерів з 3-arg
 * сигнатурою `handle($object, $cell, $character)` (ObjectHandlerInterface).
 *
 * Що дає:
 *  - Lazy Telegram init через `telegram()` — handler не платить `new Telegram()`
 *    якщо реальний use-case не дійшов до send'у.
 *  - Уніфіковані `safeSendMessage()` / `safeSendPhoto()` — catch TelegramException,
 *    handler не падає при rate-limit / network error.
 *
 * Конкретні нащадки додають `implements ObjectHandlerInterface` самі (не форсимо
 * це у parent — щоб не нав'язувати інтерфейс іншим потенційним об'єктним
 * хендлерам у майбутньому).
 *
 * NOTE: код дублюється з BaseTaskHandler. Майбутній DRY refactor —
 * extract до shared trait `TelegramSender`, використовувати в обох базах.
 */
abstract class BaseObjectHandler
{
    private ?Telegram $telegram = null;

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
                $this->telegram = new Telegram('invalid', 'invalid');
            }
        }
        return $this->telegram;
    }

    /**
     * @param int|string         $chatId
     * @param array<string,mixed> $extra
     */
    protected function safeSendMessage($chatId, string $text, array $extra = []): void
    {
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
     * @param int|string $chatId
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
            $response = Request::sendPhoto($payload);
            if (!$response->isOk()) {
                log_message('warning', '[' . static::class . '] Telegram sendPhoto not ok: '
                    . $response->getDescription());
            }
        } catch (\Throwable $e) {
            log_message('error', '[' . static::class . '] sendPhoto exception: ' . $e->getMessage());
        }
    }
}
