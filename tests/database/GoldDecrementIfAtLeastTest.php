<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\RobotRepairConfirmAction;
use App\Controllers\Telegram\Commands\Actions\Drone\RepairDroneRunAction;
use App\Database\Migrations\V20CreateFactionProjects;
use App\Services\Player\FactionProjectService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;

/**
 * s2-conditional-writes-03 (F9, ADR-181) — золото ремонта робота, пакетного ремонта дронов и
 * вклада во фракцию списывается одной условной записью `decrementIfAtLeast` ДО эффекта.
 *
 * Форма теста спеки — не гонка, а устаревший снимок: персонаж проходит проверку-чтение (золота
 * хватает), затем золото в БД становится меньше цены, и продовый метод записи вызывается. Ожидание:
 * отказ, `gold` не ниже нуля и не изменён, прочность/заряд/прогресс не тронуты.
 *
 * Action-handler'ы проверяются на уровне метода, который делает запись (`applyRepair`,
 * `applyBatchRepair`) — без живого Telegram. Фракция — через публичный `deposit()`: золото
 * понижается вторым соединением сразу после того, как `deposit()` прочитал персонажа.
 *
 * Схема: `faction_projects` — из настоящей миграции `V20CreateFactionProjects`. `characters`,
 * `crafted_items_log`, `character_factions` — DDL с колонками реальной схемы, но без FK: их
 * миграции тянут FK на `telegram_users`/`tasks`/`crafted_items`, которых стенд не держит (тот же
 * приём, что `RepairBuildingShortageRollbackTest`). Таблицы создаются, только если их нет, и
 * дропаются, только если их создал этот тест; в чужих таблицах чистятся только свои строки.
 *
 * @internal
 */
final class GoldDecrementIfAtLeastTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const MSG = 'gold went below zero, or repair/deposit applied without a confirmed payment';

    protected $migrate = false;

    /** @var list<string> */
    private array $createdTables = [];

    /** @var array<string, list<int>> таблица → id своих строк */
    private array $ownRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->db();
        $db->resetDataCache();

        $this->createTableIfMissing('characters', '
            CREATE TABLE characters (
                id INT(5) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT(11) UNSIGNED NULL,
                name VARCHAR(100) NULL,
                level INT(7) NOT NULL DEFAULT 1,
                health DECIMAL(7,2) NOT NULL DEFAULT 100,
                tired DECIMAL(7,2) NOT NULL DEFAULT 100,
                gold BIGINT(20) NULL DEFAULT NULL,
                cell_number INT(5) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) AUTO_INCREMENT=' . random_int(4_000_000, 4_999_999));
        $this->createTableIfMissing('crafted_items_log', "
            CREATE TABLE crafted_items_log (
                id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT(11) UNSIGNED NOT NULL,
                task_id INT(11) UNSIGNED NULL,
                crafted_item_id INT(11) UNSIGNED NOT NULL,
                type VARCHAR(100) NULL,
                direction_craft VARCHAR(100) NULL,
                crafting_location VARCHAR(255) NULL DEFAULT 'all',
                durability_count INT(11) NOT NULL DEFAULT 0,
                durability_time DATE NULL,
                quantity INT(11) NOT NULL DEFAULT 1,
                insured TINYINT(1) NOT NULL DEFAULT 0,
                custom_setting TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ");
        $this->createTableIfMissing('character_factions', '
            CREATE TABLE character_factions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                faction_id INT NOT NULL,
                joined_at DATETIME NULL
            )
        ');
        if (! $db->tableExists('faction_projects')) {
            if (! class_exists(V20CreateFactionProjects::class, false)) {
                require_once APPPATH . 'Database/Migrations/2026-05-25-170000_V20CreateFactionProjects.php';
            }
            (new V20CreateFactionProjects())->up();
            $this->createdTables[] = 'faction_projects';
            $db->resetDataCache();
        }
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('DBQuery');

        $db = $this->db();
        foreach ($this->ownRows as $table => $ids) {
            foreach ($ids as $id) {
                $db->table($table)->where('id', $id)->delete();
            }
        }
        $this->ownRows = [];

        foreach (array_reverse($this->createdTables) as $table) {
            $db->query("DROP TABLE IF EXISTS {$table}");
        }
        $this->createdTables = [];
        $db->resetDataCache();

        parent::tearDown();
    }

    // ── robot ────────────────────────────────────────────────────────────────

    public function testRobotRepairRefusesWhenGoldDroppedBelowPriceAfterReadCheck(): void
    {
        $price  = 100;
        $charId = $this->insertCharacter($price); // проверка-чтение пройдена: gold === цена
        $logId  = $this->insertLog($charId, 3);

        $this->setGold($charId, 40); // снимок устарел

        $result = (new RobotRepairConfirmAction($this->callbackQuery()))->applyRepair(
            $charId,
            $logId,
            10,
            ['gold' => $price, 'resources' => [], 'restored' => 7]
        );

        $this->assertNotSame('applied', $result, self::MSG);
        $this->assertSame(40, $this->gold($charId), self::MSG);
        $this->assertSame(3, $this->durability($logId), self::MSG);
    }

    // ── drone batch ──────────────────────────────────────────────────────────

    public function testDroneBatchRepairRefusesWhenGoldDroppedBelowPriceAfterReadCheck(): void
    {
        $price   = 100;
        $charId  = $this->insertCharacter($price);
        $robotA  = $this->insertLog($charId, 2);
        $robotB  = $this->insertLog($charId, 5);
        $droneId = $this->insertLog($charId, 50);

        $this->setGold($charId, 40);

        $robots = [
            ['logId' => $robotA, 'nameRus' => 'Робот А', 'current' => 2, 'base' => 10, 'goldCost' => 60],
            ['logId' => $robotB, 'nameRus' => 'Робот Б', 'current' => 5, 'base' => 10, 'goldCost' => 40],
        ];

        $result = (new RepairDroneRunAction($this->callbackQuery()))->applyBatchRepair($charId, $robots, $price, $droneId, 30);

        $this->assertNotSame('applied', $result, self::MSG);
        $this->assertSame(40, $this->gold($charId), self::MSG);
        $this->assertSame(2, $this->durability($robotA), self::MSG);
        $this->assertSame(5, $this->durability($robotB), self::MSG);
        $this->assertSame(50, $this->durability($droneId), self::MSG);
    }

    // ── faction deposit ──────────────────────────────────────────────────────

    public function testFactionDepositRefusesWhenGoldDroppedBelowDepositAfterReadCheck(): void
    {
        $svc       = new FactionProjectService();
        $deposit   = $svc->depositGold();
        $factionId = 2;
        $charId    = $this->insertCharacter($deposit);
        // Без joined_at: сервис его не читает, а общую character_factions соседние тесты создают и узкой.
        $this->insert('character_factions', [
            'character_id' => $charId, 'faction_id' => $factionId,
        ]);

        $project = $svc->getOrCreateProject($factionId);
        $projId  = (int) $project['id'];
        $this->ownRows['faction_projects'][] = $projId;
        $this->db()->table('faction_projects')->where('id', $projId)->update(['progress' => 5, 'cycles_completed' => 0, 'buff_until' => null]);

        // deposit() сам читает персонажа и сравнивает золото с ценой — сразу после этого чтения
        // золото понижается вторым соединением: снимок, на котором прошла проверка, устарел.
        $other = Database::connect('tests', false);
        $fired = false;
        Events::on('DBQuery', static function (Query $q) use (&$fired, $other, $charId): void {
            $sql = $q->getQuery();
            if ($fired || stripos($sql, 'SELECT') !== 0 || ! preg_match('/FROM\s+`?characters`?/i', $sql)) {
                return;
            }
            $fired = true;
            $other->query('UPDATE characters SET gold = 40 WHERE id = ?', [$charId]);
        });

        $result = $svc->deposit($charId);

        $this->assertTrue($fired, 'deposit() не прочитал персонажа — снимок не подменён');
        $this->assertFalse($result['ok'], self::MSG);
        $this->assertSame(40, $this->gold($charId), self::MSG);
        $row = $this->db()->table('faction_projects')->where('id', $projId)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame(5, (int) $row['progress'], self::MSG);
        $this->assertSame(0, (int) $row['cycles_completed'], self::MSG);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return BaseConnection<object, object> */
    private function db(): BaseConnection
    {
        /** @var BaseConnection<object, object> $db */
        $db = Database::connect('tests');

        return $db;
    }

    private function createTableIfMissing(string $table, string $ddl): void
    {
        if ($this->db()->tableExists($table)) {
            return;
        }
        $this->db()->query($ddl);
        $this->createdTables[] = $table;
        $this->db()->resetDataCache();
    }

    /** @param array<string,mixed> $row */
    private function insert(string $table, array $row): int
    {
        $this->db()->table($table)->insert($row);
        $id = (int) $this->db()->insertID();
        $this->ownRows[$table][] = $id;

        return $id;
    }

    private function insertCharacter(int $gold): int
    {
        return $this->insert('characters', [
            'name' => 'gold-f9', 'gold' => $gold,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function insertLog(int $charId, int $durability): int
    {
        return $this->insert('crafted_items_log', [
            'character_id' => $charId, 'crafted_item_id' => random_int(800_000, 899_999), 'type' => 'robots',
            'durability_count' => $durability, 'quantity' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function setGold(int $charId, int $gold): void
    {
        $this->db()->table('characters')->where('id', $charId)->update(['gold' => $gold]);
    }

    private function gold(int $charId): int
    {
        $row = $this->db()->table('characters')->select('gold')->where('id', $charId)->get()->getRowArray();

        return is_array($row) ? (int) $row['gold'] : PHP_INT_MIN;
    }

    private function durability(int $logId): int
    {
        $row = $this->db()->table('crafted_items_log')->select('durability_count')->where('id', $logId)->get()->getRowArray();

        return is_array($row) ? (int) $row['durability_count'] : PHP_INT_MIN;
    }

    private function callbackQuery(): CallbackQuery
    {
        $tgId = random_int(1_000_000, 9_999_999);

        return new CallbackQuery([
            'id'      => 'cbq_' . random_int(1, 999_999_999),
            'from'    => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1, 'date' => time(),
                'chat'       => ['id' => $tgId, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_' . $tgId,
            'data'          => 'noop',
        ]);
    }
}
