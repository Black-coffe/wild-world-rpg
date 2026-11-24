<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Games\FortuneWheelAction;
use App\Controllers\Telegram\Commands\Actions\StartGame\GatherTips;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Services\PVE\TributeService;
use App\TaskHandlers\Built\HandPumpProductionHandler;
use App\TaskHandlers\GreenhouseProductionHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * s1-atomicity-1124, review round 1 (ask 13) — шесть путей начисления вне `CharacterResourceModel`
 * (`ResourceModel::addOrIncreaseResource`, колесо фортуны, первая добыча `GatherTips`, подать,
 * ручная скважина, теплица) зачисляют тем же атомарным upsert. Под `UNIQUE (id_characters,
 * id_resources)` параллельное начисление той же пары не роняет их дублем ключа и не теряется:
 * остаток — сумма обоих, строка одна. И когда строки пары не было, и когда она была.
 *
 * «Параллельно» — как в `GatherCreditUpsertTest`: слушатель `DBQuery` ловит первый запрос пути к
 * `character_resources`, и в этот момент второе соединение начисляет ту же пару (upsert +5).
 *
 * @internal
 */
final class ResourceCreditSitesUpsertTest extends CIUnitTestCase
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
            $this->conn->query('CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL, rarity INT NULL)');
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
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000044, 'Тест')");
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
                . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
            );
            $this->conn->query("INSERT INTO resources (id, name, name_en, rarity) VALUES (3, 'Вода', 'Water', 1)");
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

    /** @return iterable<string, array{string, int, string}> */
    public static function sites(): iterable
    {
        foreach (['ResourceModel', 'HandPump', 'Greenhouse', 'FortuneWheel', 'GatherTips', 'Tribute'] as $site) {
            yield $site . ', пары не было' => [$site, 0, '9'];
            yield $site . ', пара была'    => [$site, 10, '19'];
        }
    }

    #[DataProvider('sites')]
    public function testConcurrentCreditOfTheSamePairLandsAsSumInOneRow(string $site, int $before, string $expected): void
    {
        if ($before > 0) {
            $this->conn->query("INSERT INTO character_resources (id_characters, id_resources, quantity, created_at, updated_at) VALUES (1, 3, {$before}, NOW(), NOW())");
        }

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
            $this->credit($site, 4);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertTrue($fired, $site . ' не обратился к character_resources — вклинивание не случилось');
        $this->assertNull($error, $site . ' упал исключением: ' . (string) $error);
        $this->assertSame([['quantity' => $expected]], $this->rows(), $site . ': начисление и параллельное начисление легли суммой в одну строку');
    }

    public function testResourceModelStillRefusesAnUnknownName(): void
    {
        $this->assertFalse((new ResourceModel())->addOrIncreaseResource(1, 'NoSuchThing', 4));
        $this->assertSame([], $this->rows());
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function credit(string $site, int $amount): void
    {
        $crm = new CharacterResourceModel();
        switch ($site) {
            case 'ResourceModel':
                $this->assertTrue((new ResourceModel())->addOrIncreaseResource(1, 'Water', $amount));

                return;
            case 'HandPump':
                $this->invoke($this->bare(HandPumpProductionHandler::class, ['characterResourceModel' => $crm]), 'addWaterToCharacter', [1, $amount, 3]);

                return;
            case 'Greenhouse':
                $this->invoke($this->bare(GreenhouseProductionHandler::class, ['characterResourceModel' => $crm]), 'addResourceToCharacter', [1, 'Water', $amount, ['Water' => ['id' => 3]]]);

                return;
            case 'FortuneWheel':
                $this->invoke($this->bare(FortuneWheelAction::class, ['characterResourceModel' => $crm]), 'addOrUpdateCharacterResource', [['id' => 1, 'telegram_user_id' => 7], 3, $amount]);

                return;
            case 'GatherTips':
                $this->invoke($this->bare(GatherTips::class, ['characterResourceModel' => $crm]), 'saveFoundResources', [[['resource_id' => 3, 'amount' => $amount]], ['id' => 1, 'telegram_user_id' => 7]]);

                return;
            case 'Tribute':
                $this->invoke($this->bare(TributeService::class, ['characters' => new CharacterModel(), 'resources' => $crm]), 'creditResources', [1, [3 => $amount]]);

                return;
        }
        $this->fail('неизвестный путь ' . $site);
    }

    /**
     * Экземпляр без конструктора (крон-хендлеры и экшены тянут Telegram/конфиг) с нужными моделями.
     *
     * @param class-string         $class
     * @param array<string, mixed> $props
     */
    private function bare(string $class, array $props): object
    {
        $ref = new \ReflectionClass($class);
        $obj = $ref->newInstanceWithoutConstructor();
        foreach ($props as $name => $value) {
            $p = $ref->getProperty($name);
            $p->setValue($obj, $value);
        }

        return $obj;
    }

    /** @param list<mixed> $args */
    private function invoke(object $obj, string $method, array $args): void
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->invokeArgs($obj, $args);
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
