<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Web\WebDelivery;
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
 * web-bridge-p1-04 (ADR-189 §4a): после нормализатора и до отправки вызов проходит
 * {@see WebDelivery::route()} — захват экрана `/play`, входящие виртуального чата и копия
 * привязанному игроку. `null` от него — вызов идёт в Telegram ровно как раньше.
 *
 * Всё остальное (encodeFile, getMe, …) наследуется без изменений — вызовы в коде
 * менять не нужно, отличается только строка `use`.
 */
class Request extends LongmanRequest
{
    /** @param array<string,mixed> $data */
    public static function send(string $action, array $data = []): ServerResponse
    {
        $data = KeyboardNormalizer::normalize($data);

        return WebDelivery::route($action, $data) ?? static::transport($action, $data);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<mixed>|null   $extras сигнатура by-ref повторяет родителя как есть
     */
    public static function sendMessage(array $data, ?array &$extras = []): ServerResponse
    {
        $data = KeyboardNormalizer::normalize($data);

        return WebDelivery::route('sendMessage', $data) ?? static::transportSendMessage($data, $extras);
    }

    /**
     * Настоящая отправка (Longman). Шов для тестов: наследник подменяет, чтобы доказать, дошёл ли
     * вызов до Telegram.
     *
     * @param array<string,mixed> $data
     */
    protected static function transport(string $action, array $data): ServerResponse
    {
        return parent::send($action, $data);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<mixed>|null   $extras
     */
    protected static function transportSendMessage(array $data, ?array &$extras = []): ServerResponse
    {
        return parent::sendMessage($data, $extras);
    }
}
