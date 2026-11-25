<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\FoodAndWaterConsumptionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * ADR-200 — суточный голод ранит, но не добивает: здоровье делится, как было, но не опускается ниже
 * пассивного пола `health.passive_floor` (дефолт 1.0, выше порога рулетки смерти 0.99). Ниже пола голод
 * здоровье не меняет и не поднимает. Сообщение о голоде печатает фактическую просадку.
 *
 * До правки пол был 0.01: 1.5 HP → 0.75 → рулетка в ту же минуту.
 *
 * Схема — по факту отсутствия таблицы; свои таблицы дропаются в tearDown, свои строки удаляются.
 *
 * @internal
 */
final class HungerPassiveFloorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<string> */
    private array $createdTables = [];

    /** @var array<string, list<int>> */
    private array $ownRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        HungerSendSpy::$captions = [];

        $this->createIfMissing('telegram_users', '
            CREATE TABLE telegram_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL,
                username VARCHAR(255) NULL,
                first_name VARCHAR(255) NULL,
                last_name VARCHAR(255) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createIfMissing('characters', '
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                name VARCHAR(64) NULL,
                gold INT NULL DEFAULT 0,
                health DECIMAL(10,2) NULL DEFAULT 100,
                tired DECIMAL(10,2) NULL DEFAULT 100,
                level INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        try {
            $db = $this->db();
            foreach ($this->ownRows as $table => $ids) {
                if ($ids !== []) {
                    $db->table($table)->whereIn('id', $ids)->delete();
                }
            }
            foreach (array_reverse($this->createdTables) as $table) {
                $db->query("DROP TABLE IF EXISTS {$table}");
            }
            $db->resetDataCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testHungerStopsAtPassiveFloor(): void
    {
        $charId = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 1.5, 'level' => 10]);

        $this->hunger($charId, 0.75);

        $this->assertEqualsWithDelta(1.0, $this->health($charId), 0.0001, 'hunger went below the passive floor');
    }

    public function testHungerBelowFloorNeitherLowersNorHeals(): void
    {
        $charId = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 0.6, 'level' => 10]);

        $this->hunger($charId, 0.3);

        $this->assertEqualsWithDelta(0.6, $this->health($charId), 0.0001, 'below the floor hunger leaves HP as is');
    }

    public function testHungerAboveFloorHalvesAsBefore(): void
    {
        $charId = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 80, 'level' => 10]);

        $this->hunger($charId, 40.0);

        $this->assertEqualsWithDelta(40.0, $this->health($charId), 0.0001, 'above the floor hunger halves HP as before');
    }

    public function testHungerMessagePrintsTheRealDrop(): void
    {
        $tgId   = $this->insertTracked('telegram_users', ['telegram_id' => 777000123]);
        $charId = $this->insertTracked('characters', ['telegram_user_id' => $tgId, 'health' => 1.5, 'level' => 10]);

        $this->hunger($charId, 0.75);

        $this->assertCount(1, HungerSendSpy::$captions, 'one hunger message');
        $caption = HungerSendSpy::$captions[0];
        $this->assertStringContainsString('просело на:* 0.5', $caption, 'message shows the applied drop, not the requested one');
        $this->assertStringContainsString('Текущее здоровье:* 1', $caption);
    }

    private function hunger(int $charId, float $requested): void
    {
        $handler = new HungerSendSpy();
        $m       = new ReflectionMethod(FoodAndWaterConsumptionHandler::class, 'subtractHealth');
        $m->invoke($handler, ['id' => $charId, 'health' => $this->health($charId)], $requested, 1.0, 1.0);
    }

    private function health(int $charId): float
    {
        $row = $this->db()->table('characters')->select('health')->where('id', $charId)->get()->getRowArray();

        return is_array($row) && is_numeric($row['health'] ?? null) ? (float) $row['health'] : -1.0;
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect('tests');
    }

    private function createIfMissing(string $table, string $ddl): void
    {
        $db = $this->db();
        if ($db->tableExists($table)) {
            return;
        }
        $db->query($ddl);
        $db->resetDataCache();
        $this->createdTables[] = $table;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertTracked(string $table, array $data): int
    {
        $this->db()->table($table)->insert($data);
        $id = (int) $this->db()->insertID();
        $this->ownRows[$table][] = $id;

        return $id;
    }
}

final class HungerSendSpy extends FoodAndWaterConsumptionHandler
{
    /** @var list<string> */
    public static array $captions = [];

    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
        self::$captions[] = $caption;
    }
}
