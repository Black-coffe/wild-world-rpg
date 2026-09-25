<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Web\VirtualChat;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * web-bridge-p1-04 (ADR-189 §4b, инвариант 1) — слой (б): последний рубеж перед сетью.
 *
 * Guzzle-middleware базового клиента Longman (его ставит {@see TelegramDeliveryProbe::install()}
 * всегда, в вебхуке, Worker, кроне и spark). Запрос, у которого `chat_id` из виртуального
 * диапазона ({@see VirtualChat::is()}), в сеть не уходит: отвечаем `ok:false` и пишем `error` —
 * до сюда такой вызов доходит только в обход слоя (а) `App\Services\Telegram\Request`, а это
 * дефект. Групповые id (`-100…`) и всё прочее проходят без изменений.
 *
 * `chat_id` читается из тела (form-urlencoded или multipart — Guzzle уже собрал его из
 * `form_params`/`multipart`), поток перематывается обратно.
 */
final class VirtualChatGuardMiddleware
{
    public const NAME = 'wildworld_virtual_chat_guard';

    public function __invoke(callable $handler): callable
    {
        return static function (RequestInterface $request, array $options) use ($handler) {
            $chatId = self::chatIdOf($request, $options);
            if ($chatId !== null && VirtualChat::is($chatId)) {
                $method = TelegramDeliveryProbe::methodFromPath($request->getUri()->getPath()) ?? '?';
                log_message('error', '[VirtualChatGuard] dropped ' . $method . ' to a virtual chat: layer (a) was bypassed');

                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'ok'          => false,
                    'error_code'  => 403,
                    'description' => 'Forbidden: web-only chat is not reachable in Telegram',
                ])));
            }

            return $handler($request, $options);
        };
    }

    /**
     * `chat_id` запроса: из опций (если ещё не собраны в тело) или из тела. Никогда не бросает.
     *
     * @param array<array-key,mixed> $options
     */
    public static function chatIdOf(RequestInterface $request, array $options = []): ?int
    {
        try {
            $form = $options['form_params'] ?? null;
            if (is_array($form) && array_key_exists('chat_id', $form)) {
                return self::toInt($form['chat_id']);
            }
            $multipart = $options['multipart'] ?? null;
            if (is_array($multipart)) {
                foreach ($multipart as $part) {
                    if (is_array($part) && ($part['name'] ?? null) === 'chat_id') {
                        return self::toInt($part['contents'] ?? null);
                    }
                }
            }

            $body = $request->getBody();
            if (! $body->isSeekable()) {
                return null;
            }
            $raw = (string) $body;
            $body->rewind();

            if (preg_match('/name="chat_id"\r?\n(?:[^\r\n]+\r?\n)*\r?\n(-?\d+)/', $raw, $m) === 1) {
                return (int) $m[1];
            }
            if (str_contains($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded')) {
                parse_str($raw, $fields);

                return self::toInt($fields['chat_id'] ?? null);
            }
        } catch (\Throwable $e) {
            log_message('error', '[VirtualChatGuard] chat_id parse failed: ' . $e->getMessage());
        }

        return null;
    }

    private static function toInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d{1,19}$/', $v) === 1) {
            return (int) $v;
        }

        return null;
    }
}
