<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CraftRecipes;
use Config\Database;
use ReflectionClass;

/**
 * transport-06 (ADR-174, docs/specs/transport-system/) — пять рецептов машин
 * в `Config\CraftRecipes`: контракт ключей/гейтов, анти-эксплойт ADR-157,
 * реальность ингредиентов и реальное чтение фракционного гейта стартовым путём.
 *
 * Два независимых слоя проверки ADR-157 (`price × 1.10 ≤ gold + сырьё`), а не один:
 *
 * 1. `testAdr157AntiExploitHoldsForEachVehicle()` — на ФИКСИРОВАННЫХ ценах
 *    (см. `FIXTURE_*` ниже). Выполняется всегда, на любой БД (пустой локальной
 *    или наполненной CI), краснеет при дрейфе СОСТАВА рецепта (новое сырьё/
 *    компонент/qty) относительно этого фиксированного набора цен. Не заявляет,
 *    что цены актуальны прямо сейчас в каком-либо окружении — гарантирует
 *    только то, что формула и состав рецептов не разошлись до дыры.
 * 2. `testAdr157HoldsOnLiveCatalogIfCatalogPopulated()` — на ЖИВЫХ ценах из
 *    `crafted_items`/`resources` той БД, к которой подключён тестраннер
 *    (той же, что читает сама игра через `ResourceModel`/`CraftedItemsModel`).
 *    Если каталог не содержит нужных строк (пустая локальная/CI база — обычное
 *    состояние, см. `feedback_local_green_on_empty_test_db_proves_nothing`) —
 *    `markTestSkipped()` с именами недостающих строк, тест НЕ падает и НЕ
 *    зелёный молча — прямо сообщает «здесь нечем проверить». Там, где каталог
 *    наполнен (testbot/прод-дамп), ловит реальный дрейф цены.
 *
 * Раньше был только вариант 2 без skip — на пустом каталоге падал 9/9 в setUp()
 * (не защита, а шум). Раньше до этого был только вариант 1, но с ложной пометкой
 * «подтверждено на testbot» в комментарии к константам — теперь комментарий
 * честно называет их фикстурой.
 *
 * `characters`/`character_factions` — отдельная изолированная фикстура для
 * гейт-тестов ниже (гонки миграций её не касаются, см. `VehicleActivationServiceTest`).
 *
 * @internal
 */
