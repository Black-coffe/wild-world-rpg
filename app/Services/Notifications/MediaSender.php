<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Throwable;

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
 *
 * Идея #12 (edit-in-place): {@see editOrSend()} — drop-in для навигационных handler'ов,
 * редактирует текущее сообщение вместо отправки нового (с graceful fallback на новое
 * сообщение при любой ошибке редактирования). См. ADR-018.
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
     * Идея #12 (edit-in-place): редактирует существующее сообщение вместо отправки
     * нового. Drop-in для НАВИГАЦИОННЫХ handler'ов (меню, переходы между экранами).
     *
     * Семантика:
     *  - В $params НЕТ 'message_id' → ведёт себя ровно как {@see sendPhotoOrText()}
     *    (новое сообщение). Это делает метод безопасной заменой на этапе перевода
     *    handler'а: пока handler не передаёт message_id — поведение не меняется.
     *  - 'message_id' ЕСТЬ:
     *      - media disabled (#14) → editMessageText (caption → text).
     *      - иначе → editMessageMedia (InputMediaPhoto: photo + caption + parse_mode) + reply_markup.
     *  - Любая ошибка редактирования (сообщение старше 48 ч / не от бота / «message is not
     *    modified» / несовпадение типа медиа / транспорт) → graceful fallback на
     *    {@see sendPhotoOrText()} (новое сообщение). Старое меню никогда не становится тупиком.
     *
     * ⚠️ ТОЛЬКО для навигации. Терминальные/уведомляющие сообщения (результаты боя,
     * завершения задач, ивент-бродкасты, эндгейм-уведомления) НЕ используют editOrSend —
     * они остаются {@see sendPhotoOrText()} (приходят как новые сообщения). См. ADR-018.
     *
     * Для caption-only переходов (то же фото, меняется только подпись + клавиатура) handler
     * может вызвать `Request::editMessageCaption(...)` напрямую — это легче, чем editMessageMedia.
     *
     * @param array<string,mixed> $params chat_id, photo, caption, parse_mode, reply_markup, message_id?
     */
    public static function editOrSend(array $params): ServerResponse
    {
        $messageId = $params['message_id'] ?? null;
        $chatId    = $params['chat_id'] ?? null;

        // Нет message_id или нет валидного chat_id → ничего не редактируем, ведём себя как раньше.
        if ($messageId === null || !is_numeric($chatId)) {
            unset($params['message_id']);
            return self::sendPhotoOrText($params);
        }

        try {
            $response = self::isMediaDisabled((int) $chatId)
                ? Request::editMessageText(self::buildEditTextParams($params))
                : Request::editMessageMedia(self::buildEditMediaParams($params));

            if ($response->isOk()) {
                return $response;
            }
            // Telegram вернул ok=false (message to edit not found / is not modified / can't be edited)
            // → проваливаемся в fallback ниже.
        } catch (Throwable) {
            // Любая ошибка валидации/транспорта → fallback на новое сообщение.
        }

        unset($params['message_id']);
        return self::sendPhotoOrText($params);
    }

    /**
     * Чистая трансформация: параметры sendPhoto → параметры editMessageText
     * (ветка media-disabled, #14: caption становится text).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function buildEditTextParams(array $params): array
    {
        $out = [
            'chat_id'    => $params['chat_id'] ?? null,
            'message_id' => $params['message_id'] ?? null,
            'text'       => self::captionOf($params),
        ];
        foreach (['parse_mode', 'reply_markup', 'disable_web_page_preview'] as $key) {
            if (isset($params[$key])) {
                $out[$key] = $params[$key];
            }
        }
        // sendMessage упадёт на пустом text — тот же placeholder, что и в sendPhotoOrText().
        if ($out['text'] === '') {
            $out['text'] = '📭 (без описания)';
        }

        return $out;
    }

    /**
     * Чистая трансформация: параметры sendPhoto → параметры editMessageMedia
     * (InputMediaPhoto несёт photo + caption + parse_mode; reply_markup отдельным полем).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function buildEditMediaParams(array $params): array
    {
        $mediaData = ['media' => $params['photo'] ?? ''];
        $caption   = self::captionOf($params);
        if ($caption !== '') {
            $mediaData['caption'] = $caption;
        }
        if (isset($params['parse_mode'])) {
            $mediaData['parse_mode'] = $params['parse_mode'];
        }

        $out = [
            'chat_id'    => $params['chat_id'] ?? null,
            'message_id' => $params['message_id'] ?? null,
            'media'      => new InputMediaPhoto($mediaData),
        ];
        if (isset($params['reply_markup'])) {
            $out['reply_markup'] = $params['reply_markup'];
        }

        return $out;
    }

    /**
     * Сброс кэша (тесты / админ-туллинг).
     */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Безопасно извлекает caption из sendPhoto-параметров как строку
     * (нескалярные значения → пустая строка).
     *
     * @param array<string,mixed> $params
     */
    private static function captionOf(array $params): string
    {
        $raw = $params['caption'] ?? '';

        return is_scalar($raw) ? (string) $raw : '';
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
