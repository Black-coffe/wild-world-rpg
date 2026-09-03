<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\Models\BaseStorageModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\GameSettingsModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsService;
use App\TaskHandlers\GreenhouseProductionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * exploit-fix-29 (R2-minor) — story 23 сделала складскую половину списания воды в
 * `GreenhouseProductionHandler::handle()` условной (`$storageWithdrawn === $fromStorage`):
 * недостача на складе теперь откатывает ОБЕ половины (`$db->transRollback()`), а не только
 * жалуется в лог, выдавая урожай за счёт недостачи. Story 23 сама изменила момент выдачи
 * урожая, но не оставила теста на это (m8 находки ревью) — этот файл закрепляет поведение
 * поведенческим тестом, не трогая handler.
 *
 * Фикстура — точная копия схемы/подхода `GreenhouseProductionWaterTest` (уникальный префикс
 * таблиц `ghr_`, DI-инъекция моделей через конструктор handler'а, ни одна общая таблица не
 * дропается) — см. её докблок про историю `RENAME TABLE`-инцидента с общим стендом.
 *
 * @internal
 */
final class GreenhouseProductionRefusalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** Уникальный префикс — гарантия, что тест никогда не заденет общие таблицы других тестов. */
    private const PREFIX = 'ghr_';

    private const TABLE_GAME_SETTINGS       = self::PREFIX . 'game_settings';
    private const TABLE_BUILDINGS           = self::PREFIX . 'buildings';
    private const TABLE_CHARACTER_BUILDINGS = self::PREFIX . 'character_buildings';
    private const TABLE_RESOURCES           = self::PREFIX . 'resources';
    private const TABLE_CHARACTER_RESOURCES = self::PREFIX . 'character_resources';
    private const TABLE_BASE_STORAGE        = self::PREFIX . 'base_storage';

    private const TABLES = [
        self::TABLE_GAME_SETTINGS, self::TABLE_BUILDINGS, self::TABLE_CHARACTER_BUILDINGS,
        self::TABLE_RESOURCES, self::TABLE_CHARACTER_RESOURCES, self::TABLE_BASE_STORAGE,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE ' . self::TABLE_GAME_SETTINGS . ' (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_float DOUBLE NULL, value_string TEXT NULL) ENGINE=InnoDB');
        $db->query('CREATE TABLE ' . self::TABLE_BUILDINGS . ' (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(191)) ENGINE=InnoDB');
        $db->query('CREATE TABLE ' . self::TABLE_CHARACTER_BUILDINGS . ' (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, building_id INT, level INT DEFAULT 1) ENGINE=InnoDB');
        $db->query('CREATE TABLE ' . self::TABLE_RESOURCES . ' (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(191)) ENGINE=InnoDB');
        $db->query('CREATE TABLE ' . self::TABLE_CHARACTER_RESOURCES . ' (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT, quantity INT DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB');
        $db->query('CREATE TABLE ' . self::TABLE_BASE_STORAGE . ' (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, resource_id INT, quantity INT DEFAULT 0, arrived_from_cell INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB');

        $db->table(self::TABLE_BUILDINGS)->insert(['id' => 1, 'name_en' => 'Greenhouse']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 1, 'name_en' => 'Water']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 2, 'name_en' => 'Fruit']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 3, 'name_en' => 'Berries']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 4, 'name_en' => 'Mushrooms']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 5, 'name_en' => 'Crops']);
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

    /** @return int id вставленной строки character_buildings (конкретный экземпляр постройки) */
    private function makeGreenhouse(int $characterId, int $level): int
    {
        $db = Database::connect('tests');
        $db->table(self::TABLE_CHARACTER_BUILDINGS)->insert([
            'character_id' => $characterId,
            'building_id'  => 1,
            'level'        => $level,
        ]);

        return (int) $db->insertID();
    }

    private function setBackpackWater(int $characterId, int $qty): void
    {
        Database::connect('tests')->table(self::TABLE_CHARACTER_RESOURCES)->insert([
            'id_characters' => $characterId,
            'id_resources'  => 1,
            'quantity'      => $qty,
        ]);
    }

    private function setStorageWater(int $characterId, int $qty): void
    {
        Database::connect('tests')->table(self::TABLE_BASE_STORAGE)->insert([
            'character_id' => $characterId,
            'resource_id'  => 1,
            'quantity'     => $qty,
        ]);
    }

    private function backpackQty(int $characterId): int
    {
        $row = $this->backpackRow($characterId);
        return $row ? (int) $row['quantity'] : 0;
    }

    /** @return array<string,mixed>|null сырая строка character_resources (Вода) — null, если строки нет вовсе */
    private function backpackRow(int $characterId): ?array
    {
        $row = Database::connect('tests')->table(self::TABLE_CHARACTER_RESOURCES)
            ->where('id_characters', $characterId)->where('id_resources', 1)->get()->getRowArray();
        return $row ?: null;
    }

    private function storageQty(int $characterId): int
    {
        $row = Database::connect('tests')->table(self::TABLE_BASE_STORAGE)
            ->where('character_id', $characterId)->where('resource_id', 1)->get()->getRowArray();
        return $row ? (int) $row['quantity'] : 0;
    }

    /** @return int количество ресурса $resourceId в рюкзаке персонажа (0, если строки нет) */
    private function harvestQty(int $characterId, int $resourceId): int
    {
        $row = Database::connect('tests')->table(self::TABLE_CHARACTER_RESOURCES)
            ->where('id_characters', $characterId)->where('id_resources', $resourceId)->get()->getRowArray();
        return $row ? (int) $row['quantity'] : 0;
    }

    /**
     * Собирает handler со всеми моделями, указывающими на приватные `ghr_*` таблицы —
     * тот же DI-паттерн, что `GreenhouseProductionWaterTest::handler()`.
     *
     * @param BaseStorageModel|null $baseStorageModel опциональная подмена — сценарий
     *                                                 недостачи ниже подставляет вариант
     *                                                 с раздутым `quantityFor()` (эмуляция
     *                                                 гонки: см. докблок теста).
     */
    private function handler(?BaseStorageModel $baseStorageModel = null): GreenhouseProductionHandler
    {
        $characterBuildingModel = (new CharacterBuildingModel())->setTable(self::TABLE_CHARACTER_BUILDINGS);
        $characterResourceModel = (new CharacterResourceModel())->setTable(self::TABLE_CHARACTER_RESOURCES);
        $buildingModel          = (new BuildingModel())->setTable(self::TABLE_BUILDINGS);
        $resourceModel          = (new ResourceModel())->setTable(self::TABLE_RESOURCES);
        $baseStorageModel       = $baseStorageModel ?? (new BaseStorageModel())->setTable(self::TABLE_BASE_STORAGE);
        $gameSettings           = new GameSettingsService((new GameSettingsModel())->setTable(self::TABLE_GAME_SETTINGS));

        return new class(
            null,
            null,
            $characterBuildingModel,
            $characterResourceModel,
            $buildingModel,
            $resourceModel,
            null,
            null,
            $baseStorageModel,
            $gameSettings
        ) extends GreenhouseProductionHandler {
            protected function notifyWaterShortage(int $characterId, int $waterQuantity): void
            {
                // Тихо — Telegram недоступен в тестах (memory
                // feedback_taskhandler_telegram_init_in_tests), путь уведомления не под тестом.
            }
        };
    }

    // ----------------------------------------------------------------

    /**
     * Acceptance 1: складской половины не хватает (недостача) → урожай не начисляется,
     * рюкзак и склад равны состоянию ДО прохода handler'а (обе половины откатились).
     *
     * L4 требует 4 воды. Рюкзак=1 (весь уйдёт на fromBackpack), значит fromStorage=3.
     * Реально на складе только 1 единица — недостачу нужно смоделировать так же, как её
     * описывает сам handler (комментарий exploit-fix-06/23): пул (`poolQty`) читается ОДНИМ
     * моментом, а списание (`withdraw()`) — следующим, и между ними склад мог опустеть.
     * Подставляем `BaseStorageModel`, где `quantityFor()` (использованный только для чтения
     * пула) отвечает раздутым числом 10, а `withdraw()` (реальное списание) работает на
     * настоящей строке с quantity=1 — то есть handler решает, что воды в пуле достаточно
     * (1+10=11 ≥ 4), но при физическом списании складская половина берёт только 1 из
     * запрошенных 3. Это и есть путь, который story 23 сделала условным.
     */
    public function testStorageShortfallRefusesAndLeavesBothHalvesUntouched(): void
    {
        $c = 1;
        $this->makeGreenhouse($c, 4);
        $this->setBackpackWater($c, 1);
        $this->setStorageWater($c, 1);

        $inflatedStorageModel = new class extends BaseStorageModel {
            public function quantityFor(int $characterId, int $resourceId): int
            {
                // Раздутое число — эмулирует момент чтения пула ДО того, как склад
                // опустел (гонка crona/игрока); реальное `withdraw()` ниже не переопределён
                // и честно работает по настоящей строке.
                return 10;
            }
        };
        $inflatedStorageModel->setTable(self::TABLE_BASE_STORAGE);

        $this->handler($inflatedStorageModel)->handle();

        $this->assertSame(1, $this->backpackQty($c), 'рюкзак вернулся к состоянию до прохода (откат)');
        $this->assertSame(1, $this->storageQty($c), 'склад вернулся к состоянию до прохода (откат)');
        $this->assertSame(0, $this->harvestQty($c, 2), 'Fruit не начислен — урожай не выдан при недостаче');
        $this->assertSame(0, $this->harvestQty($c, 3), 'Berries не начислен — урожай не выдан при недостаче');
    }

    /**
     * Acceptance 2: обеих половин хватает → урожай выдан как прежде, обе половины списаны.
     *
     * L4: water=4, Fruit=3, Berries=3. Рюкзак=1 (fromBackpack=1), fromStorage=3,
     * на складе 5 — хватает с запасом.
     */
    public function testSufficientBothHalvesGrantsHarvestAndDeductsBoth(): void
    {
        $c = 2;
        $this->makeGreenhouse($c, 4);
        $this->setBackpackWater($c, 1);
        $this->setStorageWater($c, 5);

        $this->handler()->handle();

        $this->assertNull($this->backpackRow($c), 'рюкзак вычерпан полностью (1 из 1) — строка удалена');
        $this->assertSame(2, $this->storageQty($c), 'склад списан на fromStorage=3 (5-3=2)');
        $this->assertSame(3, $this->harvestQty($c, 2), 'Fruit начислен по таблице L4');
        $this->assertSame(3, $this->harvestQty($c, 3), 'Berries начислен по таблице L4');
    }
}
