<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\CharacterTaskModel;
use App\Models\ExploredCellsModel;
use App\Services\Telegram\TelegramChatResolver;
use App\TaskHandlers\CompleteRobotExplorationHandler;
use App\TaskHandlers\CompleteRobotGatheringHandler;
use App\TaskHandlers\Craft\WorkbenchStandard\Armor\CraftCompletionDrifterClothesHandler;
use App\TaskHandlers\Craft\WorkbenchStandard\Armor\CraftCompletionLeatherJacketHandler;
use App\TaskHandlers\Craft\WorkbenchStandard\Armor\CraftCompletionRaggedShirtHandler;
use App\TaskHandlers\Craft\WorkbenchStandard\Armor\CraftCompletionReinforcedLeatherHandler;
use App\TaskHandlers\Craft\WorkbenchStandard\CraftCompletionTeleportBackpackHandler;
use App\TaskHandlers\Craft\WorkbenchStandard\CraftCompletionTeleportBeaconBasicHandler;
use App\TaskHandlers\DeathRouletteHandler;
use App\TaskHandlers\FoodAndWaterConsumptionHandler;
use App\TaskHandlers\MarchingTaskHandler;
use App\TaskHandlers\Objects\ClosedWarehouseHandler;
use App\TaskHandlers\Objects\ToolkitHandler;
use App\TaskHandlers\Quests\QuestExplore300CellsHandler;
use App\TaskHandlers\Quests\QuestExplore30CellsHandler;
use App\TaskHandlers\Quests\QuestExploreAllBiomesHandler;
use App\TaskHandlers\Quests\QuestFirstAidkitBasicHandler;
use App\TaskHandlers\TaxCollectionHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * web-accounts-p0-02 (ADR-188) — персонаж без Telegram (`characters.telegram_user_id = NULL`,
 * строки в `telegram_users` нет) проходит completion-handler'ы и кроны recon §C без
 * исключения и без потери награды; Telegram-персонаж в том же прогоне получает то же
 * уведомление в тот же чат, что и раньше (Ask 14).
 *
 * Схема строится исполнением настоящих классов миграций (recon §D,
 * `feedback_test_schema_must_come_from_migration`), включая
 * `2026-12-10-100010_NullableTelegramKeys`. Единственное рукописное DDL —
 * `character_tasks.task_settings`: этой колонки нет ни в одной миграции репозитория
 * (дрейф прода, `memory/map/tasks-worker.md`).
 *
 * Отправка перехвачена подклассом (`safeSendMessage`/`safeSendPhoto` → {@see SendSpy}),
 * живой Telegram не трогается.
 *
 * @internal
 */
final class WebOnlyCharacterWorkerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TG_CHAT_ID = 555000111;

    /** Миграции в порядке исполнения (FK-зависимости). */
    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-04-09-132757_AddBiomeIdToCharacters',
        '2026-05-11-150000_AddLastRespawnAtToCharacters',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-09-200000_AddQueuedStatusToCharacterTasks',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
        '2024-03-24-212921_CreateExploredCellsTable',
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-04-16-122053_CreateCraftedItemsLogTable',
        '2024-04-26-121416_CreateQuestsTable',
        '2024-04-26-192334_CreateQuestStepsTable',
        '2025-02-08-194808_CreateOutfitsTable',
        '2025-02-10-224703_CreateCharactersOutfitsTable',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2024-04-23-070627_CreateWorldObjectsTable',
        '2024-04-23-113858_CreateBiomeWorldObjectMap',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2024-09-26-083705_CreatePlayerDetectionHistoryTable',
    ];

    /**
     * Рукописное DDL только там, где миграция не исполняется на MySQL 8
     * (`CreateResourcesTable`: `BOOLEAN ... UNSIGNED` — синтаксическая ошибка) и для зависящей
     * от неё `character_resources`. Колонки — те, что читают/пишут handler'ы.
     */
    private const RAW_TABLES = [
        'resources'           => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_en VARCHAR(64) NULL, biome_id VARCHAR(64) NULL, rarity INT NULL, type VARCHAR(64) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'character_resources' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT NULL, id_resources INT NULL, quantity INT NULL, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        // Нет миграции в репозитории; читается марш-энкаунтером NPC.
        'npc_spawns'          => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL, status VARCHAR(50) NOT NULL',
        // CreateEventsTable не исполняется на MySQL 8 (TEXT с DEFAULT); DeathMessageBuilder читает только эти колонки.
        'active_events'       => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT NULL, status VARCHAR(20) NOT NULL, effect_log TEXT NULL',
    ];

    /** Таблицы, которые тест создаёт и сносит (дроп — в обратном порядке). */
    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources',
        'character_resources', 'tasks', 'character_tasks', 'explored_cells', 'crafted_items',
        'crafted_items_log', 'quests', 'quest_steps', 'outfits', 'characters_outfits', 'factions', 'character_factions',
        'world_objects', 'biome_world_object_map', 'claimed_cells', 'buildings', 'character_buildings',
        'player_detection_history', 'npc_spawns', 'active_events',
    ];

    private BaseConnection $conn;
    private int $tgUserId = 0;
    private int $tgCharId = 0;
    private int $webCharId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $conn = Database::connect();
        $this->conn = $conn;
        $this->dropTables();
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            // Нет ни в одной миграции (дрейф прода) — см. docblock класса.
            $this->conn->query('ALTER TABLE character_tasks ADD COLUMN task_settings TEXT NULL');
            foreach (self::RAW_TABLES as $t => $cols) {
                $this->conn->query("CREATE TABLE `{$t}` ({$cols}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            $this->migration('2026-12-10-100010_NullableTelegramKeys', null)->up();
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();
            throw $e;
        }
        SendSpy::$chatIds = [];
        SpyMarching::$delivered = [];
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    private function migration(string $file, ?Forge $forge): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m = new $class($forge);
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        for ($b = 1; $b <= 9; $b++) {
            $this->conn->table('biomes')->insert([
                'id' => $b, 'name' => "Биом {$b}", 'danger_level' => 1, 'survival_difficulty' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        // map.id = cell_number (инвариант прода; explored_cells.map_cell_id → map.id).
        // Сетка 5×5 (x,y = 10..14), cell_number = 1000 + y*10 + x — для раскрытия/марша.
        $rows = [];
        for ($y = 10; $y <= 14; $y++) {
            for ($x = 10; $x <= 14; $x++) {
                $rows[] = [
                    'id' => $this->cell($x, $y), 'cell_number' => $this->cell($x, $y), 'coordinate_x' => $x, 'coordinate_y' => $y,
                    'biome_id' => 1, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        // Клетки 1..300 вдали от сетки — наполнитель «исследованных» для квестовых кронов.
        for ($i = 1; $i <= 300; $i++) {
            $rows[] = [
                'id' => $i, 'cell_number' => $i, 'coordinate_x' => 500 + $i, 'coordinate_y' => 900,
                'biome_id' => 1, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $this->conn->table('map')->insertBatch($rows);
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => self::TG_CHAT_ID, 'username' => 'tg', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->tgUserId  = (int) $this->conn->insertID();
        $this->tgCharId  = $this->makeCharacter($this->tgUserId, 'TgHero');
        $this->webCharId = $this->makeCharacter(null, 'WebHero');
    }

    private function cell(int $x, int $y): int
    {
        return 1000 + $y * 10 + $x;
    }

    private function makeCharacter(?int $telegramUserId, string $name): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $telegramUserId, 'name' => $name, 'level' => 5, 'experience' => 1,
            'health' => 100, 'tired' => 100, 'strength' => 1, 'agility' => 1, 'intellect' => 1, 'gold' => 100,
            'cell_number' => $this->cell(12, 12), 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $this->conn->insertID();
    }

    /** @return array<string,mixed> */
    private function character(int $id): array
    {
        $row = $this->conn->table('characters')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    /** @return array<string,mixed> строка character_tasks (Worker метит completed до handle()). */
    private function makeTask(int $characterId, string $taskName, array $settings = [], string $start = '-1 hour'): array
    {
        $now = date('Y-m-d H:i:s');
        $task = $this->conn->table('tasks')->where('name', $taskName)->get()->getRowArray();
        if (! is_array($task)) {
            $this->conn->table('tasks')->insert([
                'name' => $taskName, 'name_rus' => $taskName, 'description' => '', 'min_duration' => 1, 'max_duration' => 1,
                'type' => 'test', 'difficulty_level' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $taskId = (int) $this->conn->insertID();
        } else {
            $taskId = (int) $task['id'];
        }
        $tgUserId = $this->character($characterId)['telegram_user_id'];
        $this->conn->table('character_tasks')->insert([
            'character_id' => $characterId, 'telegram_user_id' => $tgUserId, 'task_id' => $taskId,
            'start_time' => date('Y-m-d H:i:s', (int) strtotime($start)), 'end_time' => $now, 'status' => 'completed',
            'task_settings' => $settings === [] ? null : json_encode($settings), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->conn->table('character_tasks')->where('id', (int) $this->conn->insertID())->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    private function rows(string $table, array $where): int
    {
        return $this->conn->table($table)->where($where)->countAllResults();
    }

    // ---- schema / resolver / models -------------------------------------------------

    public function testMigrationMakesTelegramKeysNullable(): void
    {
        foreach ([['character_tasks', 'telegram_user_id'], ['explored_cells', 'telegram_user_id'], ['action_log', 'chat_id']] as [$t, $c]) {
            $row = $this->conn->query(
                'SELECT IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$t, $c]
            )->getRowArray();
            $this->assertSame('YES', $row['IS_NULLABLE'] ?? null, "{$t}.{$c}");
            $this->assertStringContainsString('unsigned', (string) ($row['COLUMN_TYPE'] ?? ''), "{$t}.{$c} keeps its type");
        }
        // Дрейф-колонки на тест-стенде нет — миграция её пропускает без ошибки (up() выше прошёл).
        $this->assertFalse($this->conn->fieldExists('id_telegram_users', 'character_resources'));
    }

    public function testMigrationTouchesDriftColumnOnlyWhenPresentAndDownRefusesNullRows(): void
    {
        $this->conn->query('ALTER TABLE character_resources ADD COLUMN id_telegram_users INT NOT NULL DEFAULT 0');
        $migration = $this->migration('2026-12-10-100010_NullableTelegramKeys', null);
        $migration->up();
        $row = $this->conn->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'character_resources' AND COLUMN_NAME = 'id_telegram_users'"
        )->getRowArray();
        $this->assertSame('YES', $row['IS_NULLABLE'] ?? null);

        $this->makeTask($this->webCharId, 'Probe');
        try {
            $migration->down();
            $this->fail('down() must refuse while web-only rows exist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('character_tasks.telegram_user_id', $e->getMessage());
        }

        $this->conn->table('character_tasks')->where('telegram_user_id', null)->delete();
        $migration->down();
        $row = $this->conn->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'character_tasks' AND COLUMN_NAME = 'telegram_user_id'"
        )->getRowArray();
        $this->assertSame('NO', $row['IS_NULLABLE'] ?? null);
    }

    public function testResolverReturnsNullForWebOnlyAndChatForTelegram(): void
    {
        $r = new TelegramChatResolver();
        $this->assertNull($r->chatIdForCharacter($this->webCharId));
        $this->assertNull($r->telegramUserIdForCharacter($this->webCharId));
        $this->assertNull($r->chatIdForCharacter(999999));
        $this->assertNull($r->chatIdForCharacter(0));
        $this->assertSame(self::TG_CHAT_ID, $r->chatIdForCharacter($this->tgCharId));
        $this->assertSame($this->tgUserId, $r->telegramUserIdForCharacter($this->tgCharId));
    }

    public function testRevealAroundWritesNullTelegramUserId(): void
    {
        $opened = (new ExploredCellsModel())->revealAround($this->webCharId, null, 12, 12, 5);
        $this->assertSame(9, $opened);
        $this->assertSame(9, $this->rows('explored_cells', ['character_id' => $this->webCharId, 'telegram_user_id' => null]));
    }

    public function testCharacterTaskModelAcceptsEmptyTelegramUserId(): void
    {
        $this->makeTask($this->webCharId, 'Probe'); // tasks row
        $taskId = (int) $this->conn->table('tasks')->where('name', 'Probe')->get()->getRowArray()['id'];
        $model  = new CharacterTaskModel();
        $id = $model->insert([
            'character_id' => $this->webCharId, 'telegram_user_id' => null, 'task_id' => $taskId,
            'start_time' => date('Y-m-d H:i:s'), 'status' => 'in_work',
        ]);
        $this->assertNotFalse($id, implode('; ', $model->errors()));
        $this->assertSame(1, $this->rows('character_tasks', ['id' => (int) $id, 'telegram_user_id' => null]));
    }

    // ---- completion handlers --------------------------------------------------------

    /** @return array<string,array{0:class-string,1:string}> handler, outfits.name_en */
    public static function armorHandlers(): array
    {
        return [
            'DrifterClothes'    => [SpyDrifterClothes::class, 'WandererClothes'],
            'LeatherJacket'     => [SpyLeatherJacket::class, 'LeatherJacket'],
            'RaggedShirt'       => [SpyRaggedShirt::class, 'RaggedShirt'],
            'ReinforcedLeather' => [SpyReinforcedLeather::class, 'ReinforcedLeatherJacket'],
        ];
    }

    /**
     * @param class-string $handlerClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('armorHandlers')]
    public function testArmorCompletionGrantsWebOnlyAndNotifiesTelegram(string $handlerClass, string $outfitNameEn): void
    {
        $this->conn->table('outfits')->insert(['name' => $outfitNameEn, 'name_en' => $outfitNameEn]);
        $outfitId = (int) $this->conn->insertID();

        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $handler = new $handlerClass();
            $handler->handle($this->makeTask($charId, 'craftArmor'));
            $this->assertSame(1, $this->rows('characters_outfits', ['character_id' => $charId, 'outfit_id' => $outfitId]), "outfit granted to #{$charId}");
        }
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds, 'only the Telegram character is notified, in its own chat');
    }

    /** @return array<string,array{0:class-string,1:string}> */
    public static function teleportHandlers(): array
    {
        return [
            'TeleportBackpack'    => [SpyTeleportBackpack::class, 'TeleportBackpack'],
            'TeleportBeaconBasic' => [SpyTeleportBeaconBasic::class, 'TeleportBeaconBasic'],
        ];
    }

    /**
     * @param class-string $handlerClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('teleportHandlers')]
    public function testTeleportCompletionGrantsWebOnlyAndNotifiesTelegram(string $handlerClass, string $itemNameEn): void
    {
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $handler = new $handlerClass();
            $handler->handle($this->makeTask($charId, 'craftTeleport'));
        }
        $item = $this->conn->table('crafted_items')->where('name_eng', $itemNameEn)->get()->getRowArray();
        $this->assertIsArray($item);
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $this->assertSame(1, $this->rows('crafted_items_log', ['character_id' => $charId, 'crafted_item_id' => $item['id']]), "item granted to #{$charId}");
        }
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    // ---- crons ------------------------------------------------------------------------

    /** @return array<string,array{0:class-string,1:string,2:int}> handler, quest title_en, explored cells needed */
    public static function exploreQuestHandlers(): array
    {
        return [
            'Explore30'  => [SpyQuestExplore30::class, 'Explore30Cells', 30],
            'Explore300' => [SpyQuestExplore300::class, 'Explore300Cells', 300],
        ];
    }

    /**
     * @param class-string $handlerClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('exploreQuestHandlers')]
    public function testExploreQuestCronRewardsBothCharactersInOneRun(string $handlerClass, string $titleEn, int $cells): void
    {
        $questId = $this->makeQuest($titleEn);
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $this->makeQuestStep($questId, $charId);
            $this->fillExplored($charId, $cells);
        }
        (new $handlerClass())->handle();

        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $this->assertSame(1, $this->rows('quest_steps', ['character_id' => $charId, 'is_completed' => 1]), "step completed for #{$charId}");
            $this->assertGreaterThan(100, (int) $this->character($charId)['gold'], "gold paid to #{$charId}");
        }
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    public function testExploreAllBiomesCronRewardsBothCharactersInOneRun(): void
    {
        $questId = $this->makeQuest('ExploreAllBiomes');
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $this->makeQuestStep($questId, $charId);
            for ($b = 1; $b <= 9; $b++) {
                $this->conn->table('explored_cells')->insert([
                    'character_id' => $charId, 'telegram_user_id' => null, 'map_cell_id' => $b, 'biome_id' => $b,
                ]);
            }
        }
        (new SpyQuestExploreAllBiomes())->handle();

        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $this->assertSame(1, $this->rows('quest_steps', ['character_id' => $charId, 'is_completed' => 1]));
            $this->assertEqualsWithDelta(3.0, (float) $this->character($charId)['experience'], 0.001, "xp paid to #{$charId}");
        }
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    public function testFirstAidkitQuestCronRewardsBothCharactersInOneRun(): void
    {
        $questId = $this->makeQuest('FirstAidkitBasic');
        $this->conn->table('crafted_items')->insert(['name_rus' => 'Аптечка', 'name_eng' => 'FirstAidKit']);
        $itemId = (int) $this->conn->insertID();
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $this->makeQuestStep($questId, $charId);
            $task = $this->makeTask($charId, 'craftFirstAidKit');
            $this->conn->table('crafted_items_log')->insert([
                'character_id' => $charId, 'task_id' => $task['task_id'], 'crafted_item_id' => $itemId, 'quantity' => 1,
            ]);
        }
        (new SpyQuestFirstAidkit())->handle();

        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $this->assertSame(1, $this->rows('quest_steps', ['character_id' => $charId, 'is_completed' => 1]));
            $this->assertSame(1600, (int) $this->character($charId)['gold'], "gold paid to #{$charId}");
        }
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    // ---- robots / march ---------------------------------------------------------------

    public function testRobotExplorationRevealsForWebOnlyAndNotifiesTelegram(): void
    {
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $handler = new SpyRobotExploration();
            $prop = new \ReflectionProperty(CompleteRobotExplorationHandler::class, 'playerDetectionService');
            $prop->setValue($handler, new WebOnlyStubDetector());
            $handler->handle($this->makeTask($charId, 'robotExploration', ['coordinates' => ['x' => 12, 'y' => 12]], '-6 minutes'));
            $this->assertGreaterThan(0, $this->rows('explored_cells', ['character_id' => $charId]), "cells revealed for #{$charId}");
        }
        $webOpened = $this->rows('explored_cells', ['character_id' => $this->webCharId]);
        $this->assertSame($webOpened, $this->rows('explored_cells', ['character_id' => $this->webCharId, 'telegram_user_id' => null]), 'web-only rows carry NULL telegram_user_id');
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    public function testRobotGatheringCreditsWebOnlyAndNotifiesTelegram(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('buildings')->insert([
            'name_ru' => 'Мастерская робототехники', 'name_en' => 'RoboticsWorkshop', 'description' => '', 'building_type' => 'engineering',
            'hp' => 1, 'construction_time' => 1, 'tax' => 0, 'level' => 1, 'usage' => 'personal', 'required_resources' => '{}',
            'min_character_level' => 1, 'effects' => '{}', 'days_until_disappearance' => 0, 'usage_count' => 0,
        ]);
        $workshopId = (int) $this->conn->insertID();
        $this->conn->table('resources')->insert(['name' => 'Кристалл', 'name_en' => 'Crystal', 'biome_id' => '1', 'rarity' => 10, 'type' => 'mineral']);
        $resourceId = (int) $this->conn->insertID();
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $this->conn->table('claimed_cells')->insert(['character_id' => $charId, 'map_cell_id' => $this->cell(12, 12), 'claimed_at' => $now, 'status' => 'active']);
            $this->conn->table('character_buildings')->insert([
                'character_id' => $charId, 'building_id' => $workshopId, 'map_cell_id' => $this->cell(12, 12), 'amount' => 1,
                'character_level_during_construction' => 1, 'hp' => 1, 'level' => 1, 'built_at' => $now, 'building_type' => 'engineering',
                'tax' => 0, 'usage' => 'personal', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            (new SpyRobotGathering())->handle($this->makeTask($charId, 'robotGathering', [], '-4 hours'));
            $row = $this->conn->table('character_resources')->where(['id_characters' => $charId, 'id_resources' => $resourceId])->get()->getRowArray();
            $this->assertIsArray($row, "resources credited to #{$charId}");
            $this->assertGreaterThan(0, (int) $row['quantity']);
        }
        $this->assertNotSame([], SendSpy::$chatIds);
        $this->assertSame([self::TG_CHAT_ID], array_values(array_unique(SendSpy::$chatIds)), 'only the Telegram character is notified');
    }

    public function testMarchStepMovesWebOnlyWithNullKeysAndKeepsTelegramChat(): void
    {
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $settings = ['heading' => 'north', 'steps_planned' => 3, 'steps_done' => 0];
            if ($charId === $this->tgCharId) {
                $settings += ['msg_chat_id' => self::TG_CHAT_ID, 'msg_id' => 999];
            }
            $task    = $this->makeTask($charId, 'Marching', $settings);
            $handler = new SpyMarching(new WebOnlyStubDetector(), new WebOnlyStubTowerAlerts(), new WebOnlyStubObjectSignal());
            $handler->handle($task);

            $this->assertSame($this->cell(12, 11), (int) $this->character($charId)['cell_number'], "#{$charId} moved north");
            $this->assertSame(9, $this->rows('explored_cells', ['character_id' => $charId]), "3x3 revealed for #{$charId}");
            $next = $this->conn->table('character_tasks')->where(['character_id' => $charId, 'status' => 'in_work'])->get()->getResultArray();
            $this->assertCount(1, $next, "next step spawned for #{$charId}");
            $expectedTg = $charId === $this->tgCharId ? $this->tgUserId : null;
            $this->assertSame($expectedTg, $next[0]['telegram_user_id'] === null ? null : (int) $next[0]['telegram_user_id']);
        }
        $this->assertSame(9, $this->rows('explored_cells', ['character_id' => $this->webCharId, 'telegram_user_id' => null]));
        // Веб-персонаж доходит до deliverMarchMessage, но чата у него нет; Telegram — свой чат.
        $this->assertSame([null, self::TG_CHAT_ID], SpyMarching::$delivered);
    }

    // ---- crons: notification sites (reward first, notify only with a chat) -------------

    public function testFoodAndWaterDamagesBothAndNotifiesOnlyTelegram(): void
    {
        $this->conn->table('characters')->where('id >', 0)->update(['created_at' => date('Y-m-d H:i:s', time() - 3 * 86400)]);
        $handler = new SpyFoodAndWater();
        $m = new ReflectionMethod(FoodAndWaterConsumptionHandler::class, 'subtractResources');
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $result = $m->invoke($handler, $this->character($charId), 1.0, 1.0);
            $this->assertTrue($result['healthSubtracted'], "hunger applied to #{$charId}");
            $this->assertLessThan(100.0, (float) $this->character($charId)['health'], "health reduced for #{$charId}");
        }
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    public function testToolkitAwardsBothAndNotifiesOnlyTelegram(): void
    {
        $this->conn->table('crafted_items')->insert(['name_rus' => 'Набор инструментов', 'name_eng' => 'Toolkit']);
        $itemId = (int) $this->conn->insertID();
        $m = new ReflectionMethod(ToolkitHandler::class, 'awardContents');
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $m->invoke(new SpyToolkit(), $this->character($charId), ['crafted_items' => ['Toolkit' => 1]]);
            $this->assertSame(1, $this->rows('crafted_items_log', ['character_id' => $charId, 'crafted_item_id' => $itemId]), "toolkit granted to #{$charId}");
        }
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    public function testClosedWarehousePromptsOnlyTelegram(): void
    {
        $action = new ReflectionMethod(ClosedWarehouseHandler::class, 'sendActionMessage');
        $tools  = new ReflectionMethod(ClosedWarehouseHandler::class, 'sendInsufficientToolsMessage');
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $h = new SpyClosedWarehouse();
            $action->invoke($h, ['id' => 1, 'world_object_id' => 1, 'map_id' => $this->cell(12, 12)], $this->character($charId));
            $tools->invoke($h, $this->character($charId), []);
        }
        $this->assertSame([self::TG_CHAT_ID, self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    public function testDeathRouletteMessageSkipsWebOnlyAndReachesTelegram(): void
    {
        $m = new ReflectionMethod(DeathRouletteHandler::class, 'sendDeathMessage');
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $m->invoke(new SpyDeathRoulette(), $this->character($charId), []);
        }
        $this->assertSame([self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    public function testTaxNotificationsSkipWebOnlyAndReachTelegram(): void
    {
        $text  = new ReflectionMethod(TaxCollectionHandler::class, 'sendTelegramNotification');
        $photo = new ReflectionMethod(TaxCollectionHandler::class, 'sendTelegramNotificationPhoto');
        foreach ([$this->webCharId, $this->tgCharId] as $charId) {
            $h = new SpyTaxCollection();
            $text->invoke($h, $this->character($charId), 'tax');
            $photo->invoke($h, $this->character($charId), 'tax');
        }
        $this->assertSame([self::TG_CHAT_ID, self::TG_CHAT_ID], SendSpy::$chatIds);
    }

    private function makeQuest(string $titleEn): int
    {
        $this->conn->table('quests')->insert(['title_ru' => $titleEn, 'title_en' => $titleEn, 'description' => '']);

        return (int) $this->conn->insertID();
    }

    private function makeQuestStep(int $questId, int $charId): void
    {
        $this->conn->table('quest_steps')->insert([
            'quest_id' => $questId, 'character_id' => $charId, 'step_order' => 1, 'is_completed' => 0,
        ]);
    }

    private function fillExplored(int $charId, int $n): void
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['character_id' => $charId, 'telegram_user_id' => null, 'map_cell_id' => $i, 'biome_id' => 1];
        }
        $this->conn->table('explored_cells')->insertBatch($rows);
    }
}

/** Перехват отправки: chat id каждой попытки уведомления. */
final class SendSpy
{
    /** @var list<int|string> */
    public static array $chatIds = [];
}

trait RecordsSends
{
    protected function safeSendMessage($chatId, string $text, array $extra = []): void
    {
        SendSpy::$chatIds[] = $chatId;
    }

    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
        SendSpy::$chatIds[] = $chatId;
    }
}

final class SpyDrifterClothes extends CraftCompletionDrifterClothesHandler
{
    use RecordsSends;
}
final class SpyLeatherJacket extends CraftCompletionLeatherJacketHandler
{
    use RecordsSends;
}
final class SpyRaggedShirt extends CraftCompletionRaggedShirtHandler
{
    use RecordsSends;
}
final class SpyReinforcedLeather extends CraftCompletionReinforcedLeatherHandler
{
    use RecordsSends;
}
final class SpyTeleportBackpack extends CraftCompletionTeleportBackpackHandler
{
    use RecordsSends;
}
final class SpyTeleportBeaconBasic extends CraftCompletionTeleportBeaconBasicHandler
{
    use RecordsSends;
}
final class SpyQuestExplore30 extends QuestExplore30CellsHandler
{
    use RecordsSends;
}
final class SpyQuestExplore300 extends QuestExplore300CellsHandler
{
    use RecordsSends;
}
final class SpyQuestExploreAllBiomes extends QuestExploreAllBiomesHandler
{
    use RecordsSends;
}
final class SpyQuestFirstAidkit extends QuestFirstAidkitBasicHandler
{
    use RecordsSends;
}
final class SpyRobotExploration extends CompleteRobotExplorationHandler
{
    use RecordsSends;
}
final class SpyRobotGathering extends CompleteRobotGatheringHandler
{
    use RecordsSends;
}
final class SpyFoodAndWater extends FoodAndWaterConsumptionHandler
{
    use RecordsSends;
}
final class SpyToolkit extends ToolkitHandler
{
    use RecordsSends;
}
final class SpyClosedWarehouse extends ClosedWarehouseHandler
{
    use RecordsSends;
}
final class SpyDeathRoulette extends DeathRouletteHandler
{
    use RecordsSends;
}
final class SpyTaxCollection extends TaxCollectionHandler
{
    use RecordsSends;
}

/**
 * Марш шлёт через Request::editMessageText/sendMessage, не через safeSend*. Спай пишет chat id,
 * который увидел бы настоящий deliverMarchMessage(), и зовёт родителя только без msg_chat_id
 * (web-only): там родитель обязан вернуться до любого Request::*.
 */
final class SpyMarching extends MarchingTaskHandler
{
    /** @var list<int|null> */
    public static array $delivered = [];

    protected function cellsPerTick(array $vehicleProfile = []): int
    {
        return 1;
    }

    protected function deliverMarchMessage(int $telegramUserId, array $s, string $text, array $keyboardRows): void
    {
        $raw = $s['msg_chat_id'] ?? null;
        if (is_numeric($raw)) {
            self::$delivered[] = (int) $raw;

            return;
        }
        $resolve = new ReflectionMethod(MarchingTaskHandler::class, 'resolveChatId');
        self::$delivered[] = $resolve->invoke($this, $telegramUserId);
        parent::deliverMarchMessage($telegramUserId, $s, $text, $keyboardRows);
    }
}

/** Детектор-стаб: настоящий PlayerDetectionService шлёт в Telegram. */
final class WebOnlyStubDetector extends \App\Services\Player\PlayerDetectionService
{
    public function detectNearbyPlayers(int $characterId): bool
    {
        return false;
    }
}
final class WebOnlyStubTowerAlerts extends \App\Services\PVE\TowerAlertService
{
    public function notifyTowersNear(int $moverId, int $newX, int $newY): int
    {
        return 0;
    }
}
final class WebOnlyStubObjectSignal extends \App\Services\World\ObjectSignalService
{
    public function signalNearbyObjects(int $moverId, int $newX, int $newY): int
    {
        return 0;
    }
}
