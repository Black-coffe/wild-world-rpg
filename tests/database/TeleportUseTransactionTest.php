<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Camp\TeleportUseAction;
use App\Services\Player\TeleportUse\TeleportExecutor;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;

/**
 * s2-conditional-writes-11 (ask 12) — списание заряда телепорта (`TeleportItemConsumer`, CAS
 * exploit-fix-08) и перемещение (`TeleportExecutor::teleport`) идут в одной транзакции
 * `TeleportUseAction`: сбой перемещения откатывает списание, заряд остаётся у игрока.
 *
 * Форма — не гонка: двойник `TeleportExecutor`, бросающий в `teleport()`, подаётся через
 * конструктор `TeleportUseAction`; путь портативного телепорта зовётся напрямую (гейты
 * `handle()` — пользователь, переезд, солнечная станция — к этому пункту не относятся).
 * Telegram не живой: ответы Bot API глушит guzzle-обработчик.
 *
 * Схема — как у `tests/exploit-poc/TeleportChargeTest.php`: персистентные таблицы стенда,
 * свои строки по id, своё созданное — DROP.
 *
 * @internal
 */
final class TeleportUseTransactionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<string> */
    private array $createdTables = [];

    /** @var array<string,list<int>> */
    private array $ownedRows = [];

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        try {
            $this->createSchema();
        } catch (\Throwable $e) {
            $this->dropOwnTables();

            throw $e;
        }

        new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot');
        LongmanRequest::setClient(new Client(['handler' => static fn (): PromiseInterface => Create::promiseFor(
            new Response(200, [], '{"ok":true,"result":true}')
        )]));
    }

    protected function tearDown(): void
    {
        foreach ($this->ownedRows as $table => $ids) {
            if ($ids !== [] && ! in_array($table, $this->createdTables, true)) {
                $this->conn->table($table)->whereIn('id', $ids)->delete();
            }
        }
        $this->ownedRows = [];
        $this->dropOwnTables();
        parent::tearDown();
    }

    public function testMoveFailureKeepsTheCharge(): void
    {
        [$characterId, $logId, $startCell] = $this->seedPortable(2);

        $failing = new class () extends TeleportExecutor {
            public function teleport(int $characterId, array $claimedCell, array $mapRow, array $statDeltas = []): void
            {
                throw new \RuntimeException('move failed (test double)');
            }
        };

        try {
            $this->runPortable($characterId, $failing);
        } catch (\Throwable) {
            // Исход судим по состоянию БД, а не по тому, всплыло ли исключение.
        }

        $this->assertSame(2, $this->chargesLeft($logId), 'teleport charge consumed although the move failed');
        $this->assertSame($startCell, $this->cellOf($characterId), 'персонаж сдвинулся, хотя перемещение упало');
        $this->assertSame(0, $this->conn->transDepth, 'транзакция осталась открытой после отказа перемещения');
    }

    public function testHonestTeleportSpendsExactlyOneCharge(): void
    {
        [$characterId, $logId, , $baseCell] = $this->seedPortable(2);

        $this->runPortable($characterId, new TeleportExecutor());

        $this->assertSame(1, $this->chargesLeft($logId), 'честный телепорт обязан списать ровно один заряд (2 → 1)');
        $this->assertSame($baseCell, $this->cellOf($characterId), 'честный телепорт обязан перенести персонажа на базу');
        $this->assertSame(0, $this->conn->transDepth, 'транзакция осталась открытой после успешного телепорта');
    }

    /** @return array{0:int,1:int,2:int,3:int} [characterId, logId, startCell, baseCell] */
    private function seedPortable(int $charges): array
    {
        $startCell = random_int(700_000, 799_999);
        $baseCell  = random_int(800_000, 899_999);

        $characterId = $this->insertOwn('characters', ['level' => 10, 'gold' => 0, 'cell_number' => $startCell]);
        $this->insertOwn('map', ['cell_number' => $startCell, 'biome_id' => 1]);
        $this->insertOwn('map', ['cell_number' => $baseCell, 'biome_id' => 2]);
        $this->insertOwn('claimed_cells', ['character_id' => $characterId, 'map_cell_id' => $baseCell, 'status' => 'active']);

        $itemId = $this->catalogRowId('PortableTeleport');
        $logId  = $this->insertOwn('crafted_items_log', [
            'character_id' => $characterId, 'crafted_item_id' => $itemId, 'quantity' => 1, 'durability_count' => $charges,
        ]);

        return [$characterId, $logId, $startCell, $baseCell];
    }

    private function runPortable(int $characterId, TeleportExecutor $executor): void
    {
        $cbq = new CallbackQuery([
            'id'            => 'cbq-teleport',
            'from'          => ['id' => 771000111, 'is_bot' => false, 'first_name' => 'Тест'],
            'message'       => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => 771000111, 'type' => 'private'], 'text' => 'x'],
            'chat_instance' => 'ci',
            'data'          => 'TeleportUse_Portable',
        ]);
        $action    = new TeleportUseAction($cbq, $executor);
        $character = $this->conn->table('characters')->where('id', $characterId)->get()->getRowArray();
        $this->assertIsArray($character);

        $method = new \ReflectionMethod($action, 'usePortableTeleport');
        $method->invoke($action, $character, null);
    }

    private function catalogRowId(string $nameEng): int
    {
        $row = $this->conn->table('crafted_items')->where('name_eng', $nameEng)->orderBy('id', 'ASC')->limit(1)->get()->getRowArray();
        if (is_array($row) && isset($row['id'])) {
            return (int) $row['id'];
        }

        return $this->insertOwn('crafted_items', ['name_eng' => $nameEng, 'name_rus' => 'Портативный телепорт', 'durability_count' => 3]);
    }

    /** @param array<string,mixed> $row */
    private function insertOwn(string $table, array $row): int
    {
        $this->conn->table($table)->insert($row);
        $id = (int) $this->conn->insertID();
        $this->ownedRows[$table][] = $id;

        return $id;
    }

    private function chargesLeft(int $logId): ?int
    {
        $row = $this->conn->table('crafted_items_log')->where('id', $logId)->get()->getRowArray();

        return is_array($row) && is_numeric($row['durability_count'] ?? null) ? (int) $row['durability_count'] : null;
    }

    private function cellOf(int $characterId): int
    {
        $row = $this->conn->table('characters')->where('id', $characterId)->get()->getRowArray();

        return is_array($row) && is_numeric($row['cell_number'] ?? null) ? (int) $row['cell_number'] : 0;
    }

    private function createSchema(): void
    {
        $this->createTableIfMissing('characters', ['id', 'level', 'gold', 'cell_number', 'biome_id'], '
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NULL,
                level INT DEFAULT 1,
                gold INT DEFAULT 0,
                experience DECIMAL(15,2) DEFAULT 0,
                cell_number INT DEFAULT 0,
                biome_id INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) AUTO_INCREMENT=' . random_int(2_000_000, 2_999_999));
        $this->createTableIfMissing('claimed_cells', ['id', 'character_id', 'map_cell_id', 'status'], "
            CREATE TABLE claimed_cells (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT UNSIGNED NOT NULL,
                map_cell_id INT UNSIGNED NOT NULL,
                claimed_at DATETIME NULL,
                status ENUM('active','abandoned') DEFAULT 'active'
            )
        ");
        $this->createTableIfMissing('map', ['id', 'cell_number', 'biome_id'], '
            CREATE TABLE map (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL,
                coordinate_x INT NULL,
                coordinate_y INT NULL,
                biome_id INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('crafted_items', ['id', 'name_rus', 'name_eng', 'durability_count'], '
            CREATE TABLE crafted_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name_rus VARCHAR(255) NULL,
                name_eng VARCHAR(255) NULL,
                type VARCHAR(100) NULL,
                durability_count INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('crafted_items_log', ['id', 'character_id', 'crafted_item_id', 'quantity', 'durability_count', 'custom_setting'], '
            CREATE TABLE crafted_items_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT UNSIGNED NULL,
                crafted_item_id INT UNSIGNED NULL,
                task_id INT UNSIGNED NULL,
                type VARCHAR(100) NULL,
                quantity INT DEFAULT 1,
                durability_count INT NULL,
                durability_time DATE NULL,
                custom_setting TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
    }

    /** @param list<string> $requiredColumns */
    private function createTableIfMissing(string $table, array $requiredColumns, string $ddl): void
    {
        if ($this->conn->tableExists($table)) {
            if (array_diff($requiredColumns, $this->conn->getFieldNames($table)) === []) {
                return;
            }
            $this->conn->query("DROP TABLE IF EXISTS {$table}");
            $this->conn->resetDataCache();
        }

        $this->conn->query($ddl);
        $this->createdTables[] = $table;
    }

    private function dropOwnTables(): void
    {
        foreach (array_reverse($this->createdTables) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->createdTables = [];
    }
}
