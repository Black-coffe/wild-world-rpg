<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Models\GameSettingsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\PVE\RewardService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * ADR-173 — RewardService: пул PvE-наград курируется белым списком
 * `crafted_items.type` и потолком цены (story `-02`), больше не берёт
 * ВЕСЬ каталог по одной лишь цене (постройки/роботы/дроны/верстаки раньше
 * тоже выпадали из боя).
 *
 * Никакого source-scan: каждый кейс сеет реальные строки `crafted_items` в
 * `wildworld_tests`, дёргает приватный `getRandomCraftedItems()` рефлексией
 * и проверяет, ЧТО метод вернул — не что написано в файле.
 *
 * `crafted_items`/`game_settings` создаются вручную (как в LootTableServiceTest) —
 * своя изолированная таблица на время теста, а не общая тестовая схема,
 * чтобы не конфликтовать с параллельными файлами (урок «тест теплицы»).
 *
 * @internal
 */
final class RewardServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['crafted_items'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('
            CREATE TABLE crafted_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_rus VARCHAR(150) NULL,
                name_eng VARCHAR(150) NULL,
                type VARCHAR(100) NULL,
                direction_craft VARCHAR(50) NULL,
                price INT DEFAULT 0,
                durability_count INT NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    /** @param array<string, mixed> $item */
    private function seedItem(array $item): void
    {
        Database::connect('tests')->table('crafted_items')->insert(array_merge([
            'direction_craft' => 'all',
        ], $item));
    }

    /**
     * @param array<string, int|string|bool> $overrides Ключ (без префикса `pve.reward.`) => значение
     */
    private function serviceWith(array $overrides): RewardService
    {
        $model = new class ($overrides) extends GameSettingsModel {
            /** @param array<string, int|string|bool> $overrides */
            public function __construct(private array $overrides)
            {
            }

            public function findByKey(string $key): ?array
            {
                $short = str_replace('pve.reward.', '', $key);
                if (! array_key_exists($short, $this->overrides)) {
                    return null;
                }
                $v = $this->overrides[$short];
                if (is_bool($v)) {
                    return ['id' => 1, 'value_type' => 'bool', 'value_bool' => $v ? 1 : 0];
                }
                if (is_int($v)) {
                    return ['id' => 1, 'value_type' => 'int', 'value_int' => $v];
                }
                return ['id' => 1, 'value_type' => 'string', 'value_string' => (string) $v];
            }
        };

        return new RewardService(new GameSettingsService($model));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function callGetRandomCraftedItems(RewardService $svc, int $count, bool $expensive): array
    {
        $m = new ReflectionMethod(RewardService::class, 'getRandomCraftedItems');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $res */
        $res = $m->invoke($svc, $count, $expensive);
        return $res;
    }

    /** @param list<array<string, mixed>> $items */
    private function types(array $items): array
    {
        return array_values(array_unique(array_map(static fn (array $i): string => (string) $i['type'], $items)));
    }

    public function testTypeFilterExcludesNonWhitelistedTypesOnBothBranches(): void
    {
        // Каталог: постройки, магические предметы, военное, транспорт, телепорты,
        // роботы, дроны, верстаки — весь список из brief.md — плюс два разрешённых
        // типа (weapon/food), на дешёвой и на дорогой ветке.
        $this->seedItem(['name_eng' => 'sword', 'type' => 'weapon', 'price' => 200]);
        $this->seedItem(['name_eng' => 'stew', 'type' => 'food', 'price' => 1500]);
        $this->seedItem(['name_eng' => 'shed', 'type' => 'building', 'price' => 200]);
        $this->seedItem(['name_eng' => 'bunker', 'type' => 'building', 'price' => 1500]);
        $this->seedItem(['name_eng' => 'drone1', 'type' => 'drones', 'price' => 200]);
        $this->seedItem(['name_eng' => 'drone2', 'type' => 'drones', 'price' => 1500]);
        $this->seedItem(['name_eng' => 'bot1', 'type' => 'robots', 'price' => 200]);
        $this->seedItem(['name_eng' => 'bot2', 'type' => 'robots', 'price' => 1500]);
        $this->seedItem(['name_eng' => 'wb1', 'type' => 'workbench', 'price' => 200]);
        $this->seedItem(['name_eng' => 'wb2', 'type' => 'workbench', 'price' => 1500]);
        $this->seedItem(['name_eng' => 'amulet', 'type' => 'magical item', 'price' => 200]);
        $this->seedItem(['name_eng' => 'staff', 'type' => 'magical item', 'price' => 1500]);

        $svc = $this->serviceWith([
            'type_filter_enabled'  => true,
            'craft_types'          => 'weapon,food',
            'price_cap'            => 0,
            'expensive_threshold'  => 1000,
        ]);

        $cheap     = $this->callGetRandomCraftedItems($svc, 20, false);
        $expensive = $this->callGetRandomCraftedItems($svc, 20, true);

        $this->assertSame(['weapon'], $this->types($cheap), 'дешёвая ветка: только разрешённый weapon, без building/robots/drones/workbench/magical item');
        $this->assertSame(['food'], $this->types($expensive), 'дорогая ветка: только разрешённый food, без building/robots/drones/workbench/magical item');
    }

    public function testPriceCapExcludesItemAboveCapEvenIfTypeAllowed(): void
    {
        $this->seedItem(['name_eng' => 'cheap_sword', 'type' => 'weapon', 'price' => 200]);
        $this->seedItem(['name_eng' => 'pricey_sword', 'type' => 'weapon', 'price' => 800]);

        $svc = $this->serviceWith([
            'type_filter_enabled'  => true,
            'craft_types'          => 'weapon',
            'price_cap'            => 300,
            'expensive_threshold'  => 1000,
        ]);

        $cheap = $this->callGetRandomCraftedItems($svc, 20, false);
        $names = array_map(static fn (array $i): string => (string) $i['name_eng'], $cheap);

        $this->assertContains('cheap_sword', $names);
        $this->assertNotContains('pricey_sword', $names, 'предмет дороже price_cap не должен попасть в пул');
    }

    public function testPriceCapZeroMeansNoCap(): void
    {
        $this->seedItem(['name_eng' => 'pricey_sword', 'type' => 'weapon', 'price' => 800]);

        $svc = $this->serviceWith([
            'type_filter_enabled'  => true,
            'craft_types'          => 'weapon',
            'price_cap'            => 0,
            'expensive_threshold'  => 1000,
        ]);

        $cheap = $this->callGetRandomCraftedItems($svc, 20, false);
        $names = array_map(static fn (array $i): string => (string) $i['name_eng'], $cheap);

        $this->assertContains('pricey_sword', $names, 'price_cap=0 — предмет без потолка не отсекается');
    }

    public function testKillswitchOffRestoresPreFixBehaviour(): void
    {
        // Дофиксовое поведение: только порог цены, ни белый список, ни потолок не применяются.
        $this->seedItem(['name_eng' => 'shed', 'type' => 'building', 'price' => 200]);
        $this->seedItem(['name_eng' => 'pricey_sword', 'type' => 'weapon', 'price' => 800]);

        $svc = $this->serviceWith([
            'type_filter_enabled'  => false,
            'craft_types'          => 'weapon', // должно игнорироваться
            'price_cap'            => 300,       // должно игнорироваться
            'expensive_threshold'  => 1000,
        ]);

        $cheap = $this->callGetRandomCraftedItems($svc, 20, false);
        $names = array_map(static fn (array $i): string => (string) $i['name_eng'], $cheap);

        $this->assertContains('shed', $names, 'килсвитч выключен — непроверяемый тип building не должен отсекаться');
        $this->assertContains('pricey_sword', $names, 'килсвитч выключен — price_cap не должен отсекаться');
    }

    public function testEmptyPoolAfterFilteringReturnsEmptyArrayWithoutException(): void
    {
        // В каталоге только запрещённый тип — после фильтрации пул пуст.
        $this->seedItem(['name_eng' => 'shed', 'type' => 'building', 'price' => 200]);

        $svc = $this->serviceWith([
            'type_filter_enabled'  => true,
            'craft_types'          => 'weapon',
            'price_cap'            => 0,
            'expensive_threshold'  => 1000,
        ]);

        $result = $this->callGetRandomCraftedItems($svc, 5, false);

        $this->assertSame([], $result);
    }
}
