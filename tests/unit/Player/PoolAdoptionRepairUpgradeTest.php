<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Controllers\Telegram\Commands\Actions\Craft\Repair\RepairCraftedItemAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ResourceModel;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeApplier;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeValidator;
use App\Services\Player\PlayerStateService;
use App\Services\Player\ResourcePoolService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Story storage-craft-insurance-06 (ADR-171) — тот же класс бага, что и крафт
 * (`tests\unit\Craft\CraftPoolConsumptionTest.php`, story -05): ремонт и апгрейд
 * построек читали и списывали ресурсы только из рюкзака, даже стоя на складе с
 * сырьём. Ревью-раунд добавил вторую находку: `where()->first()` на
 * переиспользуемой `ResourceModel` внутри цикла по нескольким ресурсам —
 * CI4 Model builder state не гарантированно сбрасывается между вызовами (см.
 * memory `ci4-model-builder-state-quirk`) — все три места резолвят id ОДНИМ
 * `whereIn()`-запросом до цикла.
 *
 * `RepairCraftedItemAction`/`BuildingUpgradeApplier` строятся вокруг Telegram-
 * объектов/приватных полей — `newInstanceWithoutConstructor` + рефлексия на
 * `resourcePool`/`resourceModel` (тот же приём, что `CraftPoolConsumptionTest`).
 * `BuildingUpgradeValidator` конструктор публичный nullable-default — двойники
 * заходят напрямую через него.
 */
final class PoolAdoptionRepairUpgradeTest extends CIUnitTestCase
{
    public const RES_ID   = 5;
    public const RES_NAME = 'Ткань';
    public const RES_EN   = 'cloth';

    /** Общий id-based двойник пула — все три вызывающих (Repair/Validator/Applier) теперь зовут `available()`/`consume()` по id, не по имени. */
    private function poolDouble(int $backpack, int $storage, bool $onBase): ResourcePoolService
    {
        return new class ($backpack, $storage, $onBase) extends ResourcePoolService {
            /** @var array<int,array{0:int,1:int,2:int,3:int}> */
            public array $consumeCalls = [];

            public function __construct(private int $backpack, private int $storage, private bool $onBase)
            {
                parent::__construct();
            }

            public function available(int $characterId, int $resourceId): int
            {
                return $this->backpack + ($this->onBase ? $this->storage : 0);
            }

            /** @return array{backpack:int, storage:int} */
            public function consume(int $characterId, int $resourceId, int $qty): array
            {
                $have = $this->backpack + ($this->onBase ? $this->storage : 0);
                if ($have < $qty) {
                    throw new \RuntimeException("Недостаточно ресурса #{$resourceId}: нужно {$qty}, доступно {$have}");
                }

                $fromBackpack   = min($this->backpack, $qty);
                $fromStorage    = $qty - $fromBackpack;
                $this->backpack -= $fromBackpack;
                $this->storage  -= $fromStorage;
                $this->consumeCalls[] = [$resourceId, $qty, $fromBackpack, $fromStorage];

                return ['backpack' => $fromBackpack, 'storage' => $fromStorage];
            }
        };
    }

    /**
     * Один ресурс — `id`/`name`/`name_en` разом, чтобы обслуживать все три
     * вызывающих. `findAll()` отдаёт настоящую `ResourceEntity` (не голый
     * массив) — тот же контракт, что и у реального `ResourceModel`, чтобы
     * production-код (`$row->toArray()`, без defensive is_object-проверки)
     * не подстраивался под тестовый двойник.
     */
    private function resourceModelDouble(): ResourceModel
    {
        return new class () extends ResourceModel {
            public function whereIn($key = null, $values = null, ?bool $escape = null)
            {
                return $this;
            }

            public function findAll(?int $limit = null, int $offset = 0)
            {
                return [new \App\Entities\ResourceEntity([
                    'id'      => PoolAdoptionRepairUpgradeTest::RES_ID,
                    'name'    => PoolAdoptionRepairUpgradeTest::RES_NAME,
                    'name_en' => PoolAdoptionRepairUpgradeTest::RES_EN,
                ])];
            }
        };
    }

    // ── RepairCraftedItemAction ──────────────────────────────────────────

