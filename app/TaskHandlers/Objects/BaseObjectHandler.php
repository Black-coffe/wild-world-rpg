<?php

namespace App\TaskHandlers\Objects;

use App\Services\Notifications\BlockedRecipient;
use App\Services\Telegram\Request;
use App\Services\Telegram\TelegramBridge;
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
 *  - Відмова «гравець заблокував бота» ({@see BlockedRecipient}, B16): мітка
 *    `telegram_users.blocked_at` і один WARNING; інші відмови — як раніше.
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
    /** Подъём — {@see TelegramBridge::ensure()}: при неудаче null, без исключения. */
    protected function telegram(): ?Telegram
    {
        return TelegramBridge::ensure() ? TelegramBridge::instance() : null;
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
            if (!$response->isOk()
                && ! BlockedRecipient::handle($chatId, (string) $response->getDescription(), '[' . static::class . ']', 'sendMessage')) {
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
            $response = \App\Services\Notifications\MediaSender::sendPhotoOrText($payload);
            if (!$response->isOk()
                && ! BlockedRecipient::handle($chatId, (string) $response->getDescription(), '[' . static::class . ']', 'sendPhoto')) {
                log_message('warning', '[' . static::class . '] Telegram sendPhoto not ok: '
                    . $response->getDescription());
            }
        } catch (\Throwable $e) {
            log_message('error', '[' . static::class . '] sendPhoto exception: ' . $e->getMessage());
        }
    }
}
