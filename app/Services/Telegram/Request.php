<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request as LongmanRequest;

/**
 * Тонкая надстройка над `Longman\TelegramBot\Request`: единственная точка, где
 * исходящая инлайн-клавиатура приводится к правилу «ноль одиночек в ряду»
 * ({@see KeyboardNormalizer}).
 *
 * Почему надстройка, а не правка экранов: одиночных рядов оказалось 365 в 151
 * файле, а мест сборки клавиатур — 488 в 294 файлах. Триста правок разъедутся
 * при первом же новом экране; одна точка — не разъедется, и её держит тест.
 *
 * Как ловит всё: любой `Request::что-угодно()` у Longman уходит в `__callStatic`
 * → `static::send()`, поэтому переопределения `send()` достаточно для
 * editMessageText / sendPhoto / editMessageMedia / editMessageReplyMarkup.
 * Исключение — `sendMessage()`: он объявлен явно и зовёт `self::send()`,
 * мимо наследника, поэтому перекрыт отдельно.
 *
 * Всё остальное (encodeFile, getMe, …) наследуется без изменений — вызовы в коде
 * менять не нужно, отличается только строка `use`.
 */
class Request extends LongmanRequest
{
    /** @param array<string,mixed> $data */
    public static function send(string $action, array $data = []): ServerResponse
    {
        return parent::send($action, KeyboardNormalizer::normalize($data));
    }

    /**
     * @param array<string,mixed> $data
     * @param array<mixed>|null   $extras сигнатура by-ref повторяет родителя как есть
     */
    public static function sendMessage(array $data, ?array &$extras = []): ServerResponse
    {
        return parent::sendMessage(KeyboardNormalizer::normalize($data), $extras);
    }
}
