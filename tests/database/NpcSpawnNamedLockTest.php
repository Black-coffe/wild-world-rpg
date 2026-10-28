<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateNpcDialoguesTable;
use App\Services\NPC\NpcInteractionService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s2-conditional-writes-02 (F8, ADR-181 п. 4) — «Драться» и «Ограбить» сериализуются
 * именованным локом `npc_spawn_<spawnId>`, а перевод спавна в «мёртв» — условная запись.
 *
 * Форма теста спеки — не гонка, а занятый лок: второе соединение (`connect('tests', false)`)
 * держит `GET_LOCK` с тем же именем, продовый `rob()`/`fight()` обязан выйти «NPC уже исчез.»
 * без золота, опыта и без изменения строки спавна. Второй тест — повтор после снятия лока:
 * второй `rob()` по уже ограбленному спавну золота не даёт.
 *
 * Шанс грабежа фиксируется на 1.0 через кэш `GameSettingsService` (тот же путь чтения, что у
 * прода), чтобы незащищённый `rob()` гарантированно платил. Общий стенд `wildworld_tests`:
 * создаём только отсутствующие таблицы и дропаем только свои, свои строки удаляем по id.
 *
 * @internal
 */
final class NpcSpawnNamedLockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const REASON = 'reward granted while spawn lock was busy or after the spawn was consumed';

    private const ROB_CHANCE_CACHE_KEY = 'game_settings_npc_rob_success_chance';

    private BaseConnection $conn;

    private ?BaseConnection $holder = null;

    private ?string $heldLock = null;

    /** @var list<string> */
    private array $createdTables = [];

    /** @var array<string,list<int>> */
    private array $ownedRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        $this->createTableIfMissing('characters', '
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                name VARCHAR(100) NULL,
                level INT DEFAULT 1,
                health DECIMAL(7,2) DEFAULT 100,
                tired DECIMAL(7,2) DEFAULT 100,
                strength DECIMAL(7,2) DEFAULT 1,
                agility DECIMAL(7,2) DEFAULT 1,
                intellect DECIMAL(7,2) DEFAULT 1,
                gold INT DEFAULT 0,
                experience DECIMAL(15,2) DEFAULT 0,
                npc_kills INT DEFAULT 0,
                cell_number INT DEFAULT 0,
                biome_id INT NULL,
                well_fed_until DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) AUTO_INCREMENT=' . random_int(2_000_000, 2_999_999));
        $this->createTableIfMissing('npc_spawns', '
            CREATE TABLE npc_spawns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                npc_id INT NULL,
                cell_number INT NULL,
                coordinate_x INT NULL,
                coordinate_y INT NULL,
                current_health DECIMAL(7,2) NULL,
                spawned_at DATETIME NULL,
                status VARCHAR(20) NULL
            )
        ');
        if (! $this->conn->tableExists('npc_dialogues')) {
            if (! class_exists(CreateNpcDialoguesTable::class, false)) {
                require_once APPPATH . 'Database/Migrations/2026-06-15-110000_CreateNpcDialoguesTable.php';
            }
            $forge = Database::forge('tests');
            (new CreateNpcDialoguesTable($forge instanceof Forge ? $forge : null))->up();
            $this->createdTables[] = 'npc_dialogues';
        }

        cache()->save(self::ROB_CHANCE_CACHE_KEY, ['v' => 1.0, 't' => 'float'], 300);
    }

    protected function tearDown(): void
    {
        if ($this->holder !== null) {
            if ($this->heldLock !== null) {
                $this->holder->query('SELECT RELEASE_LOCK(?)', [$this->heldLock]);
            }
            $this->holder->close();
        }
        $this->holder   = null;
        $this->heldLock = null;

        cache()->delete(self::ROB_CHANCE_CACHE_KEY);

        foreach ($this->ownedRows as $table => $ids) {
            if ($ids === [] || in_array($table, $this->createdTables, true)) {
                continue;
            }
            $this->conn->table($table)->whereIn('id', $ids)->delete();
        }
        $this->ownedRows = [];

        foreach (array_reverse($this->createdTables) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->createdTables = [];

        parent::tearDown();
    }

    public function testRobAndFightRefuseWhileSpawnLockIsHeldElsewhere(): void
    {
        [$charId, $spawnId] = $this->seedEncounter();

        $this->holder   = Database::connect('tests', false);
        $this->heldLock = 'npc_spawn_' . $spawnId;
        $got            = $this->holder->query('SELECT GET_LOCK(?, 0) AS locked', [$this->heldLock])->getRow();
        $this->assertSame(1, (int) ($got->locked ?? 0), 'предусловие: второе соединение держит лок спавна');

        $before = $this->snapshot($charId, $spawnId);

        $svc = new NpcInteractionService();
        $rob = $svc->rob($charId, $spawnId);
        $this->assertSame($before, $this->snapshot($charId, $spawnId), self::REASON);
        $this->assertSame('gone', $rob['outcome'], self::REASON);
        $this->assertSame('NPC уже исчез.', $rob['line']);

        $fight = $svc->fight($charId, $spawnId);
        $this->assertFalse($fight['ok'], self::REASON);
        $this->assertSame($before, $this->snapshot($charId, $spawnId), self::REASON);
    }

    public function testSecondRobOnConsumedSpawnGrantsNoGold(): void
    {
        [$charId, $spawnId] = $this->seedEncounter();

        $svc   = new NpcInteractionService();
        $first = $svc->rob($charId, $spawnId);
        $this->assertSame('success', $first['outcome'], 'предусловие: первый грабёж успешен (шанс 1.0)');
        $goldAfterFirst = $this->snapshot($charId, $spawnId)['gold'];
        $this->assertGreaterThan(0, (int) $goldAfterFirst, 'предусловие: первый грабёж дал золото');

        $second = $svc->rob($charId, $spawnId);
        $this->assertSame('gone', $second['outcome'], self::REASON);
        $this->assertSame($goldAfterFirst, $this->snapshot($charId, $spawnId)['gold'], self::REASON);
    }

    /** @return array{0:int,1:int} [charId, spawnId] */
    private function seedEncounter(): array
    {
        $cell    = random_int(400_000, 499_999);
        $charId  = $this->insertOwn('characters', [
            'name' => 'LockHero', 'level' => 10, 'health' => 100, 'tired' => 100,
            'strength' => 5, 'agility' => 5, 'intellect' => 5,
            'gold' => 0, 'experience' => 0, 'cell_number' => $cell,
        ]);
        $spawnId = $this->insertOwn('npc_spawns', [
            'npc_id' => random_int(800_000_000, 899_999_999), 'cell_number' => $cell,
            'current_health' => 1, 'status' => 'alive',
        ]);

        return [$charId, $spawnId];
    }

    /** @return array<string,string> */
    private function snapshot(int $charId, int $spawnId): array
    {
        $char  = $this->conn->table('characters')->where('id', $charId)->get()->getRowArray() ?? [];
        $spawn = $this->conn->table('npc_spawns')->where('id', $spawnId)->get()->getRowArray();

        return [
            'gold'         => (string) ($char['gold'] ?? ''),
            'experience'   => (string) ($char['experience'] ?? ''),
            'npc_kills'    => (string) ($char['npc_kills'] ?? ''),
            'spawn_status' => is_array($spawn) ? (string) ($spawn['status'] ?? '') : 'absent',
        ];
    }

    /** @param array<string,mixed> $row */
    private function insertOwn(string $table, array $row): int
    {
        $this->conn->table($table)->insert($row);
        $id = (int) $this->conn->insertID();
        $this->ownedRows[$table][] = $id;

        return $id;
    }

    private function createTableIfMissing(string $table, string $ddl): void
    {
        if ($this->conn->tableExists($table)) {
            return;
        }
        $this->conn->query($ddl);
        $this->createdTables[] = $table;
    }
}
