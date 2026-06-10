<?php

declare(strict_types=1);

namespace App\Services\Player;

use Config\Database;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 1 — «последний визит» игрока.
 *
 * `telegram_users.last_seen` фиксирует момент последнего ВХОДЯЩЕГО взаимодействия
 * (сообщение / нажатие кнопки). Проставляется в {@see \App\Controllers\Telegram\BotController::webhook()}
 * ПОСЛЕ `handle()` — поэтому во время обработки апдейта код видит ПРЕДЫДУЩЕЕ значение
 * (основа для оффлайн-digest Ф2 «пока тебя не было»).
 *
 * Pure-хелперы ({@see extractTelegramId()}, {@see hoursAgo()}) вынесены для unit-тестов
 * без БД/Telegram-моков; DB-методы покрываются Tier-3 webhook-smoke.
 */
class LastSeenService
{
    /**
     * Достать telegram user id из входящего update-массива (любой тип апдейта,
     * несущий `from.id`: message / edited_message / callback_query / …).
     * Чистая функция — без БД.
     *
     * @param array<array-key, mixed> $update декодированное тело webhook'а
     */
    public static function extractTelegramId(array $update): ?int
    {
        // Порядок не важен — берём первый wrapper, у которого есть from.id.
        $wrappers = [
            'message', 'edited_message', 'callback_query',
            'channel_post', 'edited_channel_post', 'inline_query',
            'chosen_inline_result', 'my_chat_member', 'chat_member',
        ];
        foreach ($wrappers as $key) {
            $wrapper = $update[$key] ?? null;
            if (! is_array($wrapper)) {
                continue;
            }
            $from = $wrapper['from'] ?? null;
            if (is_array($from) && isset($from['id']) && is_numeric($from['id'])) {
                $id = (int) $from['id'];
                if ($id > 0) {
                    return $id;
                }
            }
        }
        return null;
    }

    /**
     * Достать chat_id из входящего update-массива (для адресации сообщений —
     * напр. возврат-digest E6 Ф2). Чистая функция. Для приватного бота обычно
     * совпадает с telegram user id, но извлекаем явно (callback несёт chat в
     * `callback_query.message.chat.id`).
     *
     * @param array<array-key, mixed> $update
     */
    public static function extractChatId(array $update): ?int
    {
        // Прямые wrapper'ы с chat.
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            $chatId = self::chatIdFrom($update[$key] ?? null);
            if ($chatId !== null) {
                return $chatId;
            }
        }
        // callback_query несёт chat внутри message.
        $cq = $update['callback_query'] ?? null;
        if (is_array($cq)) {
            $chatId = self::chatIdFrom($cq['message'] ?? null);
            if ($chatId !== null) {
                return $chatId;
            }
        }
        return null;
    }

    /** @param mixed $wrapper */
    private static function chatIdFrom($wrapper): ?int
    {
        if (! is_array($wrapper)) {
            return null;
        }
        $chat = $wrapper['chat'] ?? null;
        if (is_array($chat) && isset($chat['id']) && is_numeric($chat['id'])) {
            $id = (int) $chat['id'];
            if ($id !== 0) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Сколько ПОЛНЫХ часов прошло с момента `$lastSeen` (datetime-строка) до `$nowTs`.
     * `null`, если `$lastSeen` пуст/непарсится. Отрицательную дельту клампим к 0.
     * Чистая функция (now инжектируется для time-stable тестов).
     */
    public static function hoursAgo(?string $lastSeen, ?int $nowTs = null): ?int
    {
        if ($lastSeen === null || $lastSeen === '') {
            return null;
        }
        $ts = strtotime($lastSeen);
        if ($ts === false) {
            return null;
        }
        $now   = $nowTs ?? time();
        $delta = $now - $ts;
        if ($delta < 0) {
            $delta = 0;
        }
        return intdiv($delta, 3600);
    }

    /**
     * Проставить `last_seen = now()` по telegram_id. Defensive: невалидный id —
     * no-op; любая ошибка БД глотается (не должна ломать обработку webhook'а).
     */
    public function stampByTelegramId(int $telegramId): void
    {
        if ($telegramId <= 0) {
            return;
        }
        try {
            Database::connect()
                ->table('telegram_users')
                ->where('telegram_id', $telegramId)
                ->update(['last_seen' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            log_message('error', '[LastSeenService] stamp: ' . $e->getMessage());
        }
    }

    /**
     * Прочитать `last_seen` по telegram_users.id (FK из characters.telegram_user_id).
     * Возвращает datetime-строку или null.
     */
    public function getById(int $telegramUserId): ?string
    {
        if ($telegramUserId <= 0) {
            return null;
        }
        try {
            $res = Database::connect()
                ->table('telegram_users')
                ->select('last_seen')
                ->where('id', $telegramUserId)
                ->get();
            if ($res === false) {
                return null;
            }
            $row = $res->getRowArray();
        } catch (\Throwable $e) {
            log_message('error', '[LastSeenService] getById: ' . $e->getMessage());
            return null;
        }
        $val = $row['last_seen'] ?? null;
        return is_string($val) && $val !== '' ? $val : null;
    }
}
