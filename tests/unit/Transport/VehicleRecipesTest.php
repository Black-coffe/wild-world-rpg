<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart;
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
 * ADR-157-цены (`crafted_items.price` компонентов Fabric/metalFragments/
 * WoodMaterials/wiring/electronicComponents и `resources.sell_price` сырья)
 * сверены вручную на testbot (2026-08-19, `SELECT ... FROM crafted_items/
 * resources`) — изолированная фикстура ниже сеет ИМЕННО эти подтверждённые
 * значения, а не гадает по общей `wildworld_tests` (та мигрируется/сидируется
 * параллельными сессиями и не гарантирует состав на момент прогона; паттерн
 * изоляции — как в соседнем `VehicleActivationServiceTest`).
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

    /** Подтверждённые на testbot (2026-08-19) цены компонентов и sell_price сырья. */
    private const CRAFTED_ITEM_PRICES = [
        'Fabric'                => 70.0,
        'metalFragments'        => 420.0,
        'WoodMaterials'         => 105.0,
        'wiring'                => 200.0,
        'electronicComponents'  => 300.0,
    ];
    private const RESOURCE_SELL_PRICES = [
        'Древесина'       => 1.90,
        'Шкура животных'  => 3.44,
        'Кожа животных'   => 4.17,
        'Нефть'           => 19.00,
        'Солнечные камни' => 14.25,
    ];

    /** Цена продажи каждой из пяти машин (`crafted_items.price`, id 43/47/50/46/49). */
    private const VEHICLE_SALE_PRICE = [
        'LightCart'       => 200.0,
        'MountainBike'    => 250.0,
        'Snowmobile'      => 700.0,
        'DraftCart'       => 400.0,
        'AutonomousDrone' => 800.0,
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
     * Сырьё сверено против подтверждённого на testbot списка реальных
     * ресурсов (see class docblock) — ни одно имя не из легаси-набора
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
                $this->assertArrayHasKey($name, self::RESOURCE_SELL_PRICES, "{$key}: ресурс '{$name}' не в подтверждённом на testbot списке реальных ресурсов");
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    // ── Анти-эксплойт ADR-157 ─────────────────────────────────────────────

    /**
     * price × 1.10 ≤ gold_required + Σ сырьё×sell_price + Σ компоненты×price×1.10,
     * иначе крафт печатает золото (собрать → продать выгоднее, чем добыть).
     * Считается по каждому из пяти отдельно — краснеет, если кто-то удешевит
     * вход или поднимет `crafted_items.price` без пересчёта.
     */
    public function testAdr157AntiExploitHoldsForEachVehicle(): void
    {
        foreach (self::KEYS as $key) {
            $r = $this->cfg->get($key);
            $this->assertIsArray($r);

            $goldRequired = (float) ($r['gold_required'] ?? 0);

            $inputValue = $goldRequired;
            foreach (($r['resources'] ?? []) as $name => $qty) {
                $sell = self::RESOURCE_SELL_PRICES[$name] ?? 0.0;
                $inputValue += $sell * (int) $qty;
            }
            foreach (($r['crafted_items'] ?? []) as $itemNameEng => $qty) {
                $price = self::CRAFTED_ITEM_PRICES[$itemNameEng] ?? 0.0;
                $inputValue += $price * 1.10 * (int) $qty;
            }

            $saleRevenue = self::VEHICLE_SALE_PRICE[$key] * 1.10;

            $this->assertLessThanOrEqual(
                $inputValue,
                $saleRevenue,
                "{$key}: price×1.10 ({$saleRevenue}) превышает стоимость входа ({$inputValue}) — крафт печатает золото (ADR-157)"
            );
        }
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
