<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Craft;

use App\Entities\ResourceEntity;
use App\Models\ResourceModel;
use App\Services\Craft\CraftCardHelper;
use App\Services\Player\ResourcePoolService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-171 — карточка крафта считает наличие тем же пулом (рюкзак + склад
 * базы), которым потом считает старт крафта `GenericCraftActionStart::checkResources()`.
 *
 * Story craft-shortfall-buy-01 — tracer. Тестовые двойники переопределяют
 * точки касания моделей/сервисов (тот же приём, что `ResourcePoolServiceTest`) —
 * тест держит только поведение помощника, не ходит в БД.
 *
 * @internal
 */
final class CraftCardHelperTest extends CIUnitTestCase
{
    /**
     * @param array<string,int> $backpack resourceId => qty
     * @param array<string,int> $storage  resourceId => qty
     * @param array<string,int> $ids      имя ресурса => id — свой `resolveResourceId()`,
     *                                    иначе `ResourcePoolService::availableByName()`
     *                                    пошёл бы в БД мимо переданного в помощник `ResourceModel`.
     */
    private function resourcePool(array $backpack, array $storage, bool $onBase, bool $poolEnabled = true, array $ids = ['Базальт' => 1]): ResourcePoolService
    {
        return new class ($backpack, $storage, $onBase, $poolEnabled, $ids) extends ResourcePoolService {
            /**
             * @param array<string,int> $backpack
             * @param array<string,int> $storage
             * @param array<string,int> $ids
             */
            public function __construct(
                private array $backpack,
                private array $storage,
                private bool $onBase,
                private bool $poolEnabled,
                private array $ids
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

            protected function resolveResourceId(string $resourceName): ?int
            {
                return $this->ids[$resourceName] ?? null;
            }
        };
    }

    /** @param array<string,array{id:int,rarity:int}> $resources имя => {id, rarity} */
    private function resourceModel(array $resources): ResourceModel
    {
        return new class ($resources) extends ResourceModel {
            /** @param array<string,array{id:int,rarity:int}> $resources */
            public function __construct(private array $resources)
            {
                parent::__construct();
            }

            public function getResourceByName($name)
            {
                if (! isset($this->resources[$name])) {
                    return null;
                }

                return new ResourceEntity($this->resources[$name] + ['name' => $name]);
            }
        };
    }

    public function testAvailableSumsBackpackAndStorageOnBase(): void
    {
        $pool  = $this->resourcePool(['1' => 10], ['1' => 40], onBase: true);
        $model = $this->resourceModel(['Базальт' => ['id' => 1, 'rarity' => 3]]);
        $helper = new CraftCardHelper($pool, $model);

        $result = $helper->available(7, ['Базальт' => 1]);

        $this->assertSame([['name' => 'Базальт', 'quantity' => 50, 'rarity' => 3]], $result);
    }

    public function testAvailableIsBackpackOnlyInTheField(): void
    {
        $pool  = $this->resourcePool(['1' => 10], ['1' => 40], onBase: false);
        $model = $this->resourceModel(['Базальт' => ['id' => 1, 'rarity' => 3]]);
        $helper = new CraftCardHelper($pool, $model);

        $result = $helper->available(7, ['Базальт' => 1]);

        $this->assertSame(10, $result[0]['quantity']);
    }

    public function testAvailableIsBackpackOnlyWhenKillswitchOff(): void
    {
        $pool  = $this->resourcePool(['1' => 10], ['1' => 40], onBase: true, poolEnabled: false);
        $model = $this->resourceModel(['Базальт' => ['id' => 1, 'rarity' => 3]]);
        $helper = new CraftCardHelper($pool, $model);

        $result = $helper->available(7, ['Базальт' => 1]);

        $this->assertSame(10, $result[0]['quantity']);
    }

    public function testAvailableTreatsUnknownResourceAsZero(): void
    {
        $pool  = $this->resourcePool([], [], onBase: true);
        $model = $this->resourceModel([]);
        $helper = new CraftCardHelper($pool, $model);

        $result = $helper->available(7, ['Неизвестное' => 5]);

        $this->assertSame([['name' => 'Неизвестное', 'quantity' => 0, 'rarity' => 0]], $result);
    }

    public function testFallbackButtonPointsToQuantityOneOfTheSameRecipe(): void
    {
        $helper = new CraftCardHelper($this->resourcePool([], [], false), $this->resourceModel([]));

        $btn = $helper->fallbackButton('LumberjackAxe');

        $this->assertSame('genericCraft_LumberjackAxe_1', $btn['callback_data']);
        $this->assertNotSame('', $btn['text']);
    }
}