    private function repairAction(ResourcePoolService $pool): RepairCraftedItemAction
    {
        $ref = new ReflectionClass(RepairCraftedItemAction::class);
        /** @var RepairCraftedItemAction $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        $poolProp = $ref->getProperty('resourcePool');
        $poolProp->setAccessible(true);
        $poolProp->setValue($instance, $pool);

        // `resourceModel` — унаследовано от BaseAction, конструктор которого
        // тут не выполняется (newInstanceWithoutConstructor) — подставляем сами.
        $resProp = $ref->getProperty('resourceModel');
        $resProp->setAccessible(true);
        $resProp->setValue($instance, $this->resourceModelDouble());

        return $instance;
    }

    /** @param array<int,mixed> $args @return mixed */
    private function callPrivate(object $instance, string $method, array $args)
    {
        $ref = new ReflectionClass($instance);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($instance, ...$args);
    }

    /** На базе: рюкзак пуст, склад покрывает стоимость ремонта целиком — нехватки нет. */
    public function testCheckResourceAvailabilityPassesWhenStorageCoversOnBase(): void
    {
        $pool     = $this->poolDouble(backpack: 0, storage: 100, onBase: true);
        $instance = $this->repairAction($pool);

        $result = $this->callPrivate($instance, 'checkResourceAvailability', [1, [self::RES_NAME => 10]]);

        $this->assertSame(100, $result['have'][self::RES_NAME]);
        $this->assertSame([], $result['short']);
    }

    /** Вне базы: рюкзак пуст, склад полон — прежнее поведение, отказ. */
    public function testCheckResourceAvailabilityFailsOffBaseEvenWithFullStorage(): void
    {
        $pool     = $this->poolDouble(backpack: 0, storage: 100, onBase: false);
        $instance = $this->repairAction($pool);

        $result = $this->callPrivate($instance, 'checkResourceAvailability', [1, [self::RES_NAME => 10]]);

        $this->assertSame(0, $result['have'][self::RES_NAME]);
        $this->assertSame([self::RES_NAME => 10], $result['short']);
    }

    /** Отказ по нехватке называет суммарный остаток пула, а не карманный. */
    public function testCheckResourceAvailabilityShortReflectsPooledTotal(): void
    {
        $pool     = $this->poolDouble(backpack: 3, storage: 4, onBase: true);
        $instance = $this->repairAction($pool);

        $result = $this->callPrivate($instance, 'checkResourceAvailability', [1, [self::RES_NAME => 10]]);

        $this->assertSame(7, $result['have'][self::RES_NAME]);
        $this->assertSame([self::RES_NAME => 3], $result['short']);
    }

    /** Списание на базе идёт сначала из рюкзака, остаток — со склада. */
    public function testDeductResourcesDrainsBackpackThenStorageOnBase(): void
    {
        $pool     = $this->poolDouble(backpack: 3, storage: 100, onBase: true);
        $instance = $this->repairAction($pool);

        $this->callPrivate($instance, 'deductResources', [1, [self::RES_NAME => 10]]);

        $this->assertSame([[self::RES_ID, 10, 3, 7]], $pool->consumeCalls);
    }

    /** Вне базы списание не трогает склад, даже если он полон. */
    public function testDeductResourcesNeverTouchesStorageOffBase(): void
    {
        $pool     = $this->poolDouble(backpack: 20, storage: 100, onBase: false);
        $instance = $this->repairAction($pool);

        $this->callPrivate($instance, 'deductResources', [1, [self::RES_NAME => 10]]);

        $this->assertSame([[self::RES_ID, 10, 10, 0]], $pool->consumeCalls);
    }

    /** Гонка — `consume()` бросает; `deductResources()` пробрасывает исключение (не глушит). */
    public function testDeductResourcesPropagatesRuntimeExceptionOnRace(): void
    {
        $pool     = $this->poolDouble(backpack: 0, storage: 0, onBase: true);
        $instance = $this->repairAction($pool);

        $this->expectException(\RuntimeException::class);
        $this->callPrivate($instance, 'deductResources', [1, [self::RES_NAME => 10]]);
    }

    // ── BuildingUpgradeValidator ──────────────────────────────────────────

    private function characterBuildingModelDouble(array $row): CharacterBuildingModel
    {
        return new class ($row) extends CharacterBuildingModel {
            public function __construct(private array $row)
            {
            }

            public function where($key = null, $value = null, ?bool $escape = null)
            {
                return $this;
            }

            public function first()
            {
                return $this->row;
            }
        };
    }

    private function buildingModelDouble(array $row): BuildingModel
    {
        return new class ($row) extends BuildingModel {
            public function __construct(private array $row)
            {
            }

            public function find($id = null)
            {
                return $this->row;
            }
        };
    }

    private function playerStateServiceDouble(bool $onBase): PlayerStateService
    {
        return new class ($onBase) extends PlayerStateService {
            public function __construct(private bool $onBase)
            {
            }

            public function isCharacterOnBase(int $characterId): bool
            {
                return $this->onBase;
            }
        };
    }

    private function validator(ResourcePoolService $pool, array $charBuilding, array $buildingInfo): BuildingUpgradeValidator
    {
        return new BuildingUpgradeValidator(
            $this->characterBuildingModelDouble($charBuilding),
            $this->buildingModelDouble($buildingInfo),
            $this->resourceModelDouble(),
            $this->playerStateServiceDouble(true), // "на базе" гейт (шаг 1) — не то же самое, что pooled-статус самого пула
            $pool
        );
    }

    /** На базе: рюкзак пуст, склад покрывает требование апгрейда целиком — проходит. */
    public function testValidateResourcesPassWhenStorageCoversOnBase(): void
    {
        $pool      = $this->poolDouble(backpack: 0, storage: 100, onBase: true);
        $validator = $this->validator(
            $pool,
            charBuilding: ['id' => 1, 'level' => 2, 'building_type' => 'workshop', 'building_id' => 10, 'character_id' => 1],
            buildingInfo: ['id' => 10, 'name_ru' => 'Мастерская', 'hp' => 0], // без name_en — пропускает GameSettings-множитель
        );
        $character    = ['id' => 1, 'level' => 10, 'gold' => 1000];
        $requirements = [3 => ['level' => 1, 'gold' => 0, 'resources' => [self::RES_EN => 10]]];

        $result = $validator->validate($character, 10, $requirements);

        $this->assertTrue($result['ok']);
    }

    /** Пул считает склад недоступным (не pooled сейчас) — отказ, как раньше, только по рюкзаку. */
    public function testValidateResourcesFailWhenPoolNotPooledEvenWithFullStorage(): void
    {
        $pool      = $this->poolDouble(backpack: 0, storage: 100, onBase: false);
        $validator = $this->validator(
            $pool,
            charBuilding: ['id' => 1, 'level' => 2, 'building_type' => 'workshop', 'building_id' => 10, 'character_id' => 1],
            buildingInfo: ['id' => 10, 'name_ru' => 'Мастерская', 'hp' => 0],
        );
        $character    = ['id' => 1, 'level' => 10, 'gold' => 1000];
        $requirements = [3 => ['level' => 1, 'gold' => 0, 'resources' => [self::RES_EN => 10]]];

        $result = $validator->validate($character, 10, $requirements);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['missingResources']);
    }

