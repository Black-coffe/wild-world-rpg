<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\BaseStorageModel;
use App\Services\Bases\BaseCheckService;
use App\Services\Bases\BaseStorageService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s2-conditional-writes-10 — регресс одновременного «🎒 Забрать всё».
 *
 * Не гонка, а снимок (форма теста спеки): модель склада записывает каждое чтение первого
 * `withdrawAll()` и второму отдаёт ровно те же строки — как второй запрос, прочитавший склад
 * до коммита первого. Выдача решается только условной записью `withdrawRow`
 * (`quantity >= ?` + `affectedRows`), поэтому второй забор обязан получить `empty`/`missing`,
 * а рюкзак — ровно N.
 *
 * Схема — как у `BaseStorageServiceTest` (миграции + DDL-копия ресурсов).
 *
 * @internal
 */
final class BaseStorageWithdrawAllOnceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MSG = 'bulk sale committed although increaseGold refused, or withdraw-all issued the storage twice';

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
            $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
            $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 555004, 'Тест')");
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
                . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
            );
            $this->conn->query("INSERT INTO resources (id, name, icon_text) VALUES (1, 'Вода', '💧')");
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

    public function testTwoWithdrawAllFromOneSnapshotIssueStorageOnce(): void
    {
        $n = 30;
        $this->conn->table('base_storage')->insert([
            'character_id' => self::CHAR, 'resource_id' => 1, 'quantity' => $n,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $storage = new class () extends BaseStorageModel {
            /** @var list<array<int|string,mixed>> */
            public array $tape = [];
            public bool $replay = false;
            private int $pos = 0;

            public function findAll(?int $limit = null, int $offset = 0)
            {
                $live = parent::findAll($limit, $offset); // всегда — чтобы сбросить состояние билдера
                if (! $this->replay) {
                    $this->tape[] = $live;

                    return $live;
                }

                return $this->tape[$this->pos++] ?? $live;
            }
        };
        $svc = new BaseStorageService($storage, null, $this->gateOnBase());

        $first = $svc->withdrawAll(self::CHAR);
        $storage->replay = true; // второй запрос видит склад таким, каким его видел первый
        $second = $svc->withdrawAll(self::CHAR);

        $this->assertSame(BaseStorageService::OK, $first['code'], self::MSG);
        $this->assertSame($n, $first['units'], self::MSG);
        $this->assertContains($second['code'], [BaseStorageService::EMPTY, BaseStorageService::MISSING], self::MSG);
        $this->assertSame($n, $this->backpack(1), self::MSG);
        $this->assertSame(0, $this->stored(1), self::MSG);
        $this->assertNotEmpty($storage->tape, 'снимок не записан — тест не прошёл гейт-чтение');
    }

    private function gateOnBase(): BaseCheckService
    {
        return new class () extends BaseCheckService {
            public function __construct()
            {
            }

            public function checkBaseStatus(int $characterId): array
            {
                return ['hasBase' => true, 'isOnBase' => true, 'x' => null, 'y' => null];
            }
        };
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
