<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\Built\GenericBuildingCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionClass;

/**
 * building-bonus-absorption-01 — `completion_bonus_agility/intellect` начисляется
 * ТОЛЬКО за первый экземпляр здания этого типа у персонажа (любая база), а не за
 * каждую копию (см. brief: 16 лишних начислений на проде).
 *
 * Схема — DI через reflection в private-свойства `GenericBuildingCompletionHandler`
 * (у него нет конструктора с DI, как у `GreenhouseProductionHandler`), модели указывают
 * на приватные `gba_*`-таблицы, ни одна общая таблица не задета. `characterModel` —
 * тестовый двойник, полностью подменяющий `updateAgilityAndIntellect()` (реальный метод
 * идёт через `CharacterStatsService` на хардкод-таблицу `characters` — дороже и не по
 * теме этой истории). `resolveBuildCell()` (protected) переопределён, чтобы не задевать
 * реальную `claimed_cells` (используется хардкодно через `new ClaimedCellModel()`,
 * не инжектируется) — она не по теме этой истории (ADR-102 релокейшн-логика).
 *
 * @internal
 */
final class GenericBuildingCompletionBonusTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'gba_';

    private const TABLE_BUILDINGS           = self::PREFIX . 'buildings';
    private const TABLE_CHARACTER_BUILDINGS = self::PREFIX . 'character_buildings';
    private const TABLE_CHARACTERS          = self::PREFIX . 'characters';
    private const TABLE_CHARACTER_FACTIONS  = self::PREFIX . 'character_factions';
    private const TABLE_TELEGRAM_USERS      = self::PREFIX . 'telegram_users';

    private const TABLES = [
        self::TABLE_BUILDINGS, self::TABLE_CHARACTER_BUILDINGS, self::TABLE_CHARACTERS,
        self::TABLE_CHARACTER_FACTIONS, self::TABLE_TELEGRAM_USERS,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE ' . self::TABLE_BUILDINGS . ' (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(191), building_type VARCHAR(32), hp INT DEFAULT 0, tax INT DEFAULT 0, `usage` VARCHAR(16) DEFAULT "personal") ENGINE=InnoDB');
        // Схема — 1:1 с app/Database/Migrations/2024-05-27-105534_CreateCharacterBuildingsTable.php
        // (+ ...095120_AddLastTaxCollectedToCharacterBuildings уже влит туда же), а не из головы —
        // прошлый круг разошёлся с боевой (не было last_tax_collected/tax_collection_status).
        $db->query('CREATE TABLE ' . self::TABLE_CHARACTER_BUILDINGS . ' ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
            . 'character_id INT UNSIGNED, '
            . 'building_id INT UNSIGNED, '
            . 'faction_id INT UNSIGNED NULL, '
            . 'map_cell_id INT UNSIGNED DEFAULT 0, '
            . 'amount INT DEFAULT 1, '
            . 'character_level_during_construction INT, '
            . 'hp INT, '
            . "level INT DEFAULT 1, "
            . 'built_at DATETIME NOT NULL, '
            . "building_type ENUM('military','residential','farming','resource','engineering') NULL, "
            . 'tax INT, '
            . "`usage` ENUM('personal','collective','all') NULL, "
            . 'created_at DATETIME NULL, '
            . 'updated_at DATETIME NULL, '
            . 'last_tax_collected DATETIME NULL, '
            . "tax_collection_status ENUM('SUCCESS','FAILURE') DEFAULT 'SUCCESS', "
            . 'disappearance_date DATETIME NULL, '
            . 'usage_count INT NULL'
            . ') ENGINE=InnoDB');
        $db->query('CREATE TABLE ' . self::TABLE_CHARACTERS . ' (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT DEFAULT 0, level INT DEFAULT 1) ENGINE=InnoDB');
        $db->query('CREATE TABLE ' . self::TABLE_CHARACTER_FACTIONS . ' (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, faction_id INT NULL) ENGINE=InnoDB');
        $db->query('CREATE TABLE ' . self::TABLE_TELEGRAM_USERS . ' (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id VARCHAR(64)) ENGINE=InnoDB');

        // Реальные рецепты из Config\Buildings — сравнивать бонус будем с реальными числами.
        $db->table(self::TABLE_BUILDINGS)->insert(['id' => 1, 'name_en' => 'Workshop', 'building_type' => 'engineering']);
        $db->table(self::TABLE_BUILDINGS)->insert(['id' => 2, 'name_en' => 'BlastFurnace', 'building_type' => 'engineering']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function makeCharacter(int $id): void
    {
        Database::connect('tests')->table(self::TABLE_CHARACTERS)->insert([
            'id'          => $id,
            'cell_number' => 0,
            'level'       => 5,
        ]);
    }

    /** @return int id вставленной строки character_buildings (уже существующий экземпляр здания) */
    private function makeExistingBuilding(int $characterId, int $buildingId, int $mapCellId): int
    {
        $db = Database::connect('tests');
        $db->table(self::TABLE_CHARACTER_BUILDINGS)->insert([
            'character_id'                        => $characterId,
            'building_id'                         => $buildingId,
            'map_cell_id'                          => $mapCellId,
            'amount'                               => 1,
            'character_level_during_construction' => 5,
            'hp'                                   => 100,
            'level'                                => 1,
            'built_at'                             => date('Y-m-d H:i:s'),
            'building_type'                        => 'engineering',
            'tax'                                  => 0,
            'usage'                                => 'personal',
        ]);

        return (int) $db->insertID();
    }

    /**
     * Собирает handler с моделями, указывающими на приватные `gba_*`-таблицы, через
     * reflection (у `GenericBuildingCompletionHandler` нет DI-конструктора). `resolveBuildCell`
     * переопределён, чтобы не трогать хардкодную `claimed_cells` (см. докблок класса).
     */
    private function handler(): GenericBuildingCompletionHandler
    {
        $characterModel         = (new RecordingCharacterModel())->setTable(self::TABLE_CHARACTERS);
        $characterTaskModel     = (new CharacterTaskModel());
        $buildingModel          = (new BuildingModel())->setTable(self::TABLE_BUILDINGS);
        $characterBuildingModel = (new CharacterBuildingModel())->setTable(self::TABLE_CHARACTER_BUILDINGS);
        $characterFactionModel  = (new CharacterFactionModel())->setTable(self::TABLE_CHARACTER_FACTIONS);
        $telegramUserModel      = (new TelegramUserModel())->setTable(self::TABLE_TELEGRAM_USERS);

        $handler = new class () extends GenericBuildingCompletionHandler {
            protected function resolveBuildCell(array $task, int $fallbackCell): int
            {
                // Не по теме этой истории (ADR-102 релокейшн-логика) — хардкодный
                // `new ClaimedCellModel()` внутри не инжектируется, обходим его.
                return $fallbackCell;
            }
        };

        $ref = new ReflectionClass(GenericBuildingCompletionHandler::class);
        $set = static function (string $prop, object $value) use ($ref, $handler): void {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($handler, $value);
        };
        $set('characterModel', $characterModel);
        $set('characterTaskModel', $characterTaskModel);
        $set('buildingModel', $buildingModel);
        $set('characterBuildingModel', $characterBuildingModel);
        $set('characterFactionModel', $characterFactionModel);
        $set('telegramUserModel', $telegramUserModel);

        return $handler;
    }

    /** @param array<string,mixed> $extraSettings */
    private function task(int $characterId, string $building, array $extraSettings = []): array
    {
        return [
            'character_id'    => $characterId,
            'telegram_user_id' => $characterId,
            'task_settings'   => json_encode(['building' => $building] + $extraSettings),
        ];
    }

    private function recordingCharacterModel(GenericBuildingCompletionHandler $handler): RecordingCharacterModel
    {
        $ref = new ReflectionClass(GenericBuildingCompletionHandler::class);
        $p   = $ref->getProperty('characterModel');
        $p->setAccessible(true);

        /** @var RecordingCharacterModel $model */
        $model = $p->getValue($handler);

        return $model;
    }

    // ----------------------------------------------------------------

    /** Acceptance 1: первая постройка типа у персонажа → бонус начислен числами из рецепта. */
    public function testFirstBuildingOfTypeGrantsBonus(): void
    {
        $c = 1;
        $this->makeCharacter($c);
        $handler = $this->handler();

        $handler->handle($this->task($c, 'Workshop'));

        $calls = $this->recordingCharacterModel($handler)->calls;
        $this->assertCount(1, $calls, 'первый экземпляр — бонус начислен ровно один раз');
        $this->assertSame($c, $calls[0]['characterId']);
        $this->assertSame(0.03, $calls[0]['agility']);
        $this->assertSame(0.03, $calls[0]['intellect']);
    }

    /**
     * Acceptance 2: вторая постройка того же типа при уже существующей строке
     * `character_buildings` (в т.ч. с ДРУГИМ `map_cell_id`) → бонус не начисляется.
     */
    public function testSecondBuildingOfSameTypeOnDifferentBaseGrantsNoBonus(): void
    {
        $c = 2;
        $this->makeCharacter($c);
        $this->makeExistingBuilding($c, 1, 111); // Workshop уже стоит на базе 111
        $handler = $this->handler();

        $handler->handle($this->task($c, 'Workshop', ['base_cell' => 222])); // строим на ДРУГОЙ базе

        $calls = $this->recordingCharacterModel($handler)->calls;
        $this->assertCount(0, $calls, 'дубль типа — бонус поглощён, вызова нет');
    }

    /**
     * Ревью §3: дубль на ТОЙ ЖЕ базе (ветка `amount++` в `updateCharacterBuildings()`) —
     * бонус не начисляется, и amount у существующей строки вырос.
     */
    public function testDuplicateOnSameBaseIncrementsAmountAndGrantsNoBonus(): void
    {
        $c = 4;
        $this->makeCharacter($c);
        $existingId = $this->makeExistingBuilding($c, 1, 0); // Workshop на клетке 0 — cell_number персонажа тоже 0
        $handler = $this->handler();

        $handler->handle($this->task($c, 'Workshop')); // без base_cell → fallback = cell_number = 0, та же клетка

        $calls = $this->recordingCharacterModel($handler)->calls;
        $this->assertCount(0, $calls, 'дубль на той же базе — бонус поглощён, вызова нет');

        $row = Database::connect('tests')->table(self::TABLE_CHARACTER_BUILDINGS)
            ->where('id', $existingId)->get()->getRowArray();
        $this->assertNotNull($row, 'строка не удалена и не задвоена');
        $this->assertSame(2, (int) $row['amount'], 'amount вырос на стаке той же базы (1 → 2)');
    }

    /**
     * Ревью §1: `characterModel->find()` не находит персонажа (гонка/удаление) —
     * запись character_buildings не состоялась, бонус не начисляется независимо от
     * признака «первый экземпляр».
     */
    public function testCharacterNotFoundGrantsNoBonus(): void
    {
        $missingCharacterId = 999; // не вставлен в TABLE_CHARACTERS
        $handler = $this->handler();

        $handler->handle($this->task($missingCharacterId, 'Workshop'));

        $calls = $this->recordingCharacterModel($handler)->calls;
        $this->assertCount(0, $calls, 'персонаж не найден — постройка не записана, бонуса нет');
    }

    /** Acceptance 3: первая постройка ДРУГОГО типа при уже имеющемся здании → бонус начисляется. */
    public function testFirstBuildingOfDifferentTypeStillGrantsBonus(): void
    {
        $c = 3;
        $this->makeCharacter($c);
        $this->makeExistingBuilding($c, 1, 111); // Workshop уже стоит
        $handler = $this->handler();

        $handler->handle($this->task($c, 'BlastFurnace')); // другой тип здания

        $calls = $this->recordingCharacterModel($handler)->calls;
        $this->assertCount(1, $calls, 'первый экземпляр ДРУГОГО типа — бонус начислен');
        $this->assertSame(0.07, $calls[0]['agility']);
        $this->assertSame(0.02, $calls[0]['intellect']);
    }
}

/**
 * Тестовый двойник `CharacterModel` — полностью подменяет `updateAgilityAndIntellect()`,
 * не проходя через `CharacterStatsService` (тот работает на хардкод-таблице `characters`,
 * не инжектируемой — дороже и не по теме этой истории). Записывает вызовы для проверки.
 */
final class RecordingCharacterModel extends CharacterModel
{
    /** @var list<array{characterId:int,agility:float,intellect:float}> */
    public array $calls = [];

    public function updateAgilityAndIntellect(int $characterId, float $agilityIncrement, float $intellectIncrement): bool
    {
        $this->calls[] = [
            'characterId' => $characterId,
            'agility'     => $agilityIncrement,
            'intellect'   => $intellectIncrement,
        ];

        return true;
    }
}
