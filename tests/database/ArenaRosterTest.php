<?php

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\PVP\ArenaAction;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionClass;
use ReflectionMethod;

/**
 * E25 (ADR-124) — ростер «🏟 Арены»: список бойцов, открытых к дуэлям.
 *
 * Проверяет, что roster():
 *  - возвращает только `duels_open=1` (закрытые исключены);
 *  - исключает самого игрока;
 *  - сортирует по убыванию очков ладдера, затем по уровню;
 *  - подтягивает очки через LEFT JOIN pvp_ladder (0 если строки нет).
 *
 * @internal
 */
final class ArenaRosterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');
        foreach (['characters', 'pvp_ladder'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NULL,
                level INT NULL DEFAULT 1,
                duels_open TINYINT NULL DEFAULT 0
            )
        ');
        $db->query('
            CREATE TABLE pvp_ladder (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                points INT NULL DEFAULT 0
            )
        ');

        // self=1 (open), 2 open/L10/50очк, 3 open/L20/0очк, 4 closed, 5 open/L5/100очк.
        $c = $db->table('characters');
        $c->insert(['id' => 1, 'name' => 'Self',  'level' => 30, 'duels_open' => 1]);
        $c->insert(['id' => 2, 'name' => 'Bravo', 'level' => 10, 'duels_open' => 1]);
        $c->insert(['id' => 3, 'name' => 'Charlie', 'level' => 20, 'duels_open' => 1]);
        $c->insert(['id' => 4, 'name' => 'Delta', 'level' => 40, 'duels_open' => 0]);
        $c->insert(['id' => 5, 'name' => 'Echo',  'level' => 5,  'duels_open' => 1]);

        $l = $db->table('pvp_ladder');
        $l->insert(['character_id' => 2, 'points' => 50]);
        $l->insert(['character_id' => 5, 'points' => 100]);
        // char 3 — без строки ладдера (0 очков через COALESCE).
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['characters', 'pvp_ladder'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    /** @return array<int, array<string,mixed>> */
    private function roster(int $selfId): array
    {
        $action = (new ReflectionClass(ArenaAction::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(ArenaAction::class, 'roster');
        $m->setAccessible(true);
        /** @var array<int, array<string,mixed>> $r */
        $r = $m->invoke($action, $selfId);
        return $r;
    }

    private static function num(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : -1;
    }

    /** @return array<int,int> */
    private function ids(int $selfId): array
    {
        return array_map(static fn (array $r): int => self::num($r['id'] ?? null), $this->roster($selfId));
    }

    public function testExcludesSelfAndClosed(): void
    {
        $ids = $this->ids(1);
        $this->assertNotContains(1, $ids, 'self исключён');
        $this->assertNotContains(4, $ids, 'closed исключён');
        $this->assertEqualsCanonicalizing([2, 3, 5], $ids);
    }

    public function testOrderedByPointsThenLevel(): void
    {
        // Echo(100) > Bravo(50) > Charlie(0).
        $this->assertSame([5, 2, 3], $this->ids(1));
    }

    public function testPointsCoalescedToZero(): void
    {
        $byId = [];
        foreach ($this->roster(1) as $r) {
            $byId[self::num($r['id'] ?? null)] = self::num($r['pts'] ?? null);
        }
        $this->assertSame(100, $byId[5]);
        $this->assertSame(50, $byId[2]);
        $this->assertSame(0, $byId[3], 'нет строки ладдера → 0 через COALESCE');
    }
}
