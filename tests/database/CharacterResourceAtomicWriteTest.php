<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\CharacterResourceModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * s1-atomicity-02 (C8) — запись `character_resources` атомарна: начисление одним upsert по
 * `UNIQUE (id_characters, id_resources)`, списание условной записью `quantity >= n` в самом UPDATE.
 *
 * «Два одновременных вызова» — детерминированно: слушатель `DBQuery` ловит ПЕРВЫЙ запрос вызова
 * к `character_resources` и в этот момент второе соединение делает тот же вызов той же модели
 * целиком. Read-then-write (чтение → решение в PHP → запись) в этом окне видит устаревший снимок:
 * начисление теряет одно слагаемое или пишет вторую строку пары, списание проходит дважды.
 *
 * Схема: `character_resources` — форма боевой таблицы до S1 (с `idx_cr_char_resource` из
 * `AddCriticalIndexes`), индекс S1 — запуском `up()` миграции `S1CharacterResourcesUnique`, а не
 * рукописным DDL. Стенд общий: таблицы создаются, только если их нет, и дропаются, только если их
 * создал этот тест; миграция, прогнанная на чужой таблице, откатывается `down()`; свои строки — по
 * случайным id персонажа и ресурса.
 *
 * @internal
 */
final class CharacterResourceAtomicWriteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const MSG = 'S1 atomic write violated: concurrent writers broke the one-row / no-overspend invariant';

    private const MIGRATION_FILE  = 'Database/Migrations/2026-12-25-100020_S1CharacterResourcesUnique.php';
    private const MIGRATION_CLASS = 'App\\Database\\Migrations\\S1CharacterResourcesUnique';

    protected $migrate = false;

    /** @var list<string> */
    private array $createdTables = [];

    private bool $migratedForeignTable = false;

    /** @var list<array{0:int,1:int}> пары (персонаж, ресурс) своих строк */
    private array $ownPairs = [];

    /** @var list<int> */
    private array $ownResourceIds = [];

    /** @var (callable(mixed): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->db();
        $db->resetDataCache();

        if (! $db->tableExists('resources')) {
            $db->query('
                CREATE TABLE resources (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NULL,
                    name_en VARCHAR(255) NULL,
                    rarity VARCHAR(50) NULL
                )');
            $this->createdTables[] = 'resources';
        }

        $createdHere = false;
        if (! $db->tableExists('character_resources')) {
            $db->query('
                CREATE TABLE character_resources (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    id_characters INT UNSIGNED NOT NULL,
                    id_resources INT UNSIGNED NOT NULL,
                    id_telegram_users INT NULL,
                    quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    custom_data TEXT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    KEY idx_cr_char_resource (id_characters, id_resources),
                    KEY idx_cr_character (id_characters)
                )');
            $this->createdTables[] = 'character_resources';
            $createdHere           = true;
        }

        if (! $this->hasIndex('character_resources', 'uq_cr_char_resource') && is_file(APPPATH . self::MIGRATION_FILE)) {
            require_once APPPATH . self::MIGRATION_FILE;
            $class = self::MIGRATION_CLASS;
            (new $class())->up();
            $this->migratedForeignTable = ! $createdHere;
        }
        $db->resetDataCache();
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            Events::removeListener('DBQuery', $this->listener);
            $this->listener = null;
        }

        $db = $this->db();
        foreach ($this->ownPairs as [$characterId, $resourceId]) {
            $db->table('character_resources')->where('id_characters', $characterId)->where('id_resources', $resourceId)->delete();
        }
        foreach ($this->ownResourceIds as $id) {
            $db->table('resources')->where('id', $id)->delete();
        }
        $this->ownPairs       = [];
        $this->ownResourceIds = [];

        if ($this->migratedForeignTable) {
            $class = self::MIGRATION_CLASS;
            (new $class())->down();
            $this->migratedForeignTable = false;
        }
        foreach (array_reverse($this->createdTables) as $table) {
            $db->query("DROP TABLE IF EXISTS {$table}");
        }
        $this->createdTables = [];
        $db->resetDataCache();

        parent::tearDown();
    }

    /** @return array<string, array{0:string,1:int}> метод начисления × стартовый остаток (0 = пары нет) */
    public static function grants(): array
    {
        return [
            'increaseResources, пары нет'     => ['increaseResources', 0],
            'increaseResources, пара есть'    => ['increaseResources', 10],
            'addOrIncreaseResource, пары нет'  => ['addOrIncreaseResource', 0],
            'addOrIncreaseResource, пара есть' => ['addOrIncreaseResource', 10],
        ];
    }

    #[DataProvider('grants')]
    public function testTwoConcurrentGrantsOfOnePairLandInOneRowWithBothAmounts(string $method, int $start): void
    {
        [$characterId, $resourceId] = $this->pair();
        if ($start > 0) {
            $this->insertRow($characterId, $resourceId, $start);
        }

        $other = Database::connect('tests', false);
        $this->onFirstResourceQuery(static function () use ($other, $method, $characterId, $resourceId): void {
            (new CharacterResourceModel($other))->{$method}($characterId, $resourceId, 3);
        });

        $error = null;
        try {
            (new CharacterResourceModel())->{$method}($characterId, $resourceId, 5);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $rows = $this->rows($characterId, $resourceId);
        $this->assertNull($error, self::MSG . ' — начисление упало: ' . (string) $error);
        $this->assertCount(1, $rows, self::MSG . ' — пара записана не одной строкой');
        $this->assertSame($start + 3 + 5, (int) $rows[0]['quantity'], self::MSG . ' — потеряно одно из начислений');
    }

    /** @return array<string, array{0:string}> */
    public static function grantMethods(): array
    {
        return ['increaseResources' => ['increaseResources'], 'addOrIncreaseResource' => ['addOrIncreaseResource']];
    }

    #[DataProvider('grantMethods')]
    public function testGrantIsOneSqlStatement(string $method): void
    {
        [$characterId, $resourceId] = $this->pair();
        $this->insertRow($characterId, $resourceId, 2);

        $statements = 0;
        $this->listener = static function ($query) use (&$statements): void {
            if ($query instanceof Query && str_contains($query->getQuery(), 'character_resources')) {
                $statements++;
            }
        };
        Events::on('DBQuery', $this->listener);

        (new CharacterResourceModel())->{$method}($characterId, $resourceId, 4);

        Events::removeListener('DBQuery', $this->listener);
        $this->listener = null;

        $this->assertSame(1, $statements, self::MSG . ' — начисление не одной инструкцией');
        $this->assertSame(6, (int) $this->rows($characterId, $resourceId)[0]['quantity']);
    }

    /** @return array<string, array{0:string,1:bool}> метод списания × удаляет ли строку при нуле */
    public static function deductions(): array
    {
        return [
            'deductResource'         => ['deductResource', false],
            'deductResourceForCraft' => ['deductResourceForCraft', true],
            'deductResourceByName'   => ['deductResourceByName', true],
            'decreaseQtyById'        => ['decreaseQtyById', true],
        ];
    }

    #[DataProvider('deductions')]
    public function testTwoConcurrentDeductionsOfTheWholeStackSucceedOnce(string $method, bool $deletesAtZero): void
    {
        [$characterId, $resourceId] = $this->pair();
        $name = 'S1Res' . $resourceId;
        $this->db()->table('resources')->insert(['id' => $resourceId, 'name' => $name, 'name_en' => $name, 'rarity' => 'common']);
        $this->ownResourceIds[] = $resourceId;
        $rowId = $this->insertRow($characterId, $resourceId, 4);

        $call = static function (CharacterResourceModel $model) use ($method, $name, $characterId, $rowId): bool {
            return (bool) ($method === 'decreaseQtyById'
                ? $model->decreaseQtyById($rowId, 4)
                : $model->{$method}($name, $characterId, 4));
        };

        $other      = Database::connect('tests', false);
        $competitor = null;
        $this->onFirstResourceQuery(static function () use ($other, $call, &$competitor): void {
            $competitor = $call(new CharacterResourceModel($other));
        });

        $mine = $call(new CharacterResourceModel());

        $successes = (int) $mine + (int) $competitor;
        $this->assertSame(1, $successes, self::MSG . ' — весь остаток списан дважды');

        $rows = $this->rows($characterId, $resourceId);
        if ($deletesAtZero) {
            $this->assertSame([], $rows, 'опустошённая строка удаляется, как и до S1');
        } else {
            $this->assertCount(1, $rows, 'deductResource оставляет нулевую строку, как и до S1');
            $this->assertSame(0, (int) $rows[0]['quantity']);
        }
    }

    public function testDeductionRefusesWhenTheStackIsShortAndLeavesItUntouched(): void
    {
        [$characterId, $resourceId] = $this->pair();
        $name = 'S1Res' . $resourceId;
        $this->db()->table('resources')->insert(['id' => $resourceId, 'name' => $name, 'name_en' => $name, 'rarity' => 'common']);
        $this->ownResourceIds[] = $resourceId;
        $rowId = $this->insertRow($characterId, $resourceId, 3);
        $model = new CharacterResourceModel();

        $this->assertFalse($model->deductResource($name, $characterId, 4));
        $this->assertFalse($model->deductResourceForCraft($name, $characterId, 4));
        $this->assertFalse($model->deductResourceByName($name, $characterId, 4));
        $this->assertFalse($model->decreaseQtyById($rowId, 4));
        $this->assertSame(3, (int) $this->rows($characterId, $resourceId)[0]['quantity']);

        $this->assertTrue($model->deductResource($name, $characterId, 1));
        $this->assertTrue($model->decreaseQtyById($rowId, 1));
        $this->assertSame(1, (int) $this->rows($characterId, $resourceId)[0]['quantity']);
    }

    public function testMigrationLeavesTheUniquePairIndexInPlace(): void
    {
        $this->assertTrue($this->hasIndex('character_resources', 'uq_cr_char_resource'), 'индекс S1 создан миграцией');
        $this->assertFalse($this->hasIndex('character_resources', 'idx_cr_char_resource'), 'неуникальный индекс пары заменён');
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function onFirstResourceQuery(callable $action): void
    {
        $fired          = false;
        $this->listener = static function ($query) use ($action, &$fired): void {
            if (! $fired && $query instanceof Query && str_contains($query->getQuery(), 'character_resources')) {
                $fired = true;
                $action();
            }
        };
        Events::on('DBQuery', $this->listener);
    }

    /** @return array{0:int,1:int} */
    private function pair(): array
    {
        $pair             = [random_int(600_000_000, 999_999_999), random_int(600_000_000, 999_999_999)];
        $this->ownPairs[] = $pair;

        return $pair;
    }

    private function insertRow(int $characterId, int $resourceId, int $qty): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db()->table('character_resources')->insert([
            'id_characters' => $characterId,
            'id_resources'  => $resourceId,
            'quantity'      => $qty,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return (int) $this->db()->insertID();
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $characterId, int $resourceId): array
    {
        $res = Database::connect('tests', false)->query(
            'SELECT id, quantity FROM character_resources WHERE id_characters = ? AND id_resources = ? ORDER BY id',
            [$characterId, $resourceId]
        );

        return array_values($res->getResultArray());
    }

    private function hasIndex(string $table, string $index): bool
    {
        $res = $this->db()->query("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]);

        return $res !== false && $res->getResultArray() !== [];
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }
}
