<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Services\Player\ResourcePoolService;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * ADR-171 (story storage-craft-insurance-01) — единый пул «рюкзак + склад базы».
 *
 * Тестовый двойник переопределяет все точки касания моделей/сервисов, поэтому
 * тест держит только бизнес-логику (порядок трат, killswitch, гейт «на базе»)
 * и не ходит в БД (memory `feedback_db_clock_seed_not_php_in_time_window_tests`
 * — тут время ни при чём, но принцип тот же: не завязываться на живую БД там,
 * где логику можно проверить чистой).
 */
final class ResourcePoolServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string,int> $backpack  resourceId => qty
     * @param array<string,int> $storage   resourceId => qty
     */
    private function service(array $backpack, array $storage, bool $onBase, bool $poolEnabled = true): ResourcePoolService
    {
        return new class ($backpack, $storage, $onBase, $poolEnabled) extends ResourcePoolService {
            public array $backpackWithdrawals = [];
            public array $storageWithdrawals  = [];

            /**
             * @param array<string,int> $backpack
             * @param array<string,int> $storage
             */
            public function __construct(
                private array $backpack,
                private array $storage,
                private bool $onBase,
                private bool $poolEnabled
            ) {
                parent::__construct();
            }

            protected function isPooled(int $characterId): bool
            {
                return $this->poolEnabled && $this->onBase;
            }

            protected function backpackQuantity(int $characterId, int $resourceId): int
            {
                return $this->backpack[$resourceId] ?? 0;
            }

            protected function storageQuantity(int $characterId, int $resourceId): int
            {
                return $this->storage[$resourceId] ?? 0;
            }

            protected function withdrawBackpack(int $characterId, int $resourceId, int $qty): void
            {
                $this->backpackWithdrawals[] = [$resourceId, $qty];
                $this->backpack[$resourceId] = ($this->backpack[$resourceId] ?? 0) - $qty;
            }

            protected function withdrawStorage(int $characterId, int $resourceId, int $qty): void
            {
                $this->storageWithdrawals[] = [$resourceId, $qty];
                $this->storage[$resourceId] = ($this->storage[$resourceId] ?? 0) - $qty;
            }
        };
    }

    public function testAvailableOffBaseIsBackpackOnly(): void
    {
        $svc = $this->service(['5' => 10], ['5' => 1000], onBase: false);

        $this->assertSame(10, $svc->available(1, 5));
    }

    public function testAvailableOnBaseSumsBackpackAndStorage(): void
    {
        $svc = $this->service(['5' => 10], ['5' => 1000], onBase: true);

        $this->assertSame(1010, $svc->available(1, 5));
    }

    public function testBreakdownReportsStorageEvenWhenNotPooled(): void
    {
        $svc = $this->service(['5' => 10], ['5' => 1000], onBase: false);

        $b = $svc->breakdown(1, 5);

        $this->assertSame(10, $b['backpack']);
        $this->assertSame(1000, $b['storage']);
        $this->assertFalse($b['pooled']);
    }

    public function testConsumeDrainsBackpackFirstThenStorage(): void
    {
        $svc = $this->service(['5' => 10], ['5' => 1000], onBase: true);

        $spent = $svc->consume(1, 5, 60);

        $this->assertSame(['backpack' => 10, 'storage' => 50], $spent);
    }

    public function testConsumeStaysInBackpackWhenEnough(): void
    {
        $svc = $this->service(['5' => 100], ['5' => 1000], onBase: true);

        $spent = $svc->consume(1, 5, 30);

        $this->assertSame(['backpack' => 30, 'storage' => 0], $spent);
    }

    public function testConsumeThrowsAndTakesNothingOnShortage(): void
    {
        $svc = $this->service(['5' => 10], ['5' => 5], onBase: true);

        try {
            $svc->consume(1, 5, 100);
            $this->fail('ожидался RuntimeException при нехватке');
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame([], $svc->backpackWithdrawals);
        $this->assertSame([], $svc->storageWithdrawals);
    }

    /** Killswitch выключен — склад не участвует, даже стоя на базе с полным складом. */
    public function testKillswitchOffMeansBackpackOnlyEvenOnBase(): void
    {
        $svc = $this->service(['5' => 10], ['5' => 1000], onBase: true, poolEnabled: false);

        $this->assertSame(10, $svc->available(1, 5));

        try {
            $svc->consume(1, 5, 60);
            $this->fail('ожидался RuntimeException — склад выключен killswitch\'ем');
        } catch (RuntimeException $e) {
            // expected
        }
    }

    /** Не на базе — недостача, даже если на складе этого добра горы. */
    public function testConsumeThrowsOffBaseEvenWithFullStorage(): void
    {
        $svc = $this->service(['5' => 10], ['5' => 1000], onBase: false);

        try {
            $svc->consume(1, 5, 60);
            $this->fail('ожидался RuntimeException — склад недоступен вне базы');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Недостаточно', $e->getMessage());
        }
    }
}
