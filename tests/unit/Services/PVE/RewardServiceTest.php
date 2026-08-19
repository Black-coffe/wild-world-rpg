<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PVE;

use App\Database\Migrations\Adr173RetunePveRewardThresholds;
use App\Database\Migrations\Adr173SeedPveRewardPoolGameSettings;
use App\Models\GameSettingsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\PVE\RewardService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionClass;
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

    // `game_settings` только для testServiceDefaultsMatchSeededMigrationValues():
    // те два ADR-173-миграции реально исполняются против него, а не мокаются —
    // источник правды остаётся один (код миграции), не третий экземпляр в тесте.
    private const TABLES = ['crafted_items', 'game_settings'];

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
        // Схема — по образцу CreateGameSettingsTable (2026-05-19-100000):
        // те же колонки, которые читают/пишут обе Adr173-миграции.
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(64) NOT NULL,
                category VARCHAR(32) NOT NULL,
                value_type VARCHAR(16) NOT NULL,
                value_int INT NULL,
                value_float DECIMAL(12,4) NULL,
                value_bool TINYINT(1) NULL,
                value_string VARCHAR(255) NULL,
                default_value_text TEXT NOT NULL,
                rationale_text TEXT NOT NULL,
                effect_text TEXT NOT NULL,
                above_effect_text TEXT NOT NULL,
                below_effect_text TEXT NOT NULL,
                recommended_min VARCHAR(64) NULL,
                recommended_max VARCHAR(64) NULL,
                hard_min VARCHAR(64) NULL,
                hard_max VARCHAR(64) NULL,
                updated_by VARCHAR(128) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY setting_key (setting_key)
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

    /** Читает приватную константу RewardService — без accessibility-обхода не выйдет. */
    private function serviceConstant(string $name): mixed
    {
        return (new ReflectionClass(RewardService::class))->getConstant($name);
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

    /**
     * story `-05` (ADR-173, дельта плана 3, находка `lead-review`): все пять кейсов
     * выше задают настройки явными моками, поэтому набор оставался зелёным при
     * ЛЮБЫХ дефолтах — включая тот самый пустой «дорогой» пул (порог 5000 при
     * максимуме разрешённого типа 500), который доехал до человека.
     *
     * Этот кейс гоняет ОБЕ реальные seed-миграции (`-01` и ретюн `-04`) против
     * ephemeral `game_settings` и сверяет РЕЗУЛЬТАТ их применения с приватными
     * константами RewardService рефлексией. Числа нигде не хардкодятся третьим
     * экземпляром — единственный источник правды здесь это код самих миграций.
     */
    public function testServiceDefaultsMatchSeededMigrationValues(): void
    {
        // Файлы миграций именованы с датой-префиксом (`2026-08-19-...`), поэтому
        // PSR-4 classmap их не находит по FQN — как и `php spark migrate`,
        // требуем файл по пути перед `new`.
        require_once APPPATH . 'Database/Migrations/2026-08-19-210000_Adr173SeedPveRewardPoolGameSettings.php';
        require_once APPPATH . 'Database/Migrations/2026-08-19-224000_Adr173RetunePveRewardThresholds.php';

        (new Adr173SeedPveRewardPoolGameSettings())->up();
        (new Adr173RetunePveRewardThresholds())->up();

        $settings = new GameSettingsService(new GameSettingsModel());

        $this->assertSame(
            $this->serviceConstant('DEFAULT_EXPENSIVE_THRESHOLD'),
            $settings->get('pve.reward.expensive_threshold'),
            'DEFAULT_EXPENSIVE_THRESHOLD разошёлся с итоговым сиданным значением (после ретюна story -04)'
        );
        $this->assertSame(
            $this->serviceConstant('DEFAULT_PRICE_CAP'),
            $settings->get('pve.reward.price_cap'),
            'DEFAULT_PRICE_CAP разошёлся с итоговым сиданным значением (после ретюна story -04)'
        );
        $this->assertSame(
            $this->serviceConstant('DEFAULT_CRAFT_TYPES'),
            $settings->get('pve.reward.craft_types'),
            'DEFAULT_CRAFT_TYPES разошёлся с сиданным белым списком'
        );
        $this->assertSame(
            $this->serviceConstant('DEFAULT_TYPE_FILTER_ENABLED'),
            (bool) $settings->get('pve.reward.type_filter_enabled'),
            'DEFAULT_TYPE_FILTER_ENABLED разошёлся с сиданным килсвитчем'
        );
    }

    /**
     * story `-05`: дефолтная конфигурация (никаких моков — те же значения,
     * что проверены в testServiceDefaultsMatchSeededMigrationValues выше)
     * обязана давать непустой пул на ОБЕИХ ветках. Каталог воспроизводит
     * реальный порядок цен живой базы (замер story `-04`): разрешённые типы
     * 5–500, запрещённые — десятки тысяч. Красный на пороге 5000 проверен
     * ручной подстановкой (см. `## Implementation notes`).
     */
    public function testDefaultConfigurationYieldsNonEmptyPoolOnBothBranches(): void
    {
        $this->seedItem(['name_eng' => 'drug_low', 'type' => 'drug', 'price' => 5]);
        $this->seedItem(['name_eng' => 'drug_high', 'type' => 'drug', 'price' => 40]);
        $this->seedItem(['name_eng' => 'food_low', 'type' => 'food', 'price' => 20]);
        $this->seedItem(['name_eng' => 'food_high', 'type' => 'food', 'price' => 40]);
        $this->seedItem(['name_eng' => 'weapon_low', 'type' => 'weapon', 'price' => 15]);
        $this->seedItem(['name_eng' => 'weapon_high', 'type' => 'weapon', 'price' => 50]);
        $this->seedItem(['name_eng' => 'clothing_low', 'type' => 'clothing', 'price' => 50]);
        $this->seedItem(['name_eng' => 'clothing_high', 'type' => 'clothing', 'price' => 200]);
        $this->seedItem(['name_eng' => 'component_low', 'type' => 'component', 'price' => 70]);
        $this->seedItem(['name_eng' => 'component_high', 'type' => 'component', 'price' => 420]);
        $this->seedItem(['name_eng' => 'tool_low', 'type' => 'tool', 'price' => 25]);
        $this->seedItem(['name_eng' => 'tool_high', 'type' => 'tool', 'price' => 500]);
        // Запрещённые типы — на порядки дороже, как в живом каталоге.
        $this->seedItem(['name_eng' => 'robot', 'type' => 'robots', 'price' => 95000]);
        $this->seedItem(['name_eng' => 'workbench_unit', 'type' => 'workbench', 'price' => 120000]);
        $this->seedItem(['name_eng' => 'transport_unit', 'type' => 'transport', 'price' => 120600]);

        // Ноль override — берутся собственные дефолты сервиса (сверены выше с БД).
        $svc = $this->serviceWith([]);

        $cheap     = $this->callGetRandomCraftedItems($svc, 20, false);
        $expensive = $this->callGetRandomCraftedItems($svc, 20, true);

        $this->assertNotSame([], $cheap, 'дешёвая ветка пуста при дефолтной конфигурации сервиса');
        $this->assertNotSame(
            [],
            $expensive,
            'дорогая ветка пуста при дефолтной конфигурации — именно этот дефект (порог 5000 при максимуме каталога 500) доехал до человека зелёным'
        );
        foreach ([...$cheap, ...$expensive] as $item) {
            $this->assertContains(
                (string) $item['type'],
                explode(',', (string) $this->serviceConstant('DEFAULT_CRAFT_TYPES')),
                'в пул попал тип вне дефолтного белого списка'
            );
        }
    }

    /**
     * story `-05`, находка `lead-review`: killswitch `type_filter_enabled=false`
     * обязан делить пул историческим LEGACY_EXPENSIVE_THRESHOLD=5000, а НЕ
     * настраиваемым expensiveThreshold() — иначе после снижения дефолтного
     * порога до 100 (story `-04`) выключенный аварийный рычаг раздавал бы
     * почти весь каталог (роботы/верстаки/транспорт), то есть был бы строго
     * хуже аварии, от которой должен спасать.
     *
     * Предмет стоит 800 — дороже настраиваемого порога (100), но дешевле
     * исторического (5000): если килсвитч когда-нибудь начнёт читать
     * expensiveThreshold() вместо LEGACY_EXPENSIVE_THRESHOLD, предмет
     * перескочит из дешёвой ветки в дорогую — кейс красный (проверено
     * временной подменой, см. `## Implementation notes`).
     */
    public function testKillswitchOffUsesLegacyThresholdNotConfiguredOne(): void
    {
        $this->seedItem(['name_eng' => 'mid_priced_building', 'type' => 'building', 'price' => 800]);

        $svc = $this->serviceWith([
            'type_filter_enabled' => false,
            'craft_types'         => 'weapon', // должно игнорироваться — килсвитч выключен
            'price_cap'           => 300,       // должно игнорироваться — килсвитч выключен
            'expensive_threshold' => 100,        // должно игнорироваться в пользу LEGACY=5000
        ]);

        $cheap     = $this->callGetRandomCraftedItems($svc, 20, false);
        $expensive = $this->callGetRandomCraftedItems($svc, 20, true);

        $cheapNames     = array_map(static fn (array $i): string => (string) $i['name_eng'], $cheap);
        $expensiveNames = array_map(static fn (array $i): string => (string) $i['name_eng'], $expensive);

        $this->assertContains(
            'mid_priced_building',
            $cheapNames,
            'при выключенном килсвитче 800 < исторических 5000 — предмет обязан попасть в дешёвую ветку'
        );
        $this->assertNotContains(
            'mid_priced_building',
            $expensiveNames,
            'предмет за 800 попал в дорогую ветку — похоже, килсвитч использует настраиваемые 100 вместо исторических 5000'
        );
    }
}
