<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\CompleteRobotGatheringHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F24 (s2-conditional-writes-09) — выдача сбора робота пишется относительно, а не
 * `quantity = снимок + n`.
 *
 * 1) Строки ресурса нет — `CompleteRobotGatheringHandler::handle()` создаёт ровно одну строку
 *    (это же мерит размер выдачи `A`: `mt_srand` фиксирует разброс, время задачи фиксировано).
 * 2) Строка есть (`A`). Сразу после первого запроса handler'а к `character_resources` отдельное
 *    соединение зачисляет +7 (параллельная выдача от того же снимка). Ожидание — сумма `A + A + 7`,
 *    а не последняя запись `A + A`.
 *
 * Схема — исполнением настоящих классов миграций (`character_tasks` с NOT NULL и ENUM статуса);
 * рукописно — `character_tasks.task_settings` (нет ни в одной миграции), `resources` (миграция не
 * исполняется на MySQL 8) и `character_resources` (NOT NULL-колонки миграции, без FK на `resources`).
 * Отправка перехвачена подклассом, живой Telegram не трогается.
 *
 * @internal
 */
final class RobotGatheringRelativeAwardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED_REASON = 'interrupt penalty applied twice, or robot award lost a concurrent increment';

    private const FOREIGN = 7;

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
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
    ];

    private const RAW_TABLES = [
        'resources'           => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_en VARCHAR(64) NULL, biome_id VARCHAR(64) NULL, rarity INT NULL, type VARCHAR(64) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'character_resources' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL, id_resources INT UNSIGNED NOT NULL, quantity BIGINT UNSIGNED DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources', 'character_resources',
        'tasks', 'character_tasks', 'crafted_items', 'factions', 'claimed_cells', 'buildings', 'character_buildings',
    ];

    private const BASE_CELL = 1000;

    private BaseConnection $conn;

    /** @var (callable(object): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect();
        $this->dropTables();
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->conn->query('ALTER TABLE character_tasks ADD COLUMN task_settings TEXT NULL');
            foreach (self::RAW_TABLES as $t => $cols) {
                $this->conn->query("CREATE TABLE `{$t}` ({$cols}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Throwable $e) {
            $this->dropTables();
            throw $e;
        }
        $this->conn->resetDataCache();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->listener !== null) {
                Events::removeListener('DBQuery', $this->listener);
                $this->listener = null;
            }
            $this->dropTables();
        } finally {
            parent::tearDown();
        }
    }

    public function testConcurrentAwardIsSummedNotOverwritten(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('biomes')->insert([
            'id' => 1, 'name' => 'Биом 1', 'danger_level' => 1, 'survival_difficulty' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->conn->table('map')->insert([
            'id' => self::BASE_CELL, 'cell_number' => self::BASE_CELL, 'coordinate_x' => 10, 'coordinate_y' => 10,
            'biome_id' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => random_int(770_000_000, 779_999_999), 'username' => 'robo', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $tgUid = (int) $this->conn->insertID();
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'name' => 'Robo', 'level' => 5, 'experience' => 1, 'health' => 100, 'tired' => 100,
            'strength' => 1, 'agility' => 1, 'intellect' => 1, 'gold' => 0, 'cell_number' => self::BASE_CELL,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $charId = (int) $this->conn->insertID();
        $this->conn->table('buildings')->insert([
            'name_ru' => 'Мастерская робототехники', 'name_en' => 'RoboticsWorkshop', 'description' => '', 'building_type' => 'engineering',
            'hp' => 1, 'construction_time' => 1, 'tax' => 0, 'level' => 1, 'usage' => 'personal', 'required_resources' => '{}',
            'min_character_level' => 1, 'effects' => '{}', 'days_until_disappearance' => 0, 'usage_count' => 0,
        ]);
        $workshopId = (int) $this->conn->insertID();
        $this->conn->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => self::BASE_CELL, 'claimed_at' => $now, 'status' => 'active',
        ]);
        $this->conn->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $workshopId, 'map_cell_id' => self::BASE_CELL, 'amount' => 1,
            'character_level_during_construction' => 1, 'hp' => 1, 'level' => 1, 'built_at' => $now, 'building_type' => 'engineering',
            'tax' => 0, 'usage' => 'personal', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->conn->table('resources')->insert(['name' => 'Кристалл', 'name_en' => 'Crystal', 'biome_id' => '1', 'rarity' => 10, 'type' => 'mineral']);
        $resourceId = (int) $this->conn->insertID();
        $this->conn->table('tasks')->insert([
            'name' => 'robotGathering', 'name_rus' => 'Сбор роботом', 'description' => '', 'min_duration' => 1, 'max_duration' => 1,
            'type' => 'test', 'difficulty_level' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $taskId = (int) $this->conn->insertID();

        // 1) Строки нет — выдача создаёт одну строку; её количество и есть размер выдачи A.
        mt_srand(424242);
        (new RelativeAwardRobotGathering())->handle($this->makeTask($charId, $tgUid, $taskId));
        $rows = $this->resourceRows($charId, $resourceId);
        $this->assertCount(1, $rows, self::RED_REASON . ' (missing row → exactly one row)');
        $award = (int) $rows[0]['quantity'];
        $this->assertGreaterThan(0, $award, 'предусловие: робот что-то добыл');

        // 2) Строка есть; параллельная выдача +7 вклинивается после первого запроса handler'а к ней.
        $fired = false;
        $this->listener = function (object $query) use (&$fired, $charId, $resourceId): void {
            if ($fired) {
                return;
            }
            $sql = ltrim((string) $query->getQuery());
            if (! str_contains($sql, 'character_resources')
                || (! str_starts_with($sql, 'SELECT') && ! str_starts_with($sql, 'UPDATE'))) {
                return;
            }
            $fired = true;
            Database::connect('tests', false)->query(
                'UPDATE character_resources SET quantity = quantity + ? WHERE id_characters = ? AND id_resources = ?',
                [self::FOREIGN, $charId, $resourceId]
            );
        };
        Events::on('DBQuery', $this->listener);

        mt_srand(424242);
        (new RelativeAwardRobotGathering())->handle($this->makeTask($charId, $tgUid, $taskId));

        Events::removeListener('DBQuery', $this->listener);
        $this->listener = null;

        $this->assertTrue($fired, 'предусловие: параллельная выдача вклинилась');
        $rows = $this->resourceRows($charId, $resourceId);
        $this->assertCount(1, $rows, self::RED_REASON . ' (still one row)');
        $this->assertSame($award + $award + self::FOREIGN, (int) $rows[0]['quantity'], self::RED_REASON);
    }

    /** @return array<string,mixed> строка character_tasks (Worker метит completed до handle()). */
    private function makeTask(int $charId, int $tgUid, int $taskId): array
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('character_tasks')->insert([
            'character_id' => $charId, 'telegram_user_id' => $tgUid, 'task_id' => $taskId,
            'start_time' => '2026-01-01 08:00:00', 'end_time' => '2026-01-01 12:00:00', 'status' => 'completed',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->conn->table('character_tasks')->where('id', (int) $this->conn->insertID())->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function resourceRows(int $charId, int $resourceId): array
    {
        return array_values($this->conn->table('character_resources')
            ->where(['id_characters' => $charId, 'id_resources' => $resourceId])
            ->get()->getResultArray());
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
}

final class RelativeAwardRobotGathering extends CompleteRobotGatheringHandler
{
    protected function safeSendMessage($chatId, string $text, array $extra = []): void
    {
    }

    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
    }
}
