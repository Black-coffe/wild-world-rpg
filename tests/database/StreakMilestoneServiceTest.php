<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\StreakMilestoneService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-132 — StreakMilestoneService: killswitch + set-based qualifying + idempotent claim + grants.
 *
 * @internal
 */
final class StreakMilestoneServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'game_settings', 'streak_milestones', 'character_streak_milestones',
        'characters', 'resources', 'character_resources', 'titles', 'character_titles',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, category VARCHAR(32) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE streak_milestones (id INT AUTO_INCREMENT PRIMARY KEY, threshold_days INT, name VARCHAR(128), description VARCHAR(512) DEFAULT \'\', icon VARCHAR(16) DEFAULT \'🔥\', reward_gold INT DEFAULT 0, reward_resource VARCHAR(128) NULL, reward_resource_qty INT DEFAULT 0, title_key VARCHAR(64) NULL, grants_freeze INT DEFAULT 0, sort_order INT DEFAULT 0, enabled TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE character_streak_milestones (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, streak_milestone_id INT, claimed_at DATETIME NULL, UNIQUE KEY uniq_char_milestone (character_id, streak_milestone_id))');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, login_streak INT DEFAULT 0, gold INT DEFAULT 0, active_title_id INT NULL, telegram_user_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128))');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT, quantity INT DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE titles (id INT AUTO_INCREMENT PRIMARY KEY, title_key VARCHAR(64), name VARCHAR(128), description VARCHAR(512) NULL, source_type VARCHAR(32) NULL, source_ref VARCHAR(64) NULL, icon VARCHAR(16) NULL, sort_order INT DEFAULT 0, enabled TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE character_titles (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, title_id INT, unlocked_at DATETIME NULL, UNIQUE KEY uniq_char_title (character_id, title_id))');
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

    private function svc(): StreakMilestoneService
    {
        return new StreakMilestoneService();
    }

    /**
     * @param array<string,mixed> $opts
     */
    private function seedMilestone(int $threshold, array $opts = []): int
    {
        $db = Database::connect('tests');
        $db->table('streak_milestones')->insert(array_merge([
            'threshold_days'      => $threshold,
            'name'                => 'M' . $threshold,
            'description'         => 'd',
            'icon'                => '🔥',
            'reward_gold'         => 0,
            'reward_resource'     => null,
            'reward_resource_qty' => 0,
            'title_key'           => null,
            'grants_freeze'       => 0,
            'sort_order'          => $threshold,
            'enabled'             => 1,
        ], $opts));
        return (int) $db->insertID();
    }

    private function seedChar(int $streak = 0, int $gold = 0): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['login_streak' => $streak, 'gold' => $gold, 'telegram_user_id' => 1]);
        return (int) $db->insertID();
    }

    private function seedResource(string $name): int
    {
        $db = Database::connect('tests');
        $db->table('resources')->insert(['name' => $name]);
        return (int) $db->insertID();
    }

    private function seedTitle(string $key, string $name): int
    {
        $db = Database::connect('tests');
        $db->table('titles')->insert(['title_key' => $key, 'name' => $name, 'source_type' => 'streak', 'source_ref' => '0', 'icon' => '🎖', 'enabled' => 1]);
        return (int) $db->insertID();
    }

    private function enableMilestones(): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'returnability.streak.milestones_enabled', 'value_type' => 'bool', 'value_bool' => 1,
        ]);
        $this->cleanCache();
    }

    public function testKillswitchDefaultOff(): void
    {
        $this->assertFalse($this->svc()->enabled());
    }

    public function testKillswitchOn(): void
    {
        $this->enableMilestones();
        $this->assertTrue($this->svc()->enabled());
    }

    public function testMaxAwardsPerTickDefault(): void
    {
        $this->assertSame(25, $this->svc()->maxAwardsPerTick());
    }

    public function testDefinitionsOnlyEnabledOrderedByThreshold(): void
    {
        $this->seedMilestone(10, ['name' => 'b']);
        $this->seedMilestone(3, ['name' => 'a']);
        $this->seedMilestone(7, ['name' => 'off', 'enabled' => 0]);

        $names = array_map(static fn ($d) => $d['name'], $this->svc()->definitions());
        $this->assertSame(['a', 'b'], $names); // 3 раньше 10, отключённая 7 исключена
    }

    public function testQualifyingByStreakThreshold(): void
    {
        $mid = $this->seedMilestone(7);
        $c3  = $this->seedChar(3);
        $c7  = $this->seedChar(7);
        $c20 = $this->seedChar(20);

        $milestone = Database::connect('tests')->table('streak_milestones')->where('id', $mid)->get()->getRowArray();
        $ids = $this->svc()->qualifyingCharacterIds($milestone);
        sort($ids);
        $this->assertSame([$c7, $c20], $ids); // streak >= 7
        $this->assertNotContains($c3, $ids);
    }

    public function testQualifyingExcludesClaimed(): void
    {
        $svc = $this->svc();
        $mid = $this->seedMilestone(5);
        $c5  = $this->seedChar(5);
        $c9  = $this->seedChar(9);

        $svc->claim($c5, $mid);
        $milestone = Database::connect('tests')->table('streak_milestones')->where('id', $mid)->get()->getRowArray();
        $ids = $svc->qualifyingCharacterIds($milestone);
        $this->assertSame([$c9], $ids); // c5 уже забрал → исключён
    }

    public function testQualifyingLimitCaps(): void
    {
        $mid = $this->seedMilestone(3);
        $this->seedChar(5);
        $this->seedChar(5);
        $this->seedChar(5);

        $milestone = Database::connect('tests')->table('streak_milestones')->where('id', $mid)->get()->getRowArray();
        $ids = $this->svc()->qualifyingCharacterIds($milestone, 2);
        $this->assertCount(2, $ids);
    }

    public function testClaimIdempotent(): void
    {
        $svc = $this->svc();
        $mid = $this->seedMilestone(3);
        $cid = $this->seedChar(3);

        $this->assertTrue($svc->claim($cid, $mid));
        $this->assertFalse($svc->claim($cid, $mid)); // повтор
        $count = Database::connect('tests')->table('character_streak_milestones')
            ->where('character_id', $cid)->where('streak_milestone_id', $mid)->countAllResults();
        $this->assertSame(1, $count);
    }

    public function testGrantRewardsGoldResourceAndTitle(): void
    {
        $this->seedResource('Вода');
        $this->seedTitle('streak_persistent', 'Упорный');
        $mid = $this->seedMilestone(10, [
            'reward_gold' => 300, 'reward_resource' => 'Вода', 'reward_resource_qty' => 5, 'title_key' => 'streak_persistent',
        ]);
        $cid = $this->seedChar(10, 1000);

        $milestone = Database::connect('tests')->table('streak_milestones')->where('id', $mid)->get()->getRowArray();
        $summary   = $this->svc()->grantRewards($cid, $milestone);

        $this->assertSame(300, $summary['gold']);
        $this->assertSame('Вода', $summary['resource']);
        $this->assertSame(5, $summary['resource_qty']);
        $this->assertSame('Упорный', $summary['title']);

        $db   = Database::connect('tests');
        $gold = (int) $db->table('characters')->where('id', $cid)->get()->getRowArray()['gold'];
        $this->assertSame(1300, $gold);
        $qty = (int) $db->table('character_resources')->where('id_characters', $cid)->get()->getRowArray()['quantity'];
        $this->assertSame(5, $qty);
        $titleRows = $db->table('character_titles')->where('character_id', $cid)->countAllResults();
        $this->assertSame(1, $titleRows);
    }

    public function testGrantRewardsResourceNotFoundSkips(): void
    {
        $mid = $this->seedMilestone(3, ['reward_gold' => 100, 'reward_resource' => 'НесуществующийРесурс', 'reward_resource_qty' => 5]);
        $cid = $this->seedChar(3, 0);

        $milestone = Database::connect('tests')->table('streak_milestones')->where('id', $mid)->get()->getRowArray();
        $summary   = $this->svc()->grantRewards($cid, $milestone);

        $this->assertSame(100, $summary['gold']);
        $this->assertNull($summary['resource']);
        $this->assertSame(0, $summary['resource_qty']);
        $rows = Database::connect('tests')->table('character_resources')->where('id_characters', $cid)->countAllResults();
        $this->assertSame(0, $rows); // ресурс не начислен — нет строки
    }
}
