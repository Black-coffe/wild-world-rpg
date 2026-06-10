<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\LoginStreakService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ROADMAP-100 E6 (ADR-108) Фаза 3 — LoginStreakService.
 * Чистые computeStreak/computeReward/buildText + streakLine + гейтинг maybeReward
 * через seam-double (без БД/Telegram).
 *
 * @internal
 */
final class LoginStreakServiceTest extends CIUnitTestCase
{
    // ── computeStreak ─────────────────────────────────────────────────────────

    public function testStreakFirstEverIsOne(): void
    {
        $this->assertSame(1, LoginStreakService::computeStreak(null, '2026-06-10', 0, 1));
        $this->assertSame(1, LoginStreakService::computeStreak('', '2026-06-10', 0, 1));
    }

    public function testStreakConsecutiveIncrements(): void
    {
        $this->assertSame(6, LoginStreakService::computeStreak('2026-06-09', '2026-06-10', 5, 1));
    }

    public function testStreakWithinGraceContinues(): void
    {
        // Пропущен 1 день (gap=2), grace=1 → серия продолжается.
        $this->assertSame(6, LoginStreakService::computeStreak('2026-06-08', '2026-06-10', 5, 1));
    }

    public function testStreakBeyondGraceResets(): void
    {
        // Пропущено 2 дня (gap=3), grace=1 → сброс к 1.
        $this->assertSame(1, LoginStreakService::computeStreak('2026-06-07', '2026-06-10', 5, 1));
    }

    public function testStreakStrictGraceZero(): void
    {
        // grace=0: подряд → +1, любой пропуск → 1.
        $this->assertSame(6, LoginStreakService::computeStreak('2026-06-09', '2026-06-10', 5, 0));
        $this->assertSame(1, LoginStreakService::computeStreak('2026-06-08', '2026-06-10', 5, 0));
    }

    public function testStreakSameDayUnchanged(): void
    {
        // daysSince=0 (защита от обратного хода часов / повторного вызова) → без изменений.
        $this->assertSame(5, LoginStreakService::computeStreak('2026-06-10', '2026-06-10', 5, 1));
        $this->assertSame(1, LoginStreakService::computeStreak('2026-06-11', '2026-06-10', 0, 1));
    }

    // ── computeReward ─────────────────────────────────────────────────────────

    public function testRewardDayOneIsBase(): void
    {
        $this->assertSame(50, LoginStreakService::computeReward(1, 50, 25, 500));
    }

    public function testRewardGrowsLinearly(): void
    {
        $this->assertSame(100, LoginStreakService::computeReward(3, 50, 25, 500)); // 50 + 25*2
    }

    public function testRewardCappedAtMax(): void
    {
        $this->assertSame(500, LoginStreakService::computeReward(19, 50, 25, 500)); // 50+25*18=500
        $this->assertSame(500, LoginStreakService::computeReward(40, 50, 25, 500)); // capped
    }

    public function testRewardNoCapWhenMaxZero(): void
    {
        $this->assertSame(550, LoginStreakService::computeReward(21, 50, 25, 0));
    }

    public function testRewardZeroBonus(): void
    {
        $this->assertSame(50, LoginStreakService::computeReward(10, 50, 0, 500));
    }

    // ── buildText ─────────────────────────────────────────────────────────────

    public function testBuildTextDayOne(): void
    {
        $t = LoginStreakService::buildText(1, 50);
        $this->assertStringContainsString('Новый день', $t);
        $this->assertStringContainsString('💰 *50*', $t);
    }

    public function testBuildTextStreak(): void
    {
        $t = LoginStreakService::buildText(7, 200);
        $this->assertStringContainsString('Серия входов: 7 дней подряд', $t);
        $this->assertStringContainsString('💰 *200*', $t);
    }

    // ── streakLine + maybeReward gating (seam double) ────────────────────────

    public function testStreakLineNullWhenDisabled(): void
    {
        $svc = new LoginStreakStub(false, '2026-06-09', 5, 0);
        $this->assertNull($svc->streakLine(['login_streak' => 5]));
    }

    public function testStreakLineNullWhenZero(): void
    {
        $svc = new LoginStreakStub(true, '2026-06-09', 0, 0);
        $this->assertNull($svc->streakLine(['login_streak' => 0]));
    }

    public function testStreakLineShows(): void
    {
        $svc = new LoginStreakStub(true, '2026-06-09', 5, 0);
        $this->assertSame('🔥 *Серия входов:* 5 дней', $svc->streakLine(['login_streak' => 5]));
    }

    public function testGatingDormant(): void
    {
        $svc = new LoginStreakStub(false, '2026-06-09', 5, 0);
        $svc->maybeReward(123, 123);
        $this->assertSame([], $svc->persisted);
        $this->assertSame([], $svc->sent);
    }

    public function testGatingSameDayNoOp(): void
    {
        $svc = new LoginStreakStub(true, '2026-06-10', 5, 0);
        $svc->today = '2026-06-10';
        $svc->maybeReward(123, 123);
        $this->assertSame([], $svc->persisted);
    }

    public function testGatingNewDayRewardsAndPersists(): void
    {
        $svc = new LoginStreakStub(true, '2026-06-09', 5, 0);
        $svc->today = '2026-06-10';
        $svc->maybeReward(123, 999);
        $this->assertCount(1, $svc->persisted);
        $this->assertSame(6, $svc->persisted[0]['streak']);          // 5 → 6
        $this->assertSame('2026-06-10', $svc->persisted[0]['day']);
        $this->assertCount(1, $svc->granted);
        $this->assertSame(175, $svc->granted[0]);                    // 50 + 25*5
        $this->assertCount(1, $svc->sent);
        $this->assertSame(999, $svc->sent[0]['chat_id']);
    }
}

/**
 * Тест-дабл LoginStreakService: seam'ы подменены, побочки копятся.
 *
 * @internal
 */
final class LoginStreakStub extends LoginStreakService
{
    /** @var array<int, array{streak:int,day:string}> */
    public array $persisted = [];
    /** @var array<int, int> */
    public array $granted = [];
    /** @var array<int, array<string,mixed>> */
    public array $sent = [];
    public string $today = '2026-06-10';

    public function __construct(
        private bool $en,
        private ?string $lastDay,
        private int $streak,
        private int $grace,
    ) {
    }

    protected function enabled(): bool
    {
        return $this->en;
    }

    protected function graceDays(): int
    {
        return $this->grace;
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
        return $this->today;
    }

    protected function resolveContext(int $telegramId): array
    {
        return ['char_id' => 491, 'streak' => $this->streak, 'last_day' => $this->lastDay];
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
