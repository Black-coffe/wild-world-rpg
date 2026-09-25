<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Services\Logging\TelegramDeliveryProbe;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * web-bridge-p1-04 (ADR-189 §2) — клиент Longman на время одного действия `/play`.
 *
 * Ставит его story 07 (`Request::setClient(new BridgeClient(Probe::client(), $actorChat))`) и
 * снимает в `finally`; Worker, крон и CLI не ставят никогда (инвариант 4). Сюда доходят только
 * вызовы, обошедшие слой (а) {@see WebDelivery::route()}: POST-метод Bot API на чат актора или без
 * chat_id превращается в захват «только подпись» (фото и кнопки не восстанавливаются из
 * multipart) и в Telegram не уходит. Всё остальное — в delegate без изменений.
 */
final class BridgeClient implements ClientInterface
{
    public function __construct(private ClientInterface $delegate, private int $actorChat)
    {
    }

    /**
     * Longman зовёт `$client->post(...)`/`->get(...)` — магия Guzzle `Client`, в интерфейсе её нет.
     *
     * @param array<int,mixed> $args
     */
    public function __call(string $method, array $args): ResponseInterface
    {
        $uri     = $args[0] ?? '';
        $options = $args[1] ?? [];

        return $this->request(
            strtoupper($method),
            is_string($uri) || $uri instanceof UriInterface ? $uri : '',
            is_array($options) ? $options : []
        );
    }

    /**
     * @param string|UriInterface   $uri
     * @param array<string,mixed>   $options
     */
    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        $captured = $this->captureIfActor($method, (string) $uri, $options);

        return $captured ?? $this->delegate->request($method, $uri, $options);
    }

    /**
     * @param string|UriInterface   $uri
     * @param array<string,mixed>   $options
     */
    public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface
    {
        $captured = $this->captureIfActor($method, (string) $uri, $options);

        return $captured !== null ? Create::promiseFor($captured) : $this->delegate->requestAsync($method, $uri, $options);
    }

    /** @param array<string,mixed> $options */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->delegate->send($request, $options);
    }

    /** @param array<string,mixed> $options */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->delegate->sendAsync($request, $options);
    }

    /** @deprecated повторяет интерфейс Guzzle */
    public function getConfig(?string $option = null)
    {
        return $this->delegate->getConfig($option);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function captureIfActor(string $method, string $uri, array $options): ?ResponseInterface
    {
        if (strtoupper($method) !== 'POST') {
            return null;
        }
        $action = TelegramDeliveryProbe::methodFromPath((string) parse_url($uri, PHP_URL_PATH));
        if ($action === null) {
            return null;
        }

        $fields = self::scalarFields($options);
        $chatId = WebDelivery::chatIdOf($fields);
        if ($chatId !== null && $chatId !== $this->actorChat) {
            return null;
        }

        $flat = array_intersect_key($fields, array_flip(['chat_id', 'message_id', 'text', 'caption', 'parse_mode']));

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(
            WebDelivery::captureBypass($action, $flat)
        ));
    }

    /**
     * Скалярные поля запроса из `form_params` или `multipart` (потоки пропускаются).
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function scalarFields(array $options): array
    {
        $out  = [];
        $form = $options['form_params'] ?? null;
        if (is_array($form)) {
            foreach ($form as $k => $v) {
                if (is_scalar($v)) {
                    $out[(string) $k] = is_bool($v) ? (int) $v : $v;
                }
            }
        }
        $multipart = $options['multipart'] ?? null;
        if (is_array($multipart)) {
            foreach ($multipart as $part) {
                if (is_array($part) && is_string($part['name'] ?? null) && is_scalar($part['contents'] ?? null)) {
                    $out[$part['name']] = $part['contents'];
                }
            }
        }

        return $out;
    }
}
