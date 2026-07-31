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

    /** Telegram-лимит подписи фото (символов ПОСЛЕ парсинга HTML-entity). */
    private const PHOTO_CAPTION_LIMIT = 1024;

    /** Telegram-лимит обычного текстового сообщения. */
    private const TEXT_MESSAGE_LIMIT = 4096;

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

        // #14: игрок отключил картинки → тот же caption уходит текстом (лимит 4096).
        if (self::isMediaDisabled((int) $chatId)) {
            return self::sendCaptionAsText($params);
        }

        // Прод-инцидент 2026-06-16 (Ярик/SarCasM, биом «Пещеры»): подпись фото в
        // Telegram ограничена 1024 символами (после парсинга entity). Длинный лут
        // high-level игрока в богатом биоме (до 24 ресурсов) даёт caption >1024 →
        // sendPhoto возвращал ok=false, а safeSendPhoto писал лишь warning (ниже
        // log-threshold прода) → уведомление «ресурсы собраны» ТИХО терялось (ресурсы
        // при этом уже сохранены). Картинка — enhancement (MEDIA-OFF канон, ADR-020):
        // деградируем к тексту, весь смысл (лут/числа) доходит. Защищает все callsite'ы.
        if (self::captionExceedsPhotoLimit(self::captionOf($params))) {
            return self::sendCaptionAsText($params);
        }

        return Request::sendPhoto($params);
    }

    /**
     * Отправляет caption фото как обычное текстовое сообщение. Используется в ветке
     * media-off (#14) И при подписи длиннее лимита фото (деградация). Переносит
     * parse_mode/reply_markup/preview/disable_notification, пустой caption → placeholder,
     * защита от 4096-overflow.
     *
     * @param array<string,mixed> $params исходные sendPhoto-параметры
     */
    private static function sendCaptionAsText(array $params): ServerResponse
    {
        $text = self::captionOf($params);
        // Edge case: caption пустой — placeholder, чтобы sendMessage не упал на 0-длине.
        if ($text === '') {
            $text = '📭 (без описания)';
        }
        $text = self::clampToTextLimit($text);

        $textParams = [
            'chat_id' => $params['chat_id'] ?? null,
            'text'    => $text,
        ];
        // W28 (ADR-083): несём disable_notification в text-ветку, иначе при
        // disable_media/деградации тихие рутинные уведомления зазвучали бы.
        foreach (['parse_mode', 'reply_markup', 'disable_web_page_preview', 'disable_notification'] as $key) {
            if (isset($params[$key])) {
                $textParams[$key] = $params[$key];
            }
        }

        return Request::sendMessage($textParams);
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
        return self::sendPhotoOrText(self::refreshPhotoStream($params));
    }

    /**
     * 🔴 Прод-баг 2026-07-31 (тихая потеря photo-экранов, живёт с v0.51.230 / 10.05.2026).
     *
     * `Request::encodeFile()` отдаёт **поток**, а не путь. Неудачная попытка редактирования
     * его ВЫЧИТЫВАЕТ, и fallback переотправлял тот же — уже исчерпанный — поток. Telegram
     * на это отвечает `ok=false «there is no photo in the request»`, и сообщение молча
     * пропадало: игрок жал кнопку и не получал НИЧЕГО (доказано пробой на testbot —
     * повторная отправка ok=false, свежая ok=true).
     *
     * Срабатывало штатно: `editMessageMedia` не умеет править ТЕКСТОВОЕ сообщение, а к
     * photo-экранам почти всегда приходят с текстового (`editTextOrSend`) — то есть edit
     * падал закономерно, и фолбэк был единственным шансом доставить сообщение.
     *
     * Лечение: перед фолбэком переоткрываем файл по URI потока (`stream_get_meta_data`) —
     * callsite'ы менять не нужно. Если URI недоступен или переоткрытие упало — оставляем
     * как было (хуже, чем сейчас, уже не станет).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function refreshPhotoStream(array $params): array
    {
        if (!isset($params['photo']) || !is_resource($params['photo'])) {
            return $params;
        }

        $uri = stream_get_meta_data($params['photo'])['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            return $params;
        }

        try {
            $params['photo'] = Request::encodeFile($uri);
        } catch (Throwable) {
            // Переоткрыть не вышло — отдаём исходные параметры без изменений.
        }

        return $params;
    }

    /**
     * Идея #12 (edit-in-place) — text-аналог {@see editOrSend()} для НАВИГАЦИОННЫХ
     * text-handler'ов (меню / просмотры без фото: Sell/, Quest/, склад ресурсов и т.п.).
     * Редактирует существующее сообщение (editMessageText) вместо отправки нового.
     *
     * Семантика 'message_id' как в {@see editOrSend()}:
     *  - НЕТ 'message_id' (или chat_id невалиден) → ведёт себя ровно как Request::sendMessage()
     *    (новое сообщение) — безопасная замена на этапе вайр-ина handler'а.
     *  - 'message_id' ЕСТЬ → Request::editMessageText(). Любая ошибка редактирования
     *    (сообщение старше 48 ч / не от бота / «message is not modified» / клик по
     *    photo-сообщению, где нечего редактировать как text / транспорт) → graceful
     *    fallback на новое Request::sendMessage(). Старое меню никогда не становится тупиком.
     *
     * ⚠️ ТОЛЬКО для навигации. Терминальные/уведомляющие сообщения (результаты, завершения
     * задач, ивент-бродкасты, эндгейм) шлются обычным Request::sendMessage() — новым
     * сообщением. См. ADR-018.
     *
     * @param array<string,mixed> $params chat_id, text, parse_mode?, reply_markup?, disable_web_page_preview?, message_id?
     */
    public static function editTextOrSend(array $params): ServerResponse
    {
        $messageId = $params['message_id'] ?? null;
        $chatId    = $params['chat_id'] ?? null;

        if ($messageId === null || !is_numeric($chatId)) {
            return self::sendTextFallback($params);
        }

        try {
            $response = Request::editMessageText(self::buildEditTextOnlyParams($params));
            if ($response->isOk()) {
                return $response;
            }
            // Telegram вернул ok=false (message to edit not found / is not modified /
            // can't be edited / нет текста в сообщении) → fallback ниже.
        } catch (Throwable) {
            // Любая ошибка валидации/транспорта → fallback на новое сообщение.
        }

        return self::sendTextFallback($params);
    }

    /**
     * Чистая трансформация: параметры sendMessage → параметры editMessageText.
     * В отличие от {@see buildEditTextParams()} здесь источник — уже text-сообщение,
     * никакого caption→text mapping. Используется {@see editTextOrSend()}.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function buildEditTextOnlyParams(array $params): array
    {
        $rawText = $params['text'] ?? '';
        $text    = is_scalar($rawText) ? (string) $rawText : '';
        if ($text === '') {
            $text = '📭 (без описания)';
        }

        $out = [
            'chat_id'    => $params['chat_id'] ?? null,
            'message_id' => $params['message_id'] ?? null,
            'text'       => $text,
        ];
        foreach (['parse_mode', 'reply_markup', 'disable_web_page_preview'] as $key) {
            if (isset($params[$key])) {
                $out[$key] = $params[$key];
            }
        }

        return $out;
    }

    /**
     * Новое text-сообщение (ветка fallback / нет message_id). Снимает 'message_id',
     * подставляет placeholder для пустого text (Request::sendMessage упал бы на 0-длине).
     *
     * @param array<string,mixed> $params
     */
    private static function sendTextFallback(array $params): ServerResponse
    {
        unset($params['message_id']);
        $rawText = $params['text'] ?? '';
        $text    = is_scalar($rawText) ? (string) $rawText : '';
        $params['text'] = ($text !== '') ? $text : '📭 (без описания)';

        return Request::sendMessage($params);
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

    /**
     * Подпись фото длиннее Telegram-лимита (1024 символа ПОСЛЕ парсинга HTML-entity)?
     * Pure — тестируется без сети. При true вызывающий код деградирует к тексту.
     */
    public static function captionExceedsPhotoLimit(string $captionHtml): bool
    {
        return self::visibleTgLength($captionHtml) > self::PHOTO_CAPTION_LIMIT;
    }

    /**
     * Длина текста в UTF-16 code units ПОСЛЕ удаления HTML-тегов и декода entity —
     * ровно метрика Telegram для лимитов caption/text ("after entities parsing").
     * Эмодзи (суррогатная пара) считается за 2 unit, как у Telegram.
     */
    private static function visibleTgLength(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // mb_convert_encoding со string-входом всегда возвращает string (имена кодировок валидны).
        $u16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return intdiv(strlen($u16), 2);
    }

    /**
     * Подрезает текст до Telegram-лимита сообщения (4096) по границе строки, чтобы
     * не разорвать HTML-тег. Для реального лута (≤~1500 симв.) — no-op; страхует
     * экстремальный edge, чтобы деградация не упёрлась в новый тихий ok=false.
     */
    private static function clampToTextLimit(string $text): string
    {
        if (self::visibleTgLength($text) <= self::TEXT_MESSAGE_LIMIT) {
            return $text;
        }
        // Бюджет по сырым символам (с тегами) заведомо < лимита видимой длины → безопасно.
        $cut = mb_substr($text, 0, self::TEXT_MESSAGE_LIMIT - 32);
        $nl  = mb_strrpos($cut, "\n");
        if (is_int($nl) && $nl > 0) {
            $cut = mb_substr($cut, 0, $nl);
        }

        return $cut . "\n…";
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
