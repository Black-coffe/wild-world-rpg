<?php

use App\Filters\TelegramRateLimitFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тест-двойник фильтра: перехватывает вызовы Bot API вместо похода в сеть.
 */
final class SpyTelegramRateLimitFilter extends TelegramRateLimitFilter
{
    /** @var list<array{method: string, params: array<string, scalar>}> */
    public array $calls = [];

    /** @param array<string, scalar> $params */
    protected function callTelegram(string $method, array $params): void
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
    }
}

/**
 * F1.5 — rate-limit Telegram-webhook'а.
 *
 * 🔴 Главный тест здесь — testNormalPlayOverSeveralMinutesIsNeverBlocked: он
 * воспроизводит реальный инцидент 2026-07-30. Прежняя реализация опиралась на
 * `$cache->increment()`, а CI4 FileHandler внутри делает `save($key, $val, $ttl)`,
 * что перезаписывает `time => now`. TTL продлевался на каждом тапе, счётчик — нет,
 * и «30 в минуту» превращалось в «30 за всю сессию игры». Живой игрок делал
 * 30 тапов за 3 мин 31 сек (~8.5/мин) и получал минуту тишины; за 7 дней —
 * 340 срабатываний у 26 из 63 активных игроков, из них 95.6% по обычной игре.
 *
 * @internal
 */
final class TelegramRateLimitFilterTest extends CIUnitTestCase
{
    private const LIMIT = 60;
    private const USER  = 424242;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('telegram.RATE_LIMIT_PER_MINUTE=' . self::LIMIT);
        \Config\Services::cache()->delete('tg_rate_' . self::USER);
        Time::setTestNow('2026-07-30 20:00:00');
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        putenv('telegram.RATE_LIMIT_PER_MINUTE');
        \Config\Services::cache()->delete('tg_rate_' . self::USER);

