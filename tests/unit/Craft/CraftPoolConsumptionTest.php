<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart;
use App\Models\ResourceModel;
use App\Services\Player\ResourcePoolService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Story storage-craft-insurance-05 (ADR-171) — жалоба Анжелы: «крафт ингредиенты
 * доступны только из рюкзака, даже когда я стою на складе, где их тысячи».
 *
 * `GenericCraftActionStart` строится вокруг `LongmanTelegramBot\Entities\CallbackQuery`,
 * что делает конструктор непригодным для чистого unit-теста. Поэтому тест обходит
 * конструктор (`newInstanceWithoutConstructor`) и через рефлексию подставляет
 * тестовые двойники `resourcePool`/`resourceModel` — тот же приём seam'ов, каким
 * уже пользуется `ResourcePoolServiceTest` для самого пула. Проверяется бизнес-логика
 * двух приватных методов, а не Telegram-обвязка вокруг них.
 */
final class CraftPoolConsumptionTest extends CIUnitTestCase
{
    public const RES_ID = 5;
    public const RES_NAME = 'Дерево';
    public const RES_ID_2 = 6;
    public const RES_NAME_2 = 'Камень';

    /**
     * @param array<int,int> $backpack resourceId => qty; @param array<int,int> $storage resourceId => qty
     * @param list<string> $raceOn имена ресурсов, для которых `consumeByName()` бросает —
     *   имитация гонки между `checkResources()` и списанием (кто-то успел забрать то же
     *   самое между проверкой и транзакцией).
     */
    private function poolDouble(array $backpack, array $storage, bool $onBase, array $raceOn = []): ResourcePoolService
    {
        return new class ($backpack, $storage, $onBase, $raceOn) extends ResourcePoolService {
            public array $storageWithdrawals  = [];
            public array $backpackWithdrawals = [];

            /**
             * @param array<int,int> $backpack
             * @param array<int,int> $storage
             * @param list<string> $raceOn
             */
            public function __construct(
                private array $backpack,
                private array $storage,
                private bool $onBase,
                private array $raceOn = []
            ) {
                parent::__construct();
            }

            public function consumeByName(int $characterId, string $resourceName, int $qty): array
            {
                if (in_array($resourceName, $this->raceOn, true)) {
                    throw new \RuntimeException("гонка: '{$resourceName}' списали параллельно между проверкой и транзакцией");
                }

                return parent::consumeByName($characterId, $resourceName, $qty);
            }

            protected function isPooled(int $characterId): bool
            {
                return $this->onBase;
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

            protected function resolveResourceId(string $resourceName): ?int
            {
                return match ($resourceName) {
                    CraftPoolConsumptionTest::RES_NAME   => CraftPoolConsumptionTest::RES_ID,
                    CraftPoolConsumptionTest::RES_NAME_2 => CraftPoolConsumptionTest::RES_ID_2,
                    default                               => null,
                };
            }
        };
    }

    private function resourceModelDouble(): ResourceModel
    {
        return new class () extends ResourceModel {
            public function getResourceByName($name)
            {
                return $name === CraftPoolConsumptionTest::RES_NAME ? ['id' => CraftPoolConsumptionTest::RES_ID] : null;
            }
        };
    }

    /** Строит GenericCraftActionStart в обход конструктора (Telegram-объект не нужен для этих методов). */
    private function action(ResourcePoolService $pool, ResourceModel $resourceModel): GenericCraftActionStart
    {
        $ref = new ReflectionClass(GenericCraftActionStart::class);
        /** @var GenericCraftActionStart $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        $poolProp = $ref->getProperty('resourcePool');
        $poolProp->setAccessible(true);
        $poolProp->setValue($instance, $pool);

        $resProp = $ref->getProperty('resourceModel');
        $resProp->setAccessible(true);
        $resProp->setValue($instance, $resourceModel);

        return $instance;
    }

    /** @return mixed */
    private function callPrivate(GenericCraftActionStart $instance, string $method, array $args)
    {
        $ref = new ReflectionClass(GenericCraftActionStart::class);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($instance, ...$args);
    }

    /** На базе: рюкзак пуст, склад покрывает требование целиком — нехватки нет. */
    public function testCheckResourcesPassesWhenStorageCoversShortfallOnBase(): void
    {
        $pool     = $this->poolDouble(backpack: [self::RES_ID => 0], storage: [self::RES_ID => 1000], onBase: true);
        $instance = $this->action($pool, $this->resourceModelDouble());

        $missing = $this->callPrivate($instance, 'checkResources', [1, [self::RES_NAME => 5], 2]);

        $this->assertSame([], $missing, 'на базе рюкзак+склад суммируются, 10 нужно, 1000 на складе — хватает');
    }

    /**
     * Вне базы: рюкзак пуст, склад полон — проверка ОБЯЗАНА провалиться (прежнее
     * поведение), но обязана вернуть storage-подсказку для экрана нехватки.
     */
    public function testCheckResourcesFailsOffBaseButReportsStorage(): void
    {
        $pool     = $this->poolDouble(backpack: [self::RES_ID => 0], storage: [self::RES_ID => 1000], onBase: false);
        $instance = $this->action($pool, $this->resourceModelDouble());

        $missing = $this->callPrivate($instance, 'checkResources', [1, [self::RES_NAME => 5], 2]);

        $this->assertArrayHasKey(self::RES_NAME, $missing);
        $this->assertSame(0, $missing[self::RES_NAME]['have']);
        $this->assertSame(1000, $missing[self::RES_NAME]['storage']);
        $this->assertFalse($missing[self::RES_NAME]['pooled']);
    }

    /** На базе: списание идёт сначала из рюкзака, остаток — со склада, обе точки через пул. */
    public function testSubtractResourcesDrainsBackpackThenStorageOnBase(): void
    {
        $pool     = $this->poolDouble(backpack: [self::RES_ID => 3], storage: [self::RES_ID => 1000], onBase: true);
        $instance = $this->action($pool, $this->resourceModelDouble());

        $this->callPrivate($instance, 'subtractResources', [1, [self::RES_NAME => 5], 2]); // need = 10

        /** @var array $withdrawalsBackpack */
        $withdrawalsBackpack = $pool->backpackWithdrawals;
        /** @var array $withdrawalsStorage */
        $withdrawalsStorage = $pool->storageWithdrawals;
        $this->assertSame([[self::RES_ID, 3]], $withdrawalsBackpack);
        $this->assertSame([[self::RES_ID, 7]], $withdrawalsStorage);
    }

    /** Вне базы поведение прежнее: списание не трогает склад, даже если он полон. */
    public function testSubtractResourcesNeverTouchesStorageOffBase(): void
    {
        $pool     = $this->poolDouble(backpack: [self::RES_ID => 20], storage: [self::RES_ID => 1000], onBase: false);
        $instance = $this->action($pool, $this->resourceModelDouble());

        $this->callPrivate($instance, 'subtractResources', [1, [self::RES_NAME => 5], 2]); // need = 10

        $this->assertSame([[self::RES_ID, 10]], $pool->backpackWithdrawals);
        $this->assertSame([], $pool->storageWithdrawals);
    }

    /**
     * Гонка между `checkResources()` и списанием (кто-то параллельно забрал тот же
     * остаток): `subtractResources()` ОБЯЗАН пробросить исключение, а не проглотить
     * его и продолжить крафт бесплатно. `handle()` ловит это исключение выше и
     * откатывает всю транзакцию — тут проверяется контракт метода: он не глушит гонку.
     */
    public function testSubtractResourcesPropagatesRaceInsteadOfSwallowing(): void
    {
        $pool = $this->poolDouble(
            backpack: [self::RES_ID => 100, self::RES_ID_2 => 100],
            storage: [self::RES_ID => 0, self::RES_ID_2 => 0],
            onBase: true,
            raceOn: [self::RES_NAME_2],
        );
        $instance = $this->action($pool, $this->resourceModelDouble());

        $this->expectException(\RuntimeException::class);
        try {
            // 'Дерево' первым в списке успевает списаться до того, как 'Камень' бросит —
            // это и есть частичное применение, которое в проде отменяет только откат
            // транзакции ($db->transRollback() в handle()), а не сам метод.
            $this->callPrivate($instance, 'subtractResources', [
                1,
                [self::RES_NAME => 1, self::RES_NAME_2 => 1],
                5,
            ]);
        } finally {
            $this->assertSame([[self::RES_ID, 5]], $pool->backpackWithdrawals, "'Дерево' успело списаться до гонки на 'Камне'");
            $this->assertSame([], $pool->storageWithdrawals);
        }
    }
}
