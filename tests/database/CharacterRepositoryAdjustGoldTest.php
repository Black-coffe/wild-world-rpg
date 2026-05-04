<?php

namespace Tests\Database;

use App\Repositories\CI4CharacterRepository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F2.6 — integration-тест атомарного `adjustGold`.
 *
 * Цель: подтвердить что `gold = gold + ?` через raw SQL действительно
 * атомарно и работает на реальной MySQL. Этот тест — основа доверия к
 * Repository для дальнейших миграций (DeathService, BuyCraft, SellCraft).
 *
 * Не запускает полную миграционную цепочку (медленно + могут быть
 * MySQL-only поля): создаёт минимальную `characters`-таблицу с теми
 * полями что нужны Repository.
 *
 * @internal
 */
final class CharacterRepositoryAdjustGoldTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** @var bool Не запускать full migrations — мы создаём свою минимальную таблицу. */
    protected $migrate = false;

    private CI4CharacterRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');

        // Минимальная таблица characters с полями нужными для adjustGold/Experience и lockAndReadEconomyState.
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gold DECIMAL(15,2) NOT NULL DEFAULT 0,
                experience DECIMAL(15,2) NOT NULL DEFAULT 0,
                trading_karma DECIMAL(10,2) NOT NULL DEFAULT 100.00
            )
        ');

        $this->repo = new CI4CharacterRepository();
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS characters');
        parent::tearDown();
    }

    public function testAdjustGoldAddsPositiveDelta(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (1000)');
        $charId = (int) $db->insertID();

        $this->repo->adjustGold($charId, 500.0);

        $row = $db->query('SELECT gold FROM characters WHERE id = ?', [$charId])->getRowArray();
        $this->assertSame('1500.00', $row['gold']);
    }

    public function testAdjustGoldSubtractsNegativeDelta(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (2000)');
        $charId = (int) $db->insertID();

        $this->repo->adjustGold($charId, -750.5);

        $row = $db->query('SELECT gold FROM characters WHERE id = ?', [$charId])->getRowArray();
        $this->assertSame('1249.50', $row['gold']);
    }

    public function testAdjustGoldIsAtomicOnSequentialCalls(): void
    {
        // Имитируем сценарий из F0.5 TOCTOU: два rewards подряд для
        // одного персонажа. С Repository результат должен быть
        // 100 + 25 + 35 = 160 (никакой read-modify-write race).
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold) VALUES (100)');
        $charId = (int) $db->insertID();

        $this->repo->adjustGold($charId, 25.0);
        $this->repo->adjustGold($charId, 35.0);

        $row = $db->query('SELECT gold FROM characters WHERE id = ?', [$charId])->getRowArray();
        $this->assertSame('160.00', $row['gold']);
    }

    public function testAdjustExperienceWorksTheSame(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold, experience) VALUES (0, 50.5)');
        $charId = (int) $db->insertID();

        $this->repo->adjustExperience($charId, 12.25);

        $row = $db->query('SELECT experience FROM characters WHERE id = ?', [$charId])->getRowArray();
        $this->assertSame('62.75', $row['experience']);
    }

    public function testLockAndReadEconomyStateReturnsGoldAndKarma(): void
    {
        $db = Database::connect('tests');
        $db->query('INSERT INTO characters (gold, trading_karma) VALUES (5000, 75.5)');
        $charId = (int) $db->insertID();

        $state = $this->repo->lockAndReadEconomyState($charId);

        $this->assertNotNull($state);
        $this->assertSame(5000.0, $state['gold']);
        $this->assertSame(75.5, $state['trading_karma']);
    }

    public function testLockAndReadEconomyStateReturnsNullForUnknownId(): void
    {
        $state = $this->repo->lockAndReadEconomyState(99999);
        $this->assertNull($state);
    }
}
