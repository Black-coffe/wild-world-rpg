<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\PVE\AntiCampService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * WB10 (ADR-137 «Узлы») — AntiCampService: read-side проверки loot-lock на kill-пути.
 *
 * isLootLocked (killer-кредит/«гарь узла»), lockedSubset (исключение из лотереи трофея). Лок активен,
 * пока строка boss_camp_state жива И loot_locked_until > NOW.
 *
 * @internal
 */
final class AntiCampServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['boss_camp_state'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE boss_camp_state (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, boss_point_id INT, first_seen_in_zone_at DATETIME NULL, dwell_minutes INT DEFAULT 0, loot_locked_until DATETIME NULL, last_warned_at DATETIME NULL, UNIQUE KEY uniq_cs (character_id, boss_point_id))');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    private function seed(int $charId, int $pointId, ?string $lockUntil): void
    {
        Database::connect('tests')->table('boss_camp_state')->insert([
            'character_id'      => $charId,
            'boss_point_id'     => $pointId,
            'dwell_minutes'     => 720,
            'loot_locked_until' => $lockUntil,
        ]);
    }

    // ----------------------------------------------------------------

    public function testIsLootLockedTrueWhenFutureLock(): void
    {
        $this->seed(7, 1, date('Y-m-d H:i:s', time() + 3600));
        $this->assertTrue((new AntiCampService())->isLootLocked(7, 1));
    }

    public function testIsLootLockedFalseWhenExpired(): void
    {
        $this->seed(7, 1, date('Y-m-d H:i:s', time() - 60));
        $this->assertFalse((new AntiCampService())->isLootLocked(7, 1), 'истёкший лок не считается');
    }

    public function testIsLootLockedFalseWhenNullLock(): void
    {
        $this->seed(7, 1, null);
        $this->assertFalse((new AntiCampService())->isLootLocked(7, 1), 'строка без loot_locked_until = не залочен');
    }

    public function testIsLootLockedFalseWhenNoRowOrOtherPoint(): void
    {
        $this->seed(7, 1, date('Y-m-d H:i:s', time() + 3600));
        $svc = new AntiCampService();
        $this->assertFalse($svc->isLootLocked(7, 2), 'лок у другого узла не действует');
        $this->assertFalse($svc->isLootLocked(8, 1), 'нет строки для этого перса');
        $this->assertFalse($svc->isLootLocked(0, 1), 'невалидный charId');
    }

    public function testLockedSubsetReturnsOnlyLockedAtPoint(): void
    {
        $future = date('Y-m-d H:i:s', time() + 3600);
        $past   = date('Y-m-d H:i:s', time() - 60);
        $this->seed(10, 1, $future); // залочен у точки 1
        $this->seed(11, 1, $past);   // истёк
        $this->seed(12, 1, null);    // не залочен
        $this->seed(13, 2, $future); // залочен, но у другой точки

        $locked = (new AntiCampService())->lockedSubset([10, 11, 12, 13, 14], 1);
        sort($locked);

        $this->assertSame([10], $locked, 'только активно-залоченный у точки 1');
    }

    public function testLockedSubsetEmptyInputs(): void
    {
        $svc = new AntiCampService();
        $this->assertSame([], $svc->lockedSubset([], 1));
        $this->assertSame([], $svc->lockedSubset([10], 0));
    }
}
