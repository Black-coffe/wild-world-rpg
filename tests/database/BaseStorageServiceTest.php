<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Services\Bases\BaseCheckService;
use App\Services\Bases\BaseStorageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * w2-n6-trade-storage-01 — ядро склада базы (`BaseStorageService`): гейт «на базе» в ядре,
 * выдача и сдача не задваиваются повтором, откат не выдаёт «Забрано». Гейт — настоящий
 * `BaseCheckService` по `claimed_cells` (игрок на клетке 5, база на клетке 5 или 9).
 * Схема — миграциями; `resources`/`character_resources` — DDL-копией (CreateResourcesTable
 * не идёт на MySQL 8, как в `CraftOrderServiceTest`).
 *
 * @internal
 */
final class BaseStorageServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2026-05-29-500000_W3aCreateBaseStorage',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'claimed_cells', 'base_storage',
        'game_settings', 'resources', 'character_resources',
    ];

    private const CHAR = 1;

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, icon_text VARCHAR(255) NULL,'
                . ' rarity INT NULL, sell_price INT NULL, buy_price INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testWithdrawOneMovesResourceAndSecondCallFindsNothing(): void
    {
        $this->onBase(true);
        $this->store(1, 30);

        $first  = $this->service()->withdrawOne(self::CHAR, 1);
        $second = $this->service()->withdrawOne(self::CHAR, 1);

        $this->assertSame(BaseStorageService::OK, $first['code']);
        $this->assertSame(30, $first['withdrawn']);
        $this->assertSame('Вода', $first['name']);
        $this->assertSame(BaseStorageService::MISSING, $second['code'], 'повтор не выдаёт второй раз');
        $this->assertSame(30, $this->backpack(1));
        $this->assertSame(0, $this->stored(1));
    }

    public function testWithdrawAllTwiceCreditsOnlyWhatWasStored(): void
    {
        $this->onBase(true);
        $this->store(1, 30);
        $this->store(1, 5); // вторая строка того же вида: склад не держит уникальности пары
        $this->store(2, 12);

        $first  = $this->service()->withdrawAll(self::CHAR);
        $second = $this->service()->withdrawAll(self::CHAR);

        $this->assertSame(BaseStorageService::OK, $first['code']);
        $this->assertSame(47, $first['units']);
        $this->assertSame(BaseStorageService::EMPTY, $second['code']);
        $this->assertSame(35, $this->backpack(1));
        $this->assertSame(12, $this->backpack(2));
    }

    public function testOffBaseRefusesEveryMutationAndMovesNothing(): void
    {
        $this->onBase(false);
        $this->store(1, 30);
        $this->carry(2, 8);

        $svc = $this->service();
        $this->assertSame(BaseStorageService::OFF_BASE, $svc->withdrawOne(self::CHAR, 1)['code']);
        $this->assertSame(BaseStorageService::OFF_BASE, $svc->withdrawAll(self::CHAR)['code']);
        $this->assertSame(BaseStorageService::OFF_BASE, $svc->depositOne(self::CHAR, 2)['code']);
        $this->assertSame(BaseStorageService::OFF_BASE, $svc->depositAll(self::CHAR)['code']);

        $this->assertSame(30, $this->stored(1));
        $this->assertSame(8, $this->backpack(2));
        $this->assertSame(0, $this->stored(2));
    }

    public function testRealBaseCheckGatesByClaimedCell(): void
    {
        // Без подмены гейта: игрок на клетке 5; активная база на клетке 9 — не на базе.
        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (1, 9, 'active')");
        $svc = new BaseStorageService();
        $this->assertFalse($svc->isOnBase(self::CHAR));

        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (1, 5, 'active')");
        $this->assertTrue((new BaseStorageService())->isOnBase(self::CHAR));
    }

    public function testDepositOneThenRepeatIsNotCarried(): void
    {
        $this->onBase(true);
        $this->carry(2, 8);

        $first  = $this->service()->depositOne(self::CHAR, 2, 5);
        $second = $this->service()->depositOne(self::CHAR, 2, 5);

        $this->assertSame(BaseStorageService::OK, $first['code']);
        $this->assertSame(8, $first['quantity']);
        $this->assertSame(BaseStorageService::NOT_CARRIED, $second['code']);
        $this->assertSame(8, $this->stored(2));
        $this->assertSame(0, $this->backpack(2));
        $this->assertSame(BaseStorageService::BAD_RESOURCE, $this->service()->depositOne(self::CHAR, 0)['code']);
    }

    public function testDepositAllMovesEverythingOnceAndLogs(): void
    {
        $this->onBase(true);
        $this->carry(1, 3);
        $this->carry(2, 8);

        $first  = $this->service()->depositAll(self::CHAR, 5, 777);
        $second = $this->service()->depositAll(self::CHAR, 5, 777);

        $this->assertSame(['code' => BaseStorageService::OK, 'units' => 11, 'kinds' => 2, 'skipped' => 0], $first);
        $this->assertSame(BaseStorageService::EMPTY, $second['code']);
        $this->assertSame(3, $this->stored(1));
        $this->assertSame(8, $this->stored(2));
        $log = $this->conn->query("SELECT chat_id, description FROM action_log WHERE action_name = 'BASE_STORAGE_DEPOSIT_ALL'")->getResultArray();
        $this->assertCount(1, $log);
        $this->assertSame('777', (string) $log[0]['chat_id']);
    }

    public function testWithdrawOneRollsBackWhenCreditingBackpackFails(): void
    {
        $this->onBase(true);
        $this->store(1, 30);
        $failing = new class () extends CharacterResourceModel {
            public function increaseResources($characterId, $resourceId, $amount)
            {
                throw new \RuntimeException('boom');
            }
        };

        $result = (new BaseStorageService(new BaseStorageModel(), $failing, $this->gate(true)))->withdrawOne(self::CHAR, 1);

        $this->assertSame(BaseStorageService::FAILED, $result['code']);
        $this->assertSame(30, $this->stored(1), 'откат: ресурс остался на складе');
        $this->assertSame(0, $this->backpack(1));
    }

    public function testStorageModelSortsAndTotals(): void
    {
        $this->onBase(true);
        $this->store(1, 30);
        $this->store(2, 12);

        $model = $this->service()->storageModel(self::CHAR, 'qty');

        $this->assertSame('qty', $model['mode']);
        $this->assertSame(42, $model['total_units']);
        $this->assertTrue($model['on_base']);
        $this->assertSame(['Вода', 'Глина'], array_column($model['rows'], 'name'));
        $this->assertSame([], $this->service()->storageModel(2)['rows']);
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private bool $onBase = false;

    private function onBase(bool $on): void
    {
        $this->onBase = $on;
    }

    private function service(): BaseStorageService
    {
        return new BaseStorageService(null, null, $this->gate($this->onBase));
    }

    private function gate(bool $on): BaseCheckService
    {
        return new class ($on) extends BaseCheckService {
            public function __construct(private readonly bool $on)
            {
            }

            public function checkBaseStatus(int $characterId): array
            {
                return ['hasBase' => true, 'isOnBase' => $this->on, 'x' => null, 'y' => null];
            }
        };
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (9, 9, 8, 0, 1)');
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 555003, 'Тест')");
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
        );
        $this->conn->query("INSERT INTO resources (id, name, icon_text) VALUES (1, 'Вода', '💧'), (2, 'Глина', '🟫')");
    }

    private function store(int $resourceId, int $qty): void
    {
        $this->conn->table('base_storage')->insert([
            'character_id' => self::CHAR, 'resource_id' => $resourceId, 'quantity' => $qty,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function carry(int $resourceId, int $qty): void
    {
        $this->conn->table('character_resources')->insert([
            'id_characters' => self::CHAR, 'id_resources' => $resourceId, 'quantity' => $qty,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function stored(int $resourceId): int
    {
        $row = $this->conn->query('SELECT COALESCE(SUM(quantity), 0) AS q FROM base_storage WHERE character_id = ? AND resource_id = ?', [self::CHAR, $resourceId])->getRowArray();

        return (int) ($row['q'] ?? 0);
    }

    private function backpack(int $resourceId): int
    {
        $row = $this->conn->query('SELECT COALESCE(SUM(quantity), 0) AS q FROM character_resources WHERE id_characters = ? AND id_resources = ?', [self::CHAR, $resourceId])->getRowArray();

        return (int) ($row['q'] ?? 0);
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