    /** Отказ по нехватке называет суммарный остаток пула (рюкзак+склад), а не карманный. */
    public function testValidateResourcesMissingLineReflectsPooledTotal(): void
    {
        $pool      = $this->poolDouble(backpack: 3, storage: 4, onBase: true);
        $validator = $this->validator(
            $pool,
            charBuilding: ['id' => 1, 'level' => 2, 'building_type' => 'workshop', 'building_id' => 10, 'character_id' => 1],
            buildingInfo: ['id' => 10, 'name_ru' => 'Мастерская', 'hp' => 0],
        );
        $character    = ['id' => 1, 'level' => 10, 'gold' => 1000];
        $requirements = [3 => ['level' => 1, 'gold' => 0, 'resources' => [self::RES_EN => 10]]];

        $result = $validator->validate($character, 10, $requirements);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('есть: 7', $result['missingResources'][0]);
    }

    // ── BuildingUpgradeApplier ────────────────────────────────────────────

    private function applier(ResourcePoolService $pool, ResourceModel $resourceModel): BuildingUpgradeApplier
    {
        $ref = new ReflectionClass(BuildingUpgradeApplier::class);
        /** @var BuildingUpgradeApplier $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        $poolProp = $ref->getProperty('resourcePool');
        $poolProp->setAccessible(true);
        $poolProp->setValue($instance, $pool);

        $resProp = $ref->getProperty('resourceModel');
        $resProp->setAccessible(true);
        $resProp->setValue($instance, $resourceModel);

        return $instance;
    }

    /** Списание апгрейда на базе идёт сначала из рюкзака, остаток — со склада. */
    public function testApplierDeductResourcesDrainsBackpackThenStorageOnBase(): void
    {
        $pool     = $this->poolDouble(backpack: 3, storage: 100, onBase: true);
        $instance = $this->applier($pool, $this->resourceModelDouble());

        $this->callPrivate($instance, 'deductResources', [1, [self::RES_EN => 10]]);

        $this->assertSame([[self::RES_ID, 10, 3, 7]], $pool->consumeCalls);
    }

    /** Вне пула (не на базе) списание не трогает склад, даже если он полон. */
    public function testApplierDeductResourcesNeverTouchesStorageWhenNotPooled(): void
    {
        $pool     = $this->poolDouble(backpack: 20, storage: 100, onBase: false);
        $instance = $this->applier($pool, $this->resourceModelDouble());

        $this->callPrivate($instance, 'deductResources', [1, [self::RES_EN => 10]]);

        $this->assertSame([[self::RES_ID, 10, 10, 0]], $pool->consumeCalls);
    }

    /** Гонка — `consume()` бросает; `deductResources()` пробрасывает исключение (не глушит, не бампает уровень). */
    public function testApplierDeductResourcesPropagatesRuntimeExceptionOnRace(): void
    {
        $pool     = $this->poolDouble(backpack: 0, storage: 0, onBase: true);
        $instance = $this->applier($pool, $this->resourceModelDouble());

        $this->expectException(\RuntimeException::class);
        $this->callPrivate($instance, 'deductResources', [1, [self::RES_EN => 10]]);
    }

    /**
     * insurance-06 (ревью team-lead): отказ на ресурсах не должен списать золото
     * ни разу — `apply()` теперь считает ресурсы ПЕРВЫМ шагом, gold-adjust и
     * level-update физически не достигаются, если `deductResources()` бросил.
     * Раньше порядок был «золото → ресурсы → level»: при отказе на ресурсах
     * игрок платил бы золотом за апгрейд, который не состоялся.
     */
    public function testApplierApplyDoesNotTouchGoldOrLevelWhenResourceRaceFails(): void
    {
        $pool = $this->poolDouble(backpack: 0, storage: 0, onBase: true); // consume() гарантированно бросит

        $statsSpy = new class () extends \App\Services\Player\CharacterStatsService {
            public bool $adjustCalled = false;

            public function adjust(int $characterId, array $deltas, array $boundsOverride = []): ?array
            {
                $this->adjustCalled = true;

                return null;
            }
        };

        $buildingSpy = new class () extends CharacterBuildingModel {
            public bool $updateCalled = false;

            public function update($id = null, $data = null): bool
            {
                $this->updateCalled = true;

                return true;
            }
        };

        $ref      = new ReflectionClass(BuildingUpgradeApplier::class);
        $instance = $ref->newInstanceWithoutConstructor();

        foreach ([
            'resourcePool'          => $pool,
            'resourceModel'         => $this->resourceModelDouble(),
            'statsService'          => $statsSpy,
            'characterBuildingModel' => $buildingSpy,
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($instance, $value);
        }

        $character    = ['id' => 1, 'gold' => 1000];
        $charBuilding = ['id' => 1, 'building_type' => 'workshop', 'building_id' => 10];
        $requirements = ['level' => 1, 'gold' => 200, 'resources' => [self::RES_EN => 10]];

        $threw = false;
        try {
            $instance->apply($character, $charBuilding, 3, $requirements);
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'apply() должен пробросить RuntimeException при гонке на ресурсах');
        $this->assertFalse($statsSpy->adjustCalled, 'золото не должно списываться, если оплата ресурсами не прошла');
        $this->assertFalse($buildingSpy->updateCalled, 'уровень постройки не должен меняться, если оплата ресурсами не прошла');
    }
}
