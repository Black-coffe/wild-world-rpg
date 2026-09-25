<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Web;

use App\Services\Web\BridgeClient;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebScreenStore;
use CodeIgniter\Test\CIUnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * web-bridge-p1-04 (ADR-189 §2) — {@see BridgeClient}: вызов, обошедший слой (а), на чат актора
 * или без chat_id становится захватом «только подпись» и в delegate не уходит; другой чат уходит
 * в delegate без изменений.
 *
 * Без БД: хранилище экрана подменено счётчиком id.
 *
 * @internal
 */
final class BridgeClientTest extends CIUnitTestCase
{
    private const ACTOR = 700500600;

    /** @var list<array<string,mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        WebDelivery::reset();
        WebDelivery::useServices(new class extends WebScreenStore {
            private int $next = 1000000000;

            public function __construct()
            {
            }

            public function nextMessageId(int $characterId): int
            {
                return $this->next++;
            }
        });
        WebDelivery::beginCapture(self::ACTOR, 1);
    }

    protected function tearDown(): void
    {
        WebDelivery::reset();
        parent::tearDown();
    }

    public function testActorChatAndChatlessRequestsAreCapturedNotDelegated(): void
    {
        $bridge = new BridgeClient($this->delegate(), self::ACTOR);

        $sent = $bridge->post('/bot1:x/sendMessage', ['form_params' => ['chat_id' => self::ACTOR, 'text' => 'Ответ', 'reply_markup' => '{"inline_keyboard":[[{"text":"A","callback_data":"a"}]]}']]);
        $photo = fopen('php://memory', 'r+b');
        $bridge->post('/bot1:x/sendPhoto', ['multipart' => [
            ['name' => 'chat_id', 'contents' => (string) self::ACTOR],
            ['name' => 'photo', 'contents' => $photo],
            ['name' => 'caption', 'contents' => 'Подпись фото'],
        ]]);
        $bridge->post('/bot1:x/answerCallbackQuery', ['form_params' => ['callback_query_id' => 'w-1', 'text' => 'Готово']]);

        $this->assertSame([], $this->history, 'delegate не вызывался');
        $body = json_decode((string) $sent->getBody(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['ok']);
        $this->assertSame(1000000000, $body['result']['message_id']);

        $capture = WebDelivery::endCapture();
        $this->assertSame('Ответ', $capture['sent'][0]['text']);
        $this->assertSame([], $capture['sent'][0]['inline_keyboard'], 'только подпись');
        $this->assertSame('Подпись фото', $capture['sent'][1]['caption']);
        $this->assertNull($capture['sent'][1]['photo_url']);
        $this->assertSame('Готово', $capture['alert']);
    }

    public function testOtherChatIsDelegatedUnchanged(): void
    {
        $bridge  = new BridgeClient($this->delegate(), self::ACTOR);
        $options = ['form_params' => ['chat_id' => 123456789, 'text' => 'Тебя атакуют']];

        $res = $bridge->post('/bot1:x/sendMessage', $options);

        $this->assertCount(1, $this->history);
        $this->assertSame('/bot1:x/sendMessage', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(http_build_query($options['form_params']), (string) $this->history[0]['request']->getBody());
        $this->assertSame('{"ok":true,"result":{"message_id":5}}', (string) $res->getBody());
        $this->assertSame([], WebDelivery::endCapture()['sent']);
    }

    public function testGetIsDelegated(): void
    {
        $bridge = new BridgeClient($this->delegate(), self::ACTOR);
        $bridge->get('/file/bot1:x/photos/a.jpg');

        $this->assertCount(1, $this->history);
    }

    private function delegate(): Client
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"ok":true,"result":{"message_id":5}}')]));
        $stack->push(Middleware::history($this->history));

        return new Client(['base_uri' => 'https://api.telegram.org', 'handler' => $stack]);
    }
}
