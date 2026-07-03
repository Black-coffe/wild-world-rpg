<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\StarterKitService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-104 Фаза 1 — «Стартовый набор выжившего».
 *
 * Оркестрацию (killswitch / idempotency / состав / текст) проверяем через
 * test-double, подменяющий GameSettings/DB-seam'ы детерминированными значениями
 * (memory feedback_taskhandler_telegram_init_in_tests: без БД на CI).
 *
 * @internal
 */
final class StarterKitServiceTest extends CIUnitTestCase
{
    public function testKillswitchOffIsNoOp(): void
    {
        $svc = new FakeStarterKitService();
        $svc->enabled = false;

        $this->assertNull($svc->grant(1, 10, 555));
        $this->assertSame([], $svc->granted);
        $this->assertSame([], $svc->flags);
    }

    public function testZeroCharIdIsNoOp(): void
    {
        $svc = new FakeStarterKitService();
        $this->assertNull($svc->grant(0, 10, 555));
        $this->assertSame([], $svc->granted);
    }

    public function testHappyPathGrantsAllResourcesAndFlag(): void
    {
        $svc = new FakeStarterKitService();
        $text = $svc->grant(1, 10, 555);

        $this->assertNotNull($text);
        // 6 ресурсов набора (water/wood/bark/pebble/herbs/algae) выданы.
        $this->assertCount(6, $svc->granted);
        $this->assertSame([17, 10], $svc->granted[0]); // water id 17 ×10
        $this->assertSame([2, 10], $svc->granted[1]);  // wood id 2 ×10
        $this->assertSame([8, 8], $svc->granted[2]);   // bark id 8 ×8
        $this->assertSame([19, 8], $svc->granted[3]);  // pebble id 19 ×8
        $this->assertSame([3, 4], $svc->granted[4]);   // herbs id 3 ×4 (инпут Повязки)
        $this->assertSame([20, 6], $svc->granted[5]);  // algae id 20 ×6 (инпут Повязки)
        // idempotency-флаг записан ровно один раз.
        $this->assertSame([1], $svc->flags);
    }

    public function testTextIsMediaOffComplete(): void
    {
        $svc  = new FakeStarterKitService();
        $text = (string) $svc->grant(1, 10, 555);

        // Весь смысл в тексте: названия + количества (MEDIA-OFF, ADR-020).
        $this->assertStringContainsString('Вода', $text);
        $this->assertStringContainsString('×10', $text);
        $this->assertStringContainsString('Галька', $text);
        $this->assertStringContainsString('Перс', $text);
    }

    public function testIdempotentSecondCallIsNoOp(): void
    {
        $svc = new FakeStarterKitService();
        $this->assertNotNull($svc->grant(1, 10, 555));
        // Второй раз — флаг уже стоит → no-op (защита от пере-выдачи).
        $this->assertNull($svc->grant(1, 10, 555));
        $this->assertCount(6, $svc->granted);
        $this->assertSame([1], $svc->flags);
    }

    public function testZeroAmountResourceSkipped(): void
    {
        $svc = new FakeStarterKitService();
        $svc->amounts['onboarding.starter_kit.water'] = 0; // воду не кладём

        $text = $svc->grant(1, 10, 555);
        $this->assertNotNull($text);
        $this->assertCount(5, $svc->granted); // только wood/bark/pebble/herbs/algae
        $this->assertStringNotContainsString('Вода', (string) $text);
    }

    public function testAllZeroAmountsIsNoOp(): void
    {
        $svc = new FakeStarterKitService();
        foreach (array_keys($svc->amounts) as $k) {
            $svc->amounts[$k] = 0;
        }
        $this->assertNull($svc->grant(1, 10, 555));
        $this->assertSame([], $svc->granted);
        $this->assertSame([], $svc->flags); // флаг не пишем, если нечего выдавать
    }
}

/**
 * Test-double: подменяет GameSettings/DB-seam'ы детерминированными значениями.
 *
 * @internal
 */
final class FakeStarterKitService extends StarterKitService
{
    public bool $enabled = true;

    /** @var array<string,int> */
    public array $amounts = [
        'onboarding.starter_kit.water'  => 10,
        'onboarding.starter_kit.wood'   => 10,
        'onboarding.starter_kit.bark'   => 8,
        'onboarding.starter_kit.pebble' => 8,
        'onboarding.starter_kit.herbs'  => 4,
        'onboarding.starter_kit.algae'  => 6,
    ];

    /** @var list<array{0:int,1:int}> [resourceId, amount] */
    public array $granted = [];
    /** @var list<int> charId'ы, которым записан флаг */
    public array $flags = [];

    protected function enabled(): bool
    {
        return $this->enabled;
    }

    protected function amount(string $key): int
    {
        return max(0, $this->amounts[$key] ?? 0);
    }

    protected function alreadyGranted(int $charId): bool
    {
        return in_array($charId, $this->flags, true);
    }

    protected function grantResource(int $charId, int $resourceId, int $amount): void
    {
        $this->granted[] = [$resourceId, $amount];
    }

    protected function writeGrantedFlag(int $charId, int $chatId): void
    {
        $this->flags[] = $charId;
    }
}
