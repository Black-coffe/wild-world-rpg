<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\LoginStreakService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-132 Ф2 — строгий триггер «день за действие» в LoginStreakService::maybeReward.
 * Гейтинг через seam-double (без БД/Telegram): strictActionTrigger + hasActivityToday.
 *
 * @internal
 */
final class LoginStreakStrictTriggerTest extends CIUnitTestCase
{
    public function testStrictOffCreditsRegardlessOfActivity(): void
    {
        // default-поведение ADR-108: засчёт на входе, активность не проверяется.
        $svc = new LoginStreakStrictStub(strict: false, activityToday: false);
        $svc->maybeReward(123, 999);
        $this->assertCount(1, $svc->persisted);
        $this->assertSame(6, $svc->persisted[0]['streak']); // 5 → 6
    }

    public function testStrictOnNoActivityDoesNotCredit(): void
    {
        // строгий режим + сегодня ничего не делал → день НЕ засчитывается.
        $svc = new LoginStreakStrictStub(strict: true, activityToday: false);
        $svc->maybeReward(123, 999);
        $this->assertSame([], $svc->persisted);
        $this->assertSame([], $svc->granted);
        $this->assertSame([], $svc->sent);
    }

    public function testStrictOnWithActivityCredits(): void
    {
        // строгий режим + есть действие сегодня → день засчитывается + награда.
        $svc = new LoginStreakStrictStub(strict: true, activityToday: true);
        $svc->maybeReward(123, 999);
        $this->assertCount(1, $svc->persisted);
        $this->assertSame(6, $svc->persisted[0]['streak']);
        $this->assertCount(1, $svc->granted);
        $this->assertSame(175, $svc->granted[0]); // 50 + 25*5
    }
}

/**
 * Тест-дабл со strict-seam'ами.
 *
 * @internal
 */
final class LoginStreakStrictStub extends LoginStreakService
{
    /** @var array<int, array{streak:int,day:string}> */
    public array $persisted = [];
    /** @var array<int, int> */
    public array $granted = [];
    /** @var array<int, array<string,mixed>> */
    public array $sent = [];

    public function __construct(private bool $strict, private bool $activityToday)
    {
    }

    protected function enabled(): bool
    {
        return true;
    }

    protected function graceDays(): int
    {
        return 1;
    }

    protected function baseGold(): int
    {
        return 50;
    }

    protected function bonusPerDay(): int
    {
        return 25;
    }

    protected function maxGold(): int
    {
        return 500;
    }

    protected function today(): string
    {
        return '2026-06-10';
    }

    protected function strictActionTrigger(): bool
    {
        return $this->strict;
    }

    protected function hasActivityToday(int $charId): bool
    {
        return $this->activityToday;
    }

    protected function resolveContext(int $telegramId): array
    {
        return ['char_id' => 491, 'streak' => 5, 'last_day' => '2026-06-09'];
    }

    protected function persist(int $charId, int $streak, string $today): void
    {
        $this->persisted[] = ['streak' => $streak, 'day' => $today];
    }

    protected function grantGold(int $charId, int $gold): void
    {
        $this->granted[] = $gold;
    }

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}