        parent::tearDown();
    }

    /** @param array<string, mixed> $update */
    private function requestFor(array $update): IncomingRequest
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        return $request;
    }

    /** @return array<string, mixed> */
    private function callbackTap(): array
    {
        return [
            'update_id'      => 1,
            'callback_query' => [
                'id'   => 'cb-1',
                'from' => ['id' => self::USER, 'is_bot' => false],
                'data' => 'inventory',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function keyboardTap(): array
    {
        return [
            'update_id' => 2,
            'message'   => [
                'message_id' => 5,
                'from'       => ['id' => self::USER, 'is_bot' => false],
                'chat'       => ['id' => self::USER, 'type' => 'private'],
                'text'       => '🏠 База',
            ],
        ];
    }

    /** @param array<string, mixed> $update */
    private function tap(TelegramRateLimitFilter $filter, array $update): mixed
    {
        return $filter->before($this->requestFor($update));
    }

    /**
     * 🔴 Регрессия инцидента: обычная игра дольше минуты, но с паузами короче
     * минуты. Раньше счётчик копился через окна и упирался в потолок; теперь
     * каждое новое окно обнуляет его.
     */
    public function testNormalPlayOverSeveralMinutesIsNeverBlocked(): void
    {
        $filter = new SpyTelegramRateLimitFilter();

        // 30 минут игры: тап каждые 20 секунд = 90 тапов, скорость 3/мин.
        // Тапов ЗАВЕДОМО больше лимита (60), но в каждое окно попадает по три —
        // при накапливающемся счётчике игрок был бы отрублен на 61-м.
        for ($i = 0; $i < self::LIMIT + 30; $i++) {
            Time::setTestNow(Time::parse('2026-07-30 20:00:00')->addSeconds($i * 20));
            $this->assertNull(
                $this->tap($filter, $this->callbackTap()),
                "Тап #{$i} обычной игры не должен блокироваться"
            );
        }

        $this->assertSame([], $filter->calls, 'Игрока не должны были ни о чём предупреждать');
    }

    /** Настоящий спам внутри одного окна — по-прежнему режется. */
    public function testBurstWithinOneWindowIsBlocked(): void
    {
        $filter = new SpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->assertNull($this->tap($filter, $this->callbackTap()), "Тап #{$i} в пределах лимита");
        }

        $blocked = $this->tap($filter, $this->callbackTap());

        $this->assertInstanceOf(ResponseInterface::class, $blocked);
        $this->assertSame(200, $blocked->getStatusCode(), 'Telegram должен получить ack, а не 429');
        $this->assertSame('', (string) $blocked->getBody());
    }

    /** Окно закрывается по календарю, а не по последнему тапу. */
    public function testWindowResetsAfterSixtySeconds(): void
    {
        $filter = new SpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->tap($filter, $this->callbackTap());
        }
        $this->assertInstanceOf(ResponseInterface::class, $this->tap($filter, $this->callbackTap()));

        // Ровно граница окна от ПЕРВОГО тапа.
        Time::setTestNow(Time::parse('2026-07-30 20:01:00'));
        $this->assertNull($this->tap($filter, $this->callbackTap()), 'Новое окно — счётчик с нуля');
    }

    /** Отброшенный тап не должен выглядеть как сломанная кнопка. */
    public function testBlockedCallbackGetsToastInsteadOfSilence(): void
    {
        $filter = new SpyTelegramRateLimitFilter();

        for ($i = 0; $i <= self::LIMIT; $i++) {
            $this->tap($filter, $this->callbackTap());
        }

        $this->assertCount(1, $filter->calls);
        $this->assertSame('answerCallbackQuery', $filter->calls[0]['method']);
        $this->assertSame('cb-1', $filter->calls[0]['params']['callback_query_id']);

        $text = $filter->calls[0]['params']['text'];
        $this->assertIsString($text);
        $this->assertStringContainsString('Слишком много действий', $text);
    }

    /**
     * 🔴 Анти-усилитель: спам не должен превращаться в поток НАШИХ исходящих
     * вызовов к Bot API. 200 отброшенных тапов = одно уведомление, иначе фильтр
     * сам создаёт ту нагрузку, от которой защищает.
     */
    public function testFloodProducesExactlyOneOutboundCallPerWindow(): void
    {
        $filter = new SpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::LIMIT + 200; $i++) {
            $this->tap($filter, $this->callbackTap());
        }

        $this->assertCount(1, $filter->calls, '200 отброшенных тапов — один вызов Bot API');

        // Следующее окно — снова ровно одно уведомление, а не тишина навсегда.
        Time::setTestNow(Time::parse('2026-07-30 20:01:00'));
        for ($i = 0; $i < self::LIMIT + 200; $i++) {
            $this->tap($filter, $this->callbackTap());
        }

        $this->assertCount(2, $filter->calls, 'Новое окно — новое право на предупреждение');
    }

    /** Reply-клавиатура: предупреждаем один раз за окно, а не на каждый тап. */
    public function testBlockedKeyboardTapWarnsOncePerWindow(): void
    {
        $filter = new SpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::LIMIT + 5; $i++) {
            $this->tap($filter, $this->keyboardTap());
        }

        $this->assertCount(1, $filter->calls, 'Пять отброшенных тапов — одно предупреждение');
        $this->assertSame('sendMessage', $filter->calls[0]['method']);
        $this->assertSame(self::USER, $filter->calls[0]['params']['chat_id']);
    }

    /** Апдейты без отправителя (channel_post и пр.) не занимают чужую квоту. */
    public function testUpdateWithoutSenderIsNotCounted(): void
    {
        $filter = new SpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::LIMIT * 2; $i++) {
            $this->assertNull($this->tap($filter, [
                'update_id'    => $i,
                'channel_post' => ['chat' => ['id' => -100], 'text' => 'x'],
            ]));
        }
    }

    /** Пустое/мусорное тело не должно ронять фильтр. */
    public function testGarbageBodyPassesThrough(): void
    {
        $filter  = new SpyTelegramRateLimitFilter();
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn('not-json');

        $this->assertNull($filter->before($request));
    }
}
