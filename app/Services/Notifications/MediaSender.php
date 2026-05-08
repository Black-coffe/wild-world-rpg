<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Идея #14 (Yupirex, 13.02.2025): drop-in замена для `Request::sendPhoto`,
 * которая уважает флаг `characters.disable_media`.
 *
 * Если у игрока flag=1 → отправляется sendMessage с тем же caption +
 * reply_markup (без изображения). Если 0 → стандартный sendPhoto.
 *
 * Lookup кэшируется per-process (telegram_id → bool). Ошибки lookup
 * → false (default = images on).
 *
 * Mass-replace в 139 callsite'ах: `Request::sendPhoto(` → `MediaSender::sendPhotoOrText(`.
 */
final class MediaSender
{
    /** @var array<int,bool> telegram_chat_id → disable_media */
    private static array $cache = [];

    /**
     * Drop-in для `Request::sendPhoto([...])`. Принимает тот же массив
     * параметров (chat_id, photo, caption, parse_mode, reply_markup).
     *
     * @param array<string,mixed> $params
     */
    public static function sendPhotoOrText(array $params): ServerResponse
    {
        $chatId = $params['chat_id'] ?? null;
        if ($chatId === null) {
            return Request::sendPhoto($params);
        }

        if (self::isMediaDisabled((int) $chatId)) {
            $textParams = [
                'chat_id' => $chatId,
                'text'    => (string) ($params['caption'] ?? ''),
            ];
            foreach (['parse_mode', 'reply_markup', 'disable_web_page_preview'] as $key) {
                if (isset($params[$key])) {
                    $textParams[$key] = $params[$key];
                }
            }
            // Edge case: caption пустой — fallback на placeholder, чтобы
            // sendMessage не упал на пустом text.
            if ($textParams['text'] === '') {
                $textParams['text'] = '📭 (без описания)';
            }
            return Request::sendMessage($textParams);
        }

        return Request::sendPhoto($params);
    }

    /**
     * Сброс кэша (тесты / админ-туллинг).
     */
    public static function reset(): void
    {
        self::$cache = [];
    }

    private static function isMediaDisabled(int $telegramChatId): bool
    {
        if (array_key_exists($telegramChatId, self::$cache)) {
            return self::$cache[$telegramChatId];
        }

        $tu = (new TelegramUserModel())->where('telegram_id', $telegramChatId)->first();
        if (!$tu) {
            return self::$cache[$telegramChatId] = false;
        }
        // Entity или array — оба поддерживают array-доступ через ArrayAccessibleEntity.
        $tuId = isset($tu['id']) ? (int) $tu['id'] : 0;
        if ($tuId <= 0) {
            return self::$cache[$telegramChatId] = false;
        }

        $char = (new CharacterModel())->where('telegram_user_id', $tuId)->first();
        if (!$char) {
            return self::$cache[$telegramChatId] = false;
        }
        $disabled = isset($char['disable_media']) ? (int) $char['disable_media'] : 0;
        return self::$cache[$telegramChatId] = ($disabled === 1);
    }
}
