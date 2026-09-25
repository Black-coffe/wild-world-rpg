<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\VirtualChatGuardMiddleware;
use App\Services\Web\VirtualChat;
use CodeIgniter\Test\CIUnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * web-bridge-p1-04 (ADR-189 §4b, инвариант 1) — слой (б): запрос на виртуальный chat_id не доходит
 * до внутреннего обработчика (сети) ни как form, ни как multipart, и пишет `error`. Групповой
 * отрицательный id проходит.
 *
 * Клиент настоящий (Guzzle собирает тело из опций, как для Longman), сеть — MockHandler.
 *
 * @internal
 */
final class VirtualChatGuardMiddlewareTest extends CIUnitTestCase
{
    /** @var list<mixed> */
    private array $reached = [];

    public function testVirtualChatInFormParamsNeverReachesInnerHandler(): void
    {
        $client = $this->client();
        $res    = $client->post('/bot1:x/sendMessage', ['form_params' => ['chat_id' => VirtualChat::idForAccount(7), 'text' => 'hi']]);

        $this->assertSame([], $this->reached);
        $body = json_decode((string) $res->getBody(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['ok']);
        $this->assertLogged('error', '[VirtualChatGuard] dropped sendMessage to a virtual chat: layer (a) was bypassed');
    }

    public function testVirtualChatInMultipartNeverReachesInnerHandler(): void
    {
        $photo = fopen('php://memory', 'r+b');
        $this->assertIsResource($photo);
        fwrite($photo, str_repeat('x', 2048));
        rewind($photo);

        $client = $this->client();
        $client->post('/bot1:x/sendPhoto', ['multipart' => [
            ['name' => 'photo', 'contents' => $photo],
            ['name' => 'chat_id', 'contents' => (string) VirtualChat::idForAccount(12345)],
            ['name' => 'caption', 'contents' => 'Лут'],
        ]]);

        $this->assertSame([], $this->reached);
        $this->assertLogged('error', '[VirtualChatGuard] dropped sendPhoto to a virtual chat: layer (a) was bypassed');
    }

    public function testVirtualChatInRawOptionsIsDetected(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', '/bot1:x/sendMessage');
        $virtual = VirtualChat::idForAccount(3);

        $this->assertSame($virtual, VirtualChatGuardMiddleware::chatIdOf($request, ['form_params' => ['chat_id' => $virtual]]));
        $this->assertSame($virtual, VirtualChatGuardMiddleware::chatIdOf($request, ['multipart' => [['name' => 'chat_id', 'contents' => (string) $virtual]]]));
    }

    public function testGroupNegativeIdAndPrivateIdPassThrough(): void
    {
        $client = $this->client(2);
        $client->post('/bot1:x/deleteMessage', ['form_params' => ['chat_id' => -1001234567890, 'message_id' => 5]]);
        $client->post('/bot1:x/sendMessage', ['multipart' => [['name' => 'chat_id', 'contents' => '123456789'], ['name' => 'text', 'contents' => 'hi']]]);

        $this->assertCount(2, $this->reached);
    }

    private function client(int $responses = 1): Client
    {
        $mock = new MockHandler(array_fill(0, $responses, new Response(200, [], '{"ok":true,"result":true}')));
        $inner = function (\Psr\Http\Message\RequestInterface $request, array $options) use ($mock) {
            $this->reached[] = $request;

            return $mock($request, $options);
        };
        $stack = HandlerStack::create($inner);
        $stack->push(new VirtualChatGuardMiddleware(), VirtualChatGuardMiddleware::NAME);

        return new Client(['base_uri' => 'https://api.telegram.org', 'handler' => $stack, 'http_errors' => false]);
    }
}