final class VehicleRecipesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const KEYS = ['LightCart', 'MountainBike', 'Snowmobile', 'DraftCart', 'AutonomousDrone'];

    /** Контракт плана: recipeKey => [required_level, required_faction|0]. */
    private const GATES = [
        'LightCart'       => [6, 0],
        'MountainBike'    => [12, 2], // Партизаны
        'Snowmobile'      => [14, 1], // Милитари
        'DraftCart'       => [14, 4], // Фермеры
        'AutonomousDrone' => [16, 3], // Инженеры
    ];

    /**
     * M3 (ревью-находка «два источника правды у гейта»): recipeKey → посеянный
     * GameSettings-ключ `world.vehicle.<key>.required_level` (SeedVehicleGameSettings).
     * Это ЕДИНСТВЕННОЕ место, откуда `GenericCraftActionStart::handle()` реально
     * читает уровень при заданном `required_level_setting_key` (см. строки ~317-326
     * файла) — тест ниже проверяет чтение через тот же `GameSettingsService`, а не
     * копию логики.
     */
    private const LEVEL_SETTING_KEYS = [
        'LightCart'       => 'world.vehicle.cart.required_level',
        'MountainBike'    => 'world.vehicle.mtb.required_level',
        'Snowmobile'      => 'world.vehicle.snowmobile.required_level',
        'DraftCart'       => 'world.vehicle.draft_cart.required_level',
        'AutonomousDrone' => 'world.vehicle.drone_auto.required_level',
    ];

    /** Цена продажи каждой из пяти машин (`crafted_items.price`, id 43/47/50/46/49). */
    private const VEHICLE_SALE_PRICE = [
        'LightCart'       => 200.0,
        'MountainBike'    => 250.0,
        'Snowmobile'      => 700.0,
        'DraftCart'       => 400.0,
        'AutonomousDrone' => 800.0,
    ];

    /**
     * Фикстура, НЕ снапшот «подтверждённых на testbot» цен — источник правды
     * это тест 2 (живой каталог), этот набор существует только чтобы у теста 1
     * (структурная защита состава рецептов) были детерминированные входные
     * данные на любой БД. Подобраны так, чтобы инвариант держался — если
     * кто-то расширит рецепт новым сырьём/компонентом без ключа здесь,
     * `testRawResourceIngredientsAreFromRealCatalog()`/
     * `testCraftedItemIngredientsResolveToRealRecipesInThisConfig()` это поймают.
     */
    private const FIXTURE_CRAFTED_ITEM_PRICES = [
        'Fabric'                => 70.0,
        'metalFragments'        => 420.0,
        'WoodMaterials'         => 105.0,
        'wiring'                => 200.0,
        'electronicComponents'  => 300.0,
    ];
    private const FIXTURE_RESOURCE_SELL_PRICES = [
        'Древесина'       => 1.90,
        'Шкура животных'  => 3.44,
        'Кожа животных'   => 4.17,
        'Нефть'           => 19.00,
        'Солнечные камни' => 14.25,
    ];

    private CraftRecipes $cfg;
    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg  = new CraftRecipes();
        $this->conn = Database::connect('tests');

        foreach (['character_factions', 'characters'] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->conn->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                level INT NOT NULL DEFAULT 1
            )
        ');
        $this->conn->query('
            CREATE TABLE character_factions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                faction_id INT NOT NULL
            )
        ');

        // M3: фикстура game_settings по образцу VehicleActivationServiceTest —
        // те же колонки, которые читает GameSettingsService::get().
        $this->conn->query('DROP TABLE IF EXISTS game_settings');
        $this->conn->query('
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
        $this->cleanCache();
    }

    protected function tearDown(): void
    {
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

    private function seedRequiredLevel(string $settingKey, int $value): void
    {
        $this->conn->table('game_settings')->insert([
            'setting_key'        => $settingKey,
            'category'           => 'world',
            'value_type'         => 'int',
            'value_int'          => $value,
            'default_value_text' => (string) $value,
            'rationale_text'     => 'test',
            'effect_text'        => 'test',
            'above_effect_text'  => 'test',
            'below_effect_text'  => 'test',
        ]);
    }

    /**
     * Живой SELECT из каталожной таблицы — тех же данных, что читает игра
     * через `ResourceModel`/`CraftedItemsModel`. В отличие от старой версии
     * этого теста — НЕ падает, если строки нет: недостающие имена собираются
     * и возвращаются вызывающему, который решает (`markTestSkipped`), а не
     * молча считает отсутствующую цену нулём.
     *
     * Таблица может существовать, но с чужой схемой — соседние тесты в этом же
     * прогоне создают свои изолированные `resources`/`crafted_items` с другим
     * набором колонок в общей `tests`-БД и не всегда убирают их за собой
     * (наблюдалось локально: `crafted_items` без колонки `price`). Любая ошибка
     * запроса (нет таблицы, нет колонки, что угодно) — это тоже «каталог
     * непригоден для проверки» и уходит в тот же skip-путь, а не в фатал.
     *
     * @param list<string> $names
     * @return array{0:array<string,float>,1:list<string>} [цены по имени, недостающие имена]
     */
    private function loadLivePrices(string $table, string $nameColumn, string $priceColumn, array $names): array
    {
        try {
            if (! $this->conn->tableExists($table)) {
                return [[], $names];
            }

            $rows = $this->conn->table($table)
                ->select("{$nameColumn}, {$priceColumn}")
                ->whereIn($nameColumn, $names)
                ->get()
                ->getResultArray();
        } catch (\Throwable $e) {
            return [[], $names];
        }

        $prices = [];
        foreach ($rows as $row) {
            $prices[(string) $row[$nameColumn]] = (float) $row[$priceColumn];
        }

        return [$prices, array_values(array_diff($names, array_keys($prices)))];
    }

    // ── Контракт ключей/полей ──────────────────────────────────────────

    public function testFiveKeysExistWithContractItemNameEngAndTaskName(): void
    {
        foreach (self::KEYS as $key) {
            $r = $this->cfg->get($key);
            $this->assertIsArray($r, "Рецепт '{$key}' отсутствует в CraftRecipes");
            $this->assertSame($key, $r['item_name_eng'] ?? null, "{$key}: item_name_eng обязан 1:1 совпадать с ключом (контракт story 04)");
            $this->assertSame('craft' . $key, $r['task_name'] ?? null, "{$key}: task_name должен быть craft{$key}");
            $this->assertSame("genericCraft_{$key}_1", $r['craft_again_callback'] ?? null);
        }
    }

    public function testGatesMatchContractTable(): void
    {
        foreach (self::GATES as $key => [$level, $faction]) {
            $r = $this->cfg->get($key);
            $this->assertIsArray($r);
            $this->assertSame($level, $r['required_level'] ?? null, "{$key}: required_level mismatch");
            if ($faction === 0) {
                $this->assertArrayNotHasKey('required_faction', $r, "{$key}: должен быть доступен всем фракциям");
            } else {
                $this->assertSame($faction, $r['required_faction'] ?? null, "{$key}: required_faction mismatch");
            }
        }
    }

    /**
     * M3 (ревью-находка): каждый рецепт обязан объявлять `required_level_setting_key`,
     * иначе admin-tunable ключи `world.vehicle.*.required_level` остаются мёртвыми
     * (посеяны, но не читаются ни одной строкой кода — исходная находка).
     */
    public function testEachVehicleDeclaresRequiredLevelSettingKey(): void
    {
        foreach (self::LEVEL_SETTING_KEYS as $key => $settingKey) {
            $r = $this->cfg->get($key);
            $this->assertIsArray($r);
            $this->assertSame(
                $settingKey,
                $r['required_level_setting_key'] ?? null,
                "{$key}: обязан объявлять required_level_setting_key = {$settingKey} (иначе GameSettings-ключ мёртв)"
            );
        }
    }

    /**
     * M3: смена значения ключа `world.vehicle.<key>.required_level` в GameSettings
     * реально меняет гейт — через РЕАЛЬНЫЙ `GameSettingsService::get()`, тот же
     * читатель, которым `GenericCraftActionStart::handle()` резолвит `$levelRequired`
     * (строки ~317-326: `$this->gameSettings->get($levelSettingKey, $levelRequired)`).
     */
    public function testAdminOverrideOfRequiredLevelSettingIsReadThroughGameSettingsService(): void
    {
        $svc = new GameSettingsService();

        foreach (self::LEVEL_SETTING_KEYS as $key => $settingKey) {
            $r = $this->cfg->get($key);
            $this->assertIsArray($r);
            $fallback = (int) ($r['required_level'] ?? 0);

            $tunedValue = $fallback + 5;
            $this->seedRequiredLevel($settingKey, $tunedValue);

            $resolved = $svc->get($settingKey, $fallback);

            $this->assertSame(
                $tunedValue,
                $resolved,
                "{$key}: изменение {$settingKey} в GameSettings обязано менять гейт (было {$fallback}, выставлено {$tunedValue})"
            );
        }
    }

    /**
     * M3: фолбэк — если ключ не посеян (отсутствует строка в game_settings),
     * действует `recipe.required_level`, а не тихий null/0.
     */
    public function testMissingGameSettingsRowFallsBackToRecipeRequiredLevel(): void
    {
        $svc = new GameSettingsService();

        foreach (self::LEVEL_SETTING_KEYS as $key => $settingKey) {
            $r = $this->cfg->get($key);
            $this->assertIsArray($r);
            $fallback = (int) ($r['required_level'] ?? 0);

            // game_settings пуста для этого ключа в этом тесте — ключ не сеется.
            $resolved = $svc->get($settingKey, $fallback);

            $this->assertSame(
                $fallback,
                $resolved,
                "{$key}: без строки в GameSettings обязан действовать recipe.required_level ({$fallback})"
            );
        }
    }

    public function testGateLevelsAreBijectiveAcrossFiveVehicles(): void
    {
        $levels = array_map(static fn (array $g) => $g[0], self::GATES);
        sort($levels);
        $this->assertSame([6, 12, 14, 14, 16], $levels, 'уровни сдвинуты к точке выбора фракции — 6/12/14/14/16 по концепту §1');
    }

    public function testAgilityAndIntellectBonusesAreSetForEachVehicle(): void
    {
        foreach (self::KEYS as $key) {
            $r = $this->cfg->get($key);
            $this->assertIsFloat($r['agility_bonus'] ?? null, "{$key}: agility_bonus обязателен при output_type=crafted_item");
            $this->assertGreaterThan(0.0, $r['agility_bonus']);
            $this->assertIsFloat($r['intellect_bonus'] ?? null, "{$key}: intellect_bonus обязателен при output_type=crafted_item");
            $this->assertGreaterThan(0.0, $r['intellect_bonus']);
        }
    }

    // ── Ингредиенты реальны (не легаси Wheels/Rope/Horse/...) ────────────

    /**
     * Компоненты (`crafted_items` вход рецепта) проверяются САМИМ конфигом:
     * если имя резолвится в другой реальный рецепт этого же файла — оно
     * не выдумано. Легаси-имена вроде «Rubber»/«Propane Burner» такого
     * рецепта не имеют в принципе.
     */
    public function testCraftedItemIngredientsResolveToRealRecipesInThisConfig(): void
    {
        $checked = 0;
        foreach (self::KEYS as $key) {
            $r = $this->cfg->get($key);
            foreach (($r['crafted_items'] ?? []) as $itemNameEng => $qty) {
                $resolved = $this->cfg->findByItemNameEng((string) $itemNameEng);
                $this->assertNotNull($resolved, "{$key}: компонент '{$itemNameEng}' не резолвится ни в один реальный рецепт");
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked, 'хотя бы один рецепт должен использовать crafted_items компонент');
    }

    /**
     * Сырьё сверено против фикстуры реальных имён ресурсов (`FIXTURE_RESOURCE_
     * SELL_PRICES`) — ни одно имя не из легаси-набора
     * «Wooden Beams»/«Rope»/«Wheels»/«Horse»/«Rubber»/«Propane Burner».
     */
    public function testRawResourceIngredientsAreFromRealCatalog(): void
    {
        $legacy = ['Wooden Beams', 'Rope', 'Wheels', 'Horse', 'Rubber', 'Propane Burner', 'Iron Rods', 'Gears', 'Small Engine'];
        $checked = 0;
        foreach (self::KEYS as $key) {
            $r = $this->cfg->get($key);
            foreach (($r['resources'] ?? []) as $name => $qty) {
                $this->assertNotContains($name, $legacy, "{$key}: ссылается на легаси-ресурс '{$name}', которого не существует");
                $this->assertArrayHasKey($name, self::FIXTURE_RESOURCE_SELL_PRICES, "{$key}: ресурс '{$name}' не в фикстуре — обнови FIXTURE_RESOURCE_SELL_PRICES вместе с рецептом");
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    // ── Анти-эксплойт ADR-157 ─────────────────────────────────────────────

    /**
     * price × 1.10 ≤ gold_required + Σ сырьё×sell_price + Σ компоненты×price×1.10,
     * иначе крафт печатает золото (собрать → продать выгоднее, чем добыть).
     * Считается на ФИКСИРОВАННЫХ ценах (см. класс-докблок, слой 1) — выполняется
     * всегда, краснеет при дрейфе СОСТАВА рецепта относительно этого набора.
     * Актуальность самих цен здесь не проверяется — за это отвечает следующий тест.
     */
    public function testAdr157AntiExploitHoldsForEachVehicle(): void
    {
        foreach (self::KEYS as $key) {
            $r = $this->cfg->get($key);
            $this->assertIsArray($r);

            $inputValue = $this->adr157InputValue($r, self::FIXTURE_RESOURCE_SELL_PRICES, self::FIXTURE_CRAFTED_ITEM_PRICES);
            $saleRevenue = self::VEHICLE_SALE_PRICE[$key] * 1.10;

            $this->assertLessThanOrEqual(
                $inputValue,
                $saleRevenue,
                "{$key}: price×1.10 ({$saleRevenue}) превышает стоимость входа на фикстуре ({$inputValue}) — крафт печатает золото (ADR-157)"
            );
        }
    }

    /**
     * Слой 2 (см. класс-докблок): тот же инвариант, но на ЖИВЫХ ценах
     * `crafted_items.price`/`resources.sell_price` из БД, к которой подключён
     * тестраннер. Пустой/неполный каталог (обычное состояние локальной и CI
     * БД) — `markTestSkipped`, а не падение и не тихий зелёный: явно называет
     * недостающие строки, чтобы было видно, что проверка не выполнилась,
     * а не что она подтвердила инвариант.
     */
    public function testAdr157HoldsOnLiveCatalogIfCatalogPopulated(): void
    {
        $craftedNames  = array_keys(self::FIXTURE_CRAFTED_ITEM_PRICES);
        $resourceNames = array_keys(self::FIXTURE_RESOURCE_SELL_PRICES);

        [$liveCraftedPrices, $missingCrafted]   = $this->loadLivePrices('crafted_items', 'name_eng', 'price', $craftedNames);
        [$liveResourcePrices, $missingResource] = $this->loadLivePrices('resources', 'name', 'sell_price', $resourceNames);

        $missing = array_merge($missingCrafted, $missingResource);
        if ($missing !== []) {
            $this->markTestSkipped(
                'Живой каталог не наполнен нужными строками (' . implode(', ', $missing) . ') — '
                . 'обычное состояние локальной/CI БД (feedback_local_green_on_empty_test_db_proves_nothing). '
                . 'Дрейф реальных цен ловится там, где каталог наполнен (testbot/прод-дамп).'
            );
        }

        foreach (self::KEYS as $key) {
            $r = $this->cfg->get($key);
            $this->assertIsArray($r);

            $inputValue  = $this->adr157InputValue($r, $liveResourcePrices, $liveCraftedPrices);
            $saleRevenue = self::VEHICLE_SALE_PRICE[$key] * 1.10;

            $this->assertLessThanOrEqual(
                $inputValue,
                $saleRevenue,
                "{$key}: price×1.10 ({$saleRevenue}) превышает стоимость входа на живом каталоге ({$inputValue}) — крафт печатает золото (ADR-157)"
            );
        }
    }

    /**
     * @param array<string,mixed> $recipe
     * @param array<string,float> $resourceSellPrices
     * @param array<string,float> $craftedItemPrices
     */
    private function adr157InputValue(array $recipe, array $resourceSellPrices, array $craftedItemPrices): float
    {
        $inputValue = (float) ($recipe['gold_required'] ?? 0);

        foreach (($recipe['resources'] ?? []) as $name => $qty) {
            $sell = $resourceSellPrices[$name] ?? 0.0;
            $inputValue += $sell * (int) $qty;
        }
        foreach (($recipe['crafted_items'] ?? []) as $itemNameEng => $qty) {
            $price = $craftedItemPrices[$itemNameEng] ?? 0.0;
            $inputValue += $price * 1.10 * (int) $qty;
        }

        return $inputValue;
    }

    // ── Гейт enforced на старте, не только в превью ───────────────────────

    /**
     * `GenericCraftActionStart::handle()` нельзя вызвать напрямую в юнит-тесте
     * без реального Telegram API_KEY (см. memory `feedback_taskhandler_telegram_
     * init_in_tests` — на CI без ключа падает TelegramException ДО достижения
     * gate-проверки). Вместо этого тест зовёт РЕАЛЬНЫЙ приватный метод
     * `characterFactionId()` — ТОТ ЖЕ читатель, которым `handle()` в
     * строке required_faction-проверки резолвит фракцию персонажа (не стаб,
     * не рисованное превью) — на реальной фикстуре БД с персонажем чужой
     * фракции, и подтверждает, что чтение расходится с required_faction
     * рецепта (то есть `handle()` в этой точке обязан отказать).
     */
    public function testFactionGateReaderRejectsWrongFactionForEachFactionLockedVehicle(): void
    {
        $ref        = new ReflectionClass(GenericCraftActionStart::class);
        $instance   = $ref->newInstanceWithoutConstructor();
        $methodRead = $ref->getMethod('characterFactionId');
        $methodRead->setAccessible(true);

        foreach (self::GATES as $key => [$level, $requiredFaction]) {
            if ($requiredFaction === 0) {
                continue;
            }
            $wrongFaction = $requiredFaction === 1 ? 2 : 1;

            $this->conn->table('characters')->insert(['level' => 99]);
            $charId = (int) $this->conn->insertID();
            $this->conn->table('character_factions')->insert([
                'character_id' => $charId,
                'faction_id'   => $wrongFaction,
            ]);

            $actualFaction = (int) $methodRead->invoke($instance, $charId);

            $this->assertNotSame(
                $requiredFaction,
                $actualFaction,
                "{$key}: персонаж чужой фракции обязан провалить required_faction-гейт на старте"
            );
        }
    }

    public function testFactionGateReaderAcceptsCorrectFaction(): void
    {
        $ref        = new ReflectionClass(GenericCraftActionStart::class);
        $instance   = $ref->newInstanceWithoutConstructor();
        $methodRead = $ref->getMethod('characterFactionId');
        $methodRead->setAccessible(true);

        foreach (self::GATES as $key => [$level, $requiredFaction]) {
            if ($requiredFaction === 0) {
                continue;
            }

            $this->conn->table('characters')->insert(['level' => 99]);
            $charId = (int) $this->conn->insertID();
            $this->conn->table('character_factions')->insert([
                'character_id' => $charId,
                'faction_id'   => $requiredFaction,
            ]);

            $actualFaction = (int) $methodRead->invoke($instance, $charId);

            $this->assertSame($requiredFaction, $actualFaction, "{$key}: персонаж своей фракции обязан пройти required_faction-гейт");
        }
    }

}
