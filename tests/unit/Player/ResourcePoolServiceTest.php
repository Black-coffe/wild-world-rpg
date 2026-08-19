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
            public array $backpackRestores    = [];
            public array $storageRestores     = [];

            /** Симулирует гонку: рюкзак реально отдаёт МЕНЬШЕ, чем у него просят. null = отдаёт сколько просят. */
            public ?int $backpackShortfallTo = null;
            /** Симулирует гонку: склад реально отдаёт МЕНЬШЕ, чем у него просят. null = отдаёт сколько просят. */
            public ?int $storageShortfallTo = null;

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

            protected function withdrawBackpack(int $characterId, int $resourceId, int $qty): int
            {
                $taken = $this->backpackShortfallTo !== null ? min($qty, $this->backpackShortfallTo) : $qty;
                $this->backpackWithdrawals[] = [$resourceId, $taken];
                $this->backpack[$resourceId] = ($this->backpack[$resourceId] ?? 0) - $taken;

                return $taken;
            }

            protected function withdrawStorage(int $characterId, int $resourceId, int $qty): int
            {
                $taken = $this->storageShortfallTo !== null ? min($qty, $this->storageShortfallTo) : $qty;
                $this->storageWithdrawals[] = [$resourceId, $taken];
                $this->storage[$resourceId] = ($this->storage[$resourceId] ?? 0) - $taken;

                return $taken;
            }

            protected function restoreBackpack(int $characterId, int $resourceId, int $qty): void
            {
                $this->backpackRestores[]    = [$resourceId, $qty];
                $this->backpack[$resourceId] = ($this->backpack[$resourceId] ?? 0) + $qty;
            }

            protected function restoreStorage(int $characterId, int $resourceId, int $qty): void
            {
                $this->storageRestores[]    = [$resourceId, $qty];
                $this->storage[$resourceId] = ($this->storage[$resourceId] ?? 0) + $qty;
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

    /**
     * Story storage-craft-insurance-08: склад по своему докблоку может списать
     * МЕНЬШЕ запрошенного (гонка — параллельный запрос уже утащил часть). Раньше
     * consume() возвращал намерение, не глядя на факт. Теперь — отказ и полный
     * откат: и уже списанного рюкзака, и частично списанного склада.
     */
    public function testConsumeRollsBackEverythingWhenStorageWithdrawsLessThanPromised(): void
    {
        $svc                     = $this->service(['5' => 10], ['5' => 1000], onBase: true);
        $svc->storageShortfallTo = 40; // просили 50 (60 - 10 из рюкзака), реально отдало 40

        try {
            $svc->consume(1, 5, 60);
            $this->fail('ожидался RuntimeException при расхождении списания склада');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('складе', $e->getMessage());
        }

        $this->assertSame([[5, 10]], $svc->backpackWithdrawals, 'рюкзак был списан целиком (10)');
        $this->assertSame([[5, 10]], $svc->backpackRestores, 'и целиком откачен обратно');
        $this->assertSame([[5, 40]], $svc->storageWithdrawals, 'склад отдал частично (40 из 50)');
        $this->assertSame([[5, 40]], $svc->storageRestores, 'частично списанное со склада тоже вернулось');
    }

    /**
     * Расхождение в самом рюкзаке (двойной тап опустошил его между чтением
     * breakdown() и записью) — отказ без обращения к складу вообще: рюкзак
     * списывается одним атомарным UPDATE, поэтому либо всё, либо ничего,
     * откатывать при недостаче нечего.
     */
    public function testConsumeThrowsWhenBackpackWithdrawsLessThanPromisedAndNeverTouchesStorage(): void
    {
        $svc                      = $this->service(['5' => 10], ['5' => 1000], onBase: true);
        $svc->backpackShortfallTo = 4; // просили 10, реально осталось 4

        try {
            $svc->consume(1, 5, 60);
            $this->fail('ожидался RuntimeException при расхождении списания рюкзака');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('рюкзаке', $e->getMessage());
        }

        $this->assertSame([[5, 4]], $svc->backpackWithdrawals);
        $this->assertSame([], $svc->storageWithdrawals, 'склад не должен был тронут — отказ произошёл раньше');
        $this->assertSame([], $svc->backpackRestores, 'рюкзак атомарен: нечего откатывать');
        $this->assertSame([], $svc->storageRestores);
    }
}
