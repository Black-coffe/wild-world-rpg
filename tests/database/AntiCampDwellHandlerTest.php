<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\NPC\AntiCampDwellHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * WB10 (ADR-137 «Узлы») — AntiCampDwellHandler: накопление online-dwell, warn-пинг, loot-lock,
 * сброс при уходе из радиуса, оффлайн-заморозка, killswitch.
 *
 * Telegram-отправка подменена seam'ом sendWarn (CI без бот-ключа). Пороги: dwell_hours=1 (lock 60),
 * warn_minutes=30 (warn ≥30), radius=2, active_window=30.
 *
 * @internal
 */
final class AntiCampDwellHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'characters', 'telegram_users', 'map', 'boss_points', 'boss_camp_state'];

    /** @var list<int> tg-id'ы, которым ушёл warn-пинг */
    private array $warns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $this->warns = [];
        $db          = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_float DOUBLE NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT, telegram_user_id INT)');
        $db->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT, last_seen DATETIME NULL, blocked_at DATETIME NULL)');
        $db->query('CREATE TABLE map (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT, coordinate_x INT, coordinate_y INT)');
        $db->query("CREATE TABLE boss_points (id INT AUTO_INCREMENT PRIMARY KEY, cell_number INT, coordinate_x INT, coordinate_y INT, status VARCHAR(16))");
        $db->query('CREATE TABLE boss_camp_state (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, boss_point_id INT, first_seen_in_zone_at DATETIME NULL, dwell_minutes INT DEFAULT 0, loot_locked_until DATETIME NULL, last_warned_at DATETIME NULL, UNIQUE KEY uniq_cs (character_id, boss_point_id))');

        // Узел: id=1, клетка 1000, (500,100), alive.
        $db->table('boss_points')->insert(['cell_number' => 1000, 'coordinate_x' => 500, 'coordinate_y' => 100, 'status' => 'alive']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    private function setInt(string $key, int $v): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $key, 'value_type' => 'int', 'value_int' => $v]);
        $this->cleanCache();
    }

    private function enable(): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => 'world.nodes.point_mode_enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $this->setInt('world.nodes.anticamp.radius', 2);
        $this->setInt('world.nodes.anticamp.dwell_hours', 1);            // lockMin = 60
        $this->setInt('world.nodes.anticamp.warn_minutes', 30);         // warn ≥ 30
        $this->setInt('world.nodes.anticamp.active_window_minutes', 30);
        $this->cleanCache();
    }

    /** Создать перса в клетке (x,y) с online/offline last_seen. */
    private function makeChar(int $cell, int $x, int $y, bool $online = true, ?int $tgId = null): int
    {
        $db   = Database::connect('tests');
        $tgId ??= 500 + $cell;
        $db->table('telegram_users')->insert([
            'telegram_id' => $tgId,
            'last_seen'   => $online ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime('-90 minutes')),
            'blocked_at'  => null,
        ]);
        $tuId = (int) $db->insertID();
        $db->table('map')->insert(['cell_number' => $cell, 'coordinate_x' => $x, 'coordinate_y' => $y]);
        $db->table('characters')->insert(['cell_number' => $cell, 'telegram_user_id' => $tuId]);

        return (int) $db->insertID();
    }

    /** @param array<string,mixed> $over */
    private function seedCamp(int $charId, array $over = []): void
    {
        Database::connect('tests')->table('boss_camp_state')->insert(array_merge([
            'character_id'  => $charId,
            'boss_point_id' => 1,
            'dwell_minutes' => 0,
        ], $over));
    }

    /** @return array<string,mixed>|null */
    private function camp(int $charId): ?array
    {
        return Database::connect('tests')->table('boss_camp_state')->where('character_id', $charId)->where('boss_point_id', 1)->get()->getRowArray();
    }

    private function handler(): AntiCampDwellHandler
    {
        $h = new class extends AntiCampDwellHandler {
            /** @var list<int> */
            public array $captured = [];

            protected function sendWarn(int $tgId): void
            {
                $this->captured[] = $tgId;
            }
        };

        return $h;
    }

    // ----------------------------------------------------------------

    public function testDormantNoop(): void
    {
        // point_mode OFF → даже ушедшего из зоны не сбрасываем.
        $c = $this->makeChar(3000, 600, 100); // вне радиуса (dx=100)
        $this->seedCamp($c, ['dwell_minutes' => 50]);
        $this->handler()->run();
        $this->assertNotNull($this->camp($c), 'dormant → строка не тронута');
    }

    public function testFreshAccumulationCreatesRow(): void
    {
        $this->enable();
        $c = $this->makeChar(2000, 501, 100); // dx=1 ≤ 2 → в зоне
        $this->handler()->run();
        $row = $this->camp($c);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['dwell_minutes'], 'свежий вход → dwell=1');
        $this->assertNotNull($row['first_seen_in_zone_at']);
        $this->assertNull($row['loot_locked_until']);
    }

    public function testAccumulationIncrements(): void
    {
        $this->enable();
        $c = $this->makeChar(2000, 500, 100); // на самом узле
        $this->seedCamp($c, ['dwell_minutes' => 5, 'first_seen_in_zone_at' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);
        $this->handler()->run();
        $this->assertSame(6, (int) $this->camp($c)['dwell_minutes'], 'существующий → dwell+1');
    }

    public function testWarnPingBeforeLock(): void
    {
        $this->enable();
        $c = $this->makeChar(2000, 500, 100, true, 777);
        $this->seedCamp($c, ['dwell_minutes' => 29]); // → 30, warn-окно [30..59]
        $h = $this->handler();
        $h->run();
        $row = $this->camp($c);
        $this->assertSame([777], $h->captured, 'warn-пинг ушёл');
        $this->assertNotNull($row['last_warned_at'], 'отмечен last_warned_at (one-shot)');
        $this->assertNull($row['loot_locked_until'], 'ещё не залочен');
    }

    public function testWarnNotRepeated(): void
    {
        $this->enable();
        $c = $this->makeChar(2000, 500, 100);
        $this->seedCamp($c, ['dwell_minutes' => 40, 'last_warned_at' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);
        $h = $this->handler();
        $h->run();
        $this->assertSame([], $h->captured, 'повторно не предупреждаем (last_warned_at уже стоит)');
    }

    public function testLockWhenThresholdReached(): void
    {
        $this->enable();
        $c = $this->makeChar(2000, 500, 100);
        $this->seedCamp($c, ['dwell_minutes' => 59, 'last_warned_at' => date('Y-m-d H:i:s')]); // → 60 = lockMin
        $h = $this->handler();
        $h->run();
        $row = $this->camp($c);
        $this->assertNotNull($row['loot_locked_until'], 'dwell ≥ порога → выставлен loot_locked_until');
        $this->assertGreaterThan(time(), strtotime((string) $row['loot_locked_until']), 'лок в будущем');
        $this->assertSame([], $h->captured, 'на локе warn не шлём');
    }

    public function testResetWhenOutOfRadius(): void
    {
        $this->enable();
        $c = $this->makeChar(3000, 600, 100); // dx=100 > 2 → вне зоны
        $this->seedCamp($c, ['dwell_minutes' => 50, 'loot_locked_until' => date('Y-m-d H:i:s', strtotime('+1 hour'))]);
        $this->handler()->run();
        $this->assertNull($this->camp($c), 'ушёл из радиуса → строка удалена (сброс)');
    }

    public function testOfflineDoesNotAccumulate(): void
    {
        $this->enable();
        $c = $this->makeChar(2000, 500, 100, false); // в зоне, но last_seen 90м назад
        $this->seedCamp($c, ['dwell_minutes' => 5]);
        $this->handler()->run();
        $row = $this->camp($c);
        $this->assertNotNull($row, 'в радиусе → не сброшен');
        $this->assertSame(5, (int) $row['dwell_minutes'], '🔴 оффлайн НЕ копит dwell (заморожен)');
    }

    public function testBlockedUserSkipped(): void
    {
        $this->enable();
        $c = $this->makeChar(2000, 500, 100);
        Database::connect('tests')->table('telegram_users')->where('id', 1)->update(['blocked_at' => date('Y-m-d H:i:s')]);
        $this->seedCamp($c, ['dwell_minutes' => 5]);
        $this->handler()->run();
        $this->assertSame(5, (int) $this->camp($c)['dwell_minutes'], 'заблокировавший бота не обрабатывается');
    }

    public function testRadiusBoundaryInVsOut(): void
    {
        $this->enable();
        $in  = $this->makeChar(2002, 502, 100); // dx=2 = R → в зоне
        $out = $this->makeChar(2003, 503, 100); // dx=3 > R → вне зоны
        $this->seedCamp($in, ['dwell_minutes' => 5]);
        $this->seedCamp($out, ['dwell_minutes' => 5]);
        $this->handler()->run();
        $this->assertSame(6, (int) $this->camp($in)['dwell_minutes'], 'на границе R=2 — в зоне, копит');
        $this->assertNull($this->camp($out), 'за границей — сброшен');
    }

    public function testCooldownNodeNotCounted(): void
    {
        $this->enable();
        Database::connect('tests')->table('boss_points')->where('id', 1)->update(['status' => 'cooldown']);
        $c = $this->makeChar(2000, 500, 100);
        $this->handler()->run();
        $this->assertNull($this->camp($c), 'у мёртвого (cooldown) узла dwell не копится (нечего кемпить)');
    }
}
