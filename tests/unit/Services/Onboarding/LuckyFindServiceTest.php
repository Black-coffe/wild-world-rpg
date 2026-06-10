<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\LuckyFindService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-104 Фаза 3b — гарантированный «момент удачи» на первый ход новичка.
 *
 * Оркестрацию (killswitch / level / idempotency / состав / текст) — через test-double,
 * подменяющий GameSettings/DB-seam'ы (без БД/Telegram на CI).
 *
 * @internal
 */
final class LuckyFindServiceTest extends CIUnitTestCase
{
    public function testKillswitchOffIsNoOp(): void
    {
        $svc = new FakeLuckyFindService();
        $svc->enabled = false;
        $this->assertFalse($svc->maybeGrantFirstMove(['id' => 1, 'level' => 1], 555));
        $this->assertSame(0, $svc->goldGranted);
        $this->assertSame([], $svc->resGranted);
        $this->assertSame([], $svc->flags);
        $this->assertSame([], $svc->sent);
    }

    public function testLevelGateSuppresses(): void
    {
        $svc = new FakeLuckyFindService();
        $this->assertFalse($svc->maybeGrantFirstMove(['id' => 1, 'level' => 7], 555));
        $this->assertSame(0, $svc->goldGranted);
        $this->assertSame([], $svc->flags);
    }

    public function testZeroCharIdSuppresses(): void
    {
        $svc = new FakeLuckyFindService();
        $this->assertFalse($svc->maybeGrantFirstMove(['id' => 0, 'level' => 1], 555));
        $this->assertSame([], $svc->flags);
    }

    public function testHappyPathGrantsGoldResourceFlagAndMessage(): void
    {
        $svc = new FakeLuckyFindService();
        $this->assertTrue($svc->maybeGrantFirstMove(['id' => 1, 'level' => 1], 555));
        $this->assertSame(300, $svc->goldGranted);
        $this->assertSame([[72, 5]], $svc->resGranted); // Железная руда id 72 ×5
        $this->assertSame([1], $svc->flags);
        $this->assertCount(1, $svc->sent);
    }

    public function testTextIsMediaOffComplete(): void
    {
        $svc = new FakeLuckyFindService();
        $svc->maybeGrantFirstMove(['id' => 1, 'level' => 1], 555);
        $text = (string) ($svc->sent[0]['text'] ?? '');
        $this->assertStringContainsString('находка', mb_strtolower($text));
        $this->assertStringContainsString('×300', $text);
        $this->assertStringContainsString('Железная руда', $text);
    }

    public function testIdempotentSecondCallNoOp(): void
    {
        $svc = new FakeLuckyFindService();
        $this->assertTrue($svc->maybeGrantFirstMove(['id' => 1, 'level' => 1], 555));
        $this->assertFalse($svc->maybeGrantFirstMove(['id' => 1, 'level' => 1], 555));
        $this->assertSame(300, $svc->goldGranted); // не удвоилось
        $this->assertSame([1], $svc->flags);
    }

    public function testAllZeroAmountsNoOpNoFlag(): void
    {
        $svc = new FakeLuckyFindService();
        $svc->gold      = 0;
        $svc->ironstone = 0;
        $this->assertFalse($svc->maybeGrantFirstMove(['id' => 1, 'level' => 1], 555));
        $this->assertSame(0, $svc->goldGranted);
        $this->assertSame([], $svc->resGranted);
        $this->assertSame([], $svc->flags); // флаг не пишем, если нечего выдавать
    }

    public function testGoldOnlyWhenIronstoneZero(): void
    {
        $svc = new FakeLuckyFindService();
        $svc->ironstone = 0;
        $this->assertTrue($svc->maybeGrantFirstMove(['id' => 1, 'level' => 1], 555));
        $this->assertSame(300, $svc->goldGranted);
        $this->assertSame([], $svc->resGranted);
        $this->assertStringNotContainsString('Железная руда', (string) $svc->sent[0]['text']);
    }
}

/**
 * Test-double: детерминированные GameSettings/DB/Telegram-seam'ы.
 *
 * @internal
 */
final class FakeLuckyFindService extends LuckyFindService
{
    public bool $enabled = true;
    public int $maxLvl = 6;
    public int $gold = 300;
    public int $ironstone = 5;

    public int $goldGranted = 0;
    /** @var list<array{0:int,1:int}> */
    public array $resGranted = [];
    /** @var list<int> */
    public array $flags = [];
    /** @var list<array<string,mixed>> */
    public array $sent = [];

    protected function enabled(): bool
    {
        return $this->enabled;
    }

    protected function maxLevel(): int
    {
        return $this->maxLvl;
    }

    protected function amount(string $key, int $default): int
    {
        if ($key === 'onboarding.lucky_find.gold') {
            return max(0, $this->gold);
        }
        if ($key === 'onboarding.lucky_find.ironstone') {
            return max(0, $this->ironstone);
        }
        return $default;
    }

    protected function alreadyGranted(int $charId): bool
    {
        return in_array($charId, $this->flags, true);
    }

    protected function grantGold(int $charId, int $gold): void
    {
        $this->goldGranted += $gold;
    }

    protected function grantResource(int $charId, int $resourceId, int $amount): void
    {
        $this->resGranted[] = [$resourceId, $amount];
    }

    protected function writeFlag(int $charId, int $chatId): void
    {
        $this->flags[] = $charId;
    }

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}
