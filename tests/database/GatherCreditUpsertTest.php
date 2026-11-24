<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\Gather\GatherResultPersister;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-1124 S4 (ask 13) — добыча (`GatherResultPersister`) зачисляет в рюкзак тем же
 * атомарным upsert, что и `CharacterResourceModel`: под `UNIQUE (id_characters, id_resources)`
 * параллельное начисление той же пары не роняет добычу дублем и не теряется — остаток равен сумме.
 *
 * «Параллельно» — детерминированно: слушатель `DBQuery` ловит первый запрос добычи к
 * `character_resources`, и в этот момент второе соединение начисляет ту же пару (upsert +5). Схема —
 * прогоном миграций (кроме `resources`), индекс S1 — `up()` миграции `S1CharacterResourcesUnique`.
 *
 * @internal
 */
final class GatherCreditUpsertTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-22-110635_CreateCharacterResourcesTable',
        '2026-12-25-100020_S1CharacterResourcesUnique',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = ['biomes', 'map', 'telegram_users', 'characters', 'resources', 'character_resources', 'game_settings'];

    private BaseConnection $conn;

    /** @var (callable(mixed): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // `CreateResourcesTable` не собирается на MySQL 8 (UNSIGNED у не-числовой колонки) — `resources`
            // руками, как в `CraftQueueCoreTest`; `character_resources` и индекс S1 — миграциями.
            $this->conn->query('CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL)');
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
            $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000043, 'Тест')");
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
                . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
            );
            $this->conn->query("INSERT INTO resources (id, name) VALUES (3, 'Дерево')");
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            Events::removeListener('DBQuery', $this->listener);
            $this->listener = null;
        }
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testConcurrentCreditOfTheSamePairLandsAsSumWithoutDuplicateKey(): void
    {
        $fired          = false;
        $this->listener = function ($query) use (&$fired): void {
            if (! $fired && $query instanceof Query && str_contains($query->getQuery(), 'character_resources')) {
                $fired = true;
                $now   = date('Y-m-d H:i:s');
                Database::connect(null, false)->query(
                    'INSERT INTO character_resources (id_characters, id_resources, quantity, created_at, updated_at) VALUES (1, 3, 5, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE quantity = quantity + 5',
                    [$now, $now]
                );
            }
        };
        Events::on('DBQuery', $this->listener);

        $error = null;
        try {
            $this->persister()->persist([['resource_id' => 3, 'amount' => 4, 'type' => 'raw']], $this->character(), []);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertTrue($fired, 'добыча не обратилась к character_resources — вклинивание не случилось');
        $this->assertNull($error, 'добыча упала исключением: ' . (string) $error);
        $this->assertSame([['quantity' => '9']], $this->rows(), 'добыча и параллельное начисление легли суммой в одну строку');
    }

    public function testTheSameResourceTwiceInOneFindIsCreditedInFull(): void
    {
        $this->conn->query("INSERT INTO character_resources (id_characters, id_resources, quantity, created_at, updated_at) VALUES (1, 3, 10, NOW(), NOW())");

        $this->persister()->persist(
            [['resource_id' => 3, 'amount' => 4, 'type' => 'raw'], ['resource_id' => 3, 'amount' => 2, 'type' => 'raw']],
            $this->character(),
            []
        );

        $this->assertSame([['quantity' => '16']], $this->rows());
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function persister(): GatherResultPersister
    {
        return new class () extends GatherResultPersister {
            protected function resolveVehicleProfile(int $charId): array
            {
                return ['key' => null, 'cells_per_tick' => 1, 'tired_factor' => 1.0, 'max_steps_per_order' => 1, 'cargo_share' => 0.0, 'wear_per_cell' => 0];
            }

            protected function recordGathered(array|\App\Entities\CharacterEntity $character, array $foundResources): void
            {
            }
        };
    }

    /** @return array<string, int|float> */
    private function character(): array
    {
        return ['id' => 1, 'telegram_user_id' => 7, 'level' => 10, 'experience' => 1.5];
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return Database::connect(null, false)
            ->query('SELECT quantity FROM character_resources WHERE id_characters = 1 AND id_resources = 3')
            ->getResultArray();
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
