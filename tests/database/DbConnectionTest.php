<?php

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F2 sanity-test: подтверждает что integration-test infra собрана.
 *
 * Локально работает на laragon MySQL (root / no password / wildworld_tests),
 * в CI — на mysql:8.0 service container с теми же creds.
 *
 * Если этот тест зелёный — можно писать integration-tests на Repository
 * и handler'ы.
 *
 * @internal
 */
final class DbConnectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /**
     * Запретить migrations — для базовой проверки коннекта они не нужны,
     * сильно ускоряет тест и обходит возможные MySQL-only миграции.
     */
    protected $migrate = false;

    public function testTestsGroupConnects(): void
    {
        $db = Database::connect('tests');
        $this->assertInstanceOf(\CodeIgniter\Database\BaseConnection::class, $db);

        $row = $db->query('SELECT 1 AS one')->getRowArray();
        $this->assertSame('1', (string) $row['one']);
    }

    public function testTestsGroupIsMySQLi(): void
    {
        $db = Database::connect('tests');
        $this->assertSame('MySQLi', $db->DBDriver);
    }
}
