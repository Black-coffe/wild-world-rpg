<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\NPC\NodeAnnounceDigestHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * WB11 (ADR-137 «Узлы») — NodeAnnounceDigestHandler: killswitch / hour-guard / once-day-claim /
 * сборка сводки / opt-out фильтр / потребление очереди / пустая очередь = no-op.
 *
 * Реальная отправка замокана seam'ом sendDigest. @internal
 */
final class NodeAnnounceDigestHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'boss_kill_announce_queue', 'biomes', 'characters', 'telegram_users'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DOUBLE NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query("CREATE TABLE boss_kill_announce_queue (id INT AUTO_INCREMENT PRIMARY KEY, boss_point_id INT, boss_level INT, biome_id INT NULL, status VARCHAR(16) DEFAULT 'pending', created_at DATETIME NULL)");
        $db->query('CREATE TABLE biomes (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, node_announce_enabled TINYINT NOT NULL DEFAULT 1)');
        $db->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL, blocked_at DATETIME NULL)');

        // Happy-path: анонс включён, час = текущий.
        $this->setSetting('world.nodes.announce_enabled', 'bool', ['value_bool' => 1]);
        $this->setSetting('world.nodes.announce_digest_hour', 'int', ['value_int' => (int) date('G')]);

        $db->table('biomes')->insert(['id' => 1, 'name' => 'Болота']);
        $db->table('biomes')->insert(['id' => 2, 'name' => 'Технопарк']);

        // Игроки: A opt-in, B opt-out, C заблокировал бота.
        $db->table('telegram_users')->insert(['id' => 10, 'telegram_id' => 1010, 'blocked_at' => null]);
        $db->table('telegram_users')->insert(['id' => 20, 'telegram_id' => 2020, 'blocked_at' => null]);
        $db->table('telegram_users')->insert(['id' => 30, 'telegram_id' => 3030, 'blocked_at' => date('Y-m-d H:i:s')]);
        $db->table('characters')->insert(['id' => 1, 'telegram_user_id' => 10, 'node_announce_enabled' => 1]);
        $db->table('characters')->insert(['id' => 2, 'telegram_user_id' => 20, 'node_announce_enabled' => 0]);
        $db->table('characters')->insert(['id' => 3, 'telegram_user_id' => 30, 'node_announce_enabled' => 1]);
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

    /** @param array<string,int> $cols */
    private function setSetting(string $key, string $type, array $cols): void
    {
        Database::connect('tests')->table('game_settings')->insert(array_merge(
            ['setting_key' => $key, 'category' => 'world', 'value_type' => $type],
            $cols
        ));
        $this->cleanCache();
    }

    private function queue(int $level, ?int $biomeId): void
    {
        Database::connect('tests')->table('boss_kill_announce_queue')->insert([
            'boss_point_id' => 1, 'boss_level' => $level, 'biome_id' => $biomeId, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function pendingCount(): int
    {
        return Database::connect('tests')->table('boss_kill_announce_queue')->where('status', 'pending')->countAllResults();
    }

    private function handler(): NodeAnnounceDigestHandler
    {
        return new class extends NodeAnnounceDigestHandler {
            /** @var list<array{int,string}> */
            public array $sent = [];

            protected function sendDigest(int $tgId, string $text): void
            {
                $this->sent[] = [$tgId, $text];
            }
        };
    }

    // ----------------------------------------------------------------

    public function testHappyPathSendsToOptInAndConsumesQueue(): void
    {
        $this->queue(120, 1);
        $this->queue(95, 2);

        $h = $this->handler();
        $h->run();

        // Только игрок A (opt-in, не заблокирован) получил.
        $this->assertCount(1, $h->sent);
        $this->assertSame(1010, $h->sent[0][0]);
        $this->assertStringContainsString('Сводка с пустоши', $h->sent[0][1]);
        $this->assertStringContainsString('L120 — Болота', $h->sent[0][1]);
        $this->assertStringContainsString('L95 — Технопарк', $h->sent[0][1]);
        // Очередь потреблена.
        $this->assertSame(0, $this->pendingCount());
    }

    public function testKillswitchOffNoop(): void
    {
        Database::connect('tests')->table('game_settings')->where('setting_key', 'world.nodes.announce_enabled')->update(['value_bool' => 0]);
        $this->cleanCache();
        $this->queue(120, 1);

        $h = $this->handler();
        $h->run();

        $this->assertCount(0, $h->sent);
        $this->assertSame(1, $this->pendingCount(), 'killswitch off → очередь не тронута');
    }

    public function testWrongHourNoop(): void
    {
        $other = ((int) date('G') + 1) % 24;
        Database::connect('tests')->table('game_settings')->where('setting_key', 'world.nodes.announce_digest_hour')->update(['value_int' => $other]);
        $this->cleanCache();
        $this->queue(120, 1);

        $h = $this->handler();
        $h->run();

        $this->assertCount(0, $h->sent);
        $this->assertSame(1, $this->pendingCount());
    }

    public function testEmptyQueueSendsNothing(): void
    {
        $h = $this->handler();
        $h->run();
        $this->assertCount(0, $h->sent, 'нет pending-килов → сводка не шлётся');
    }

    public function testOnceDayClaimPreventsSecondRun(): void
    {
        $this->queue(120, 1);
        $first = $this->handler();
        $first->run();
        $this->assertCount(1, $first->sent);

        // Тот же день: новый килл в очереди, но claim уже взят → не шлём.
        $this->queue(130, 2);
        $second = $this->handler();
        $second->run();
        $this->assertCount(0, $second->sent, 'once/day-claim блокирует второй прогон');
        $this->assertSame(1, $this->pendingCount(), 'новый килл остался в очереди до завтра');
    }

    public function testNullBiomeFallback(): void
    {
        $this->queue(150, null);
        $h = $this->handler();
        $h->run();
        $this->assertStringContainsString('L150 — пустошь', $h->sent[0][1], 'без биома → «пустошь»');
    }

    public function testBuildDigestTextCapsAndPluralizes(): void
    {
        $entries = [];
        for ($i = 0; $i < 15; $i++) {
            $entries[] = ['level' => 200 - $i, 'biome' => 'Север'];
        }
        $text = $this->handler()->buildDigestText($entries, 12);
        // 12 показано, 3 в остатке.
        $this->assertStringContainsString('и ещё *3* узла', $text);
        $this->assertStringContainsString('через 12ч', $text);
    }
}
