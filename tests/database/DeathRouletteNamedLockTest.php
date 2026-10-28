<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\DeathService;
use App\TaskHandlers\DeathRouletteHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s2-conditional-writes-08 (F23, ADR-181 п. 4) — весь прогон рулетки смерти идёт под
 * именованным локом `death_roulette`, без миграции.
 *
 * Форма теста спеки — не гонка, а занятый лок: второе соединение (`connect('tests', false)`)
 * держит `GET_LOCK('death_roulette', 0)`, продовый `handle()` обязан выйти, не тронув ни одного
 * кандидата. Кандидат с health = 0 умирает при любом броске (roll 1..100 > 0), поэтому
 * незащищённый прогон гарантированно зовёт смерть: счётчик двойника `DeathService` растёт или
 * строка кандидата меняется. Исключение из прогона под занятым локом тоже значит «кандидат
 * обработан» — под локом прогон не должен начаться вовсе.
 *
 * Общий стенд `wildworld_tests`: создаём только отсутствующие таблицы/колонку и убираем только
 * своё; свои строки удаляем по id. Telegram в конструкторе не поднимается.
 *
 * @internal
 */
final class DeathRouletteNamedLockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const REASON = 'death roulette processed a candidate while the run lock was busy';

    private const LOCK_NAME = 'death_roulette';

    private BaseConnection $conn;

    private ?BaseConnection $holder = null;

    private bool $createdTable = false;

    /** @var list<string> */
    private array $addedColumns = [];

    /** Колонки, которые читает прогон и снимок; на чужой урезанной таблице стенда добавляются и убираются. */
    private const NEEDED_COLUMNS = [
        'name'            => 'VARCHAR(100) NULL',
        'health'          => 'DECIMAL(7,2) DEFAULT 100',
        'tired'           => 'DECIMAL(7,2) DEFAULT 100',
        'experience'      => 'DECIMAL(15,2) DEFAULT 0',
        'strength'        => 'DECIMAL(7,2) DEFAULT 1',
        'cell_number'     => 'INT NULL',
        'last_respawn_at' => 'DATETIME NULL',
    ];

    /** @var list<int> */
    private array $ownedIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        if (! $this->conn->tableExists('characters')) {
            $this->conn->query('
                CREATE TABLE characters (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    telegram_user_id INT NULL,
                    name VARCHAR(100) NULL,
                    level INT DEFAULT 1,
                    health DECIMAL(7,2) DEFAULT 100,
                    max_health DECIMAL(7,2) DEFAULT 100,
                    tired DECIMAL(7,2) DEFAULT 100,
                    max_tired DECIMAL(7,2) DEFAULT 100,
                    strength DECIMAL(7,2) DEFAULT 1,
                    agility DECIMAL(7,2) DEFAULT 1,
                    intellect DECIMAL(7,2) DEFAULT 1,
                    gold INT DEFAULT 0,
                    experience DECIMAL(15,2) DEFAULT 0,
                    cell_number INT DEFAULT 0,
                    last_respawn_at DATETIME NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                ) AUTO_INCREMENT=' . random_int(3_000_000, 3_999_999));
            $this->createdTable = true;
        } else {
            foreach (self::NEEDED_COLUMNS as $col => $ddl) {
                if (! $this->conn->fieldExists($col, 'characters')) {
                    $this->conn->query("ALTER TABLE characters ADD COLUMN {$col} {$ddl}");
                    $this->addedColumns[] = $col;
                }
            }
            $this->conn->resetDataCache();
        }
    }

    protected function tearDown(): void
    {
        if ($this->holder !== null) {
            $this->holder->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
            $this->holder->close();
        }
        $this->holder = null;

        if ($this->createdTable) {
            $this->conn->query('DROP TABLE IF EXISTS characters');
        } else {
            if ($this->ownedIds !== []) {
                $this->conn->table('characters')->whereIn('id', $this->ownedIds)->delete();
            }
            foreach ($this->addedColumns as $col) {
                $this->conn->query("ALTER TABLE characters DROP COLUMN {$col}");
            }
        }
        $this->ownedIds     = [];
        $this->createdTable = false;
        $this->addedColumns = [];

        parent::tearDown();
    }

    public function testRunSkipsEveryCandidateWhileRunLockIsHeldElsewhere(): void
    {
        $charId = $this->insertOwn([
            'name' => 'RouletteLock', 'health' => 0, 'tired' => 50,
            'strength' => 5, 'experience' => 100, 'cell_number' => random_int(500_000, 599_999),
            'last_respawn_at' => null,
        ]);

        $this->holder = Database::connect('tests', false);
        $got          = $this->holder->query('SELECT GET_LOCK(?, 0) AS locked', [self::LOCK_NAME])->getRow();
        $this->assertSame(1, (int) ($got->locked ?? 0), 'предусловие: второе соединение держит лок рулетки');

        $before = $this->snapshot($charId);
        $death  = new CountingDeathService();

        $threw = null;
        try {
            (new DeathRouletteHandler(null, $death))->handle();
        } catch (\Throwable $e) {
            $threw = $e::class . ': ' . $e->getMessage();
        }

        $this->assertNull($threw, self::REASON);
        $this->assertSame(0, $death->calls, self::REASON);
        $this->assertSame($before, $this->snapshot($charId), self::REASON);
    }

    /** @return array<string,string> */
    private function snapshot(int $charId): array
    {
        $row = $this->conn->table('characters')->where('id', $charId)->get()->getRowArray() ?? [];

        return [
            'health'      => (string) ($row['health'] ?? ''),
            'tired'       => (string) ($row['tired'] ?? ''),
            'experience'  => (string) ($row['experience'] ?? ''),
            'strength'    => (string) ($row['strength'] ?? ''),
            'cell_number' => (string) ($row['cell_number'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private function insertOwn(array $row): int
    {
        $this->conn->table('characters')->insert($row);
        $id               = (int) $this->conn->insertID();
        $this->ownedIds[] = $id;

        return $id;
    }
}

/**
 * Двойник смерти: считает вызовы и не трогает БД. Родительский конструктор не зовётся —
 * модели и калькуляторы двойнику не нужны.
 */
final class CountingDeathService extends DeathService
{
    public int $calls = 0;

    public function __construct()
    {
    }

    public function handlePlayerDeathAndReward(int $loserId, ?int $winnerId = null, bool $deferVehicleNotice = false): array
    {
        $this->calls++;

        return [
            'hasBase'               => false,
            'penalty'               => 0.0,
            'newbieProtected'       => false,
            'transferredResources'  => [],
            'transferredCraftItems' => [],
            'transferredGold'       => 0,
            'vehicleBroken'         => null,
            'success'               => false,
        ];
    }
}
