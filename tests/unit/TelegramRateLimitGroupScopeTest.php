<?php

use App\Filters\TelegramRateLimitFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Тест-двойник фильтра: перехватывает вызовы Bot API вместо похода в сеть
 * (тот же паттерн, что в {@see TelegramRateLimitFilterTest}).
 */
final class GroupScopeSpyTelegramRateLimitFilter extends TelegramRateLimitFilter
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
 * community-chat-bot-01 — групповой/супергрупповой трафик считается в ОТДЕЛЬНОМ ключе
 * кэша (по чату, не по `from.id`), с собственным лимитом. Персональный игровой лимит
 * 60/мин не должен расходоваться флудом в общем чате.
 *
 * @internal
 */
final class TelegramRateLimitGroupScopeTest extends CIUnitTestCase
{
    private const LIMIT   = 60;
    private const USER    = 555555;
    private const GROUP   = -1001234567890;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('telegram.RATE_LIMIT_PER_MINUTE=' . self::LIMIT);
        \Config\Services::cache()->delete('tg_rate_' . self::USER);
        \Config\Services::cache()->delete('tg_rate_group_' . self::GROUP);
        Time::setTestNow('2026-08-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        putenv('telegram.RATE_LIMIT_PER_MINUTE');
        \Config\Services::cache()->delete('tg_rate_' . self::USER);
        \Config\Services::cache()->delete('tg_rate_group_' . self::GROUP);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function groupTap(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'message'   => [
                'message_id' => $updateId,
                'from'       => ['id' => self::USER, 'is_bot' => false],
                'chat'       => ['id' => self::GROUP, 'type' => 'supergroup'],
                'text'       => 'привет всем',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function privateTap(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'message'   => [
                'message_id' => $updateId,
                'from'       => ['id' => self::USER, 'is_bot' => false],
                'chat'       => ['id' => self::USER, 'type' => 'private'],
                'text'       => '🏠 База',
            ],
        ];
    }

    /** @param array<string, mixed> $update */
    private function tap(TelegramRateLimitFilter $filter, array $update): mixed
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        return $filter->before($request);
    }

    /**
     * 🔴 Главный тест story: 100 групповых сообщений подряд от ОДНОГО from.id не
     * расходуют его персональное окно — следующий его личный игровой апдейт проходит.
     */
    public function testGroupFloodDoesNotConsumePersonalWindow(): void
    {
        $filter = new GroupScopeSpyTelegramRateLimitFilter();

        for ($i = 0; $i < 100; $i++) {
            $this->tap($filter, $this->groupTap($i));
        }

        $this->assertNull(
            $this->tap($filter, $this->privateTap(1000)),
            'Личный апдейт того же игрока обязан пройти после группового флуда'
        );
    }

    /** Групповой трафик учитывается по chat.id, отдельно от личного ключа игрока. */
    public function testGroupTrafficIsBlockedOnItsOwnKeyEventually(): void
    {
        $filter = new GroupScopeSpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::LIMIT; $i++) {
            $this->assertNull($this->tap($filter, $this->groupTap($i)), "Групповой тап #{$i} в пределах лимита");
        }

        $blocked = $this->tap($filter, $this->groupTap(self::LIMIT));

        $this->assertInstanceOf(ResponseInterface::class, $blocked);
        $this->assertSame(200, $blocked->getStatusCode());
        $this->assertSame('', (string) $blocked->getBody());
    }

    /**
     * 🔴 Non-goals story: «после этой story бот в чате нем» — отброшенный групповой
     * апдейт НЕ должен порождать никакого исходящего вызова Bot API (в отличие от
     * личного лимита, где игрока предупреждают toast'ом).
     */
    public function testBlockedGroupTrafficProducesNoOutboundCall(): void
    {
        $filter = new GroupScopeSpyTelegramRateLimitFilter();

        for ($i = 0; $i <= self::LIMIT + 50; $i++) {
            $this->tap($filter, $this->groupTap($i));
        }

        $this->assertSame([], $filter->calls, 'Групповой флуд не должен вызывать Bot API');
    }

    /** Личный лимит игрока по-прежнему считается по from.id и по-прежнему предупреждает. */
    public function testPersonalLimitStillWarnsAsBefore(): void
    {
        $filter = new GroupScopeSpyTelegramRateLimitFilter();

        for ($i = 0; $i <= self::LIMIT; $i++) {
            $this->tap($filter, $this->privateTap($i));
        }

        $this->assertCount(1, $filter->calls, 'Личный лимит по-прежнему предупреждает игрока');
    }
}
