<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\AchievementModel;
use App\Models\CharacterAchievementModel;
use App\Services\Player\AchievementService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W9 (ADR-066) — AchievementService: killswitch + idempotent award + set-based criteria.
 *
 * @internal
 */
final class AchievementServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'achievements', 'character_achievements', 'characters', 'claimed_cells'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, category VARCHAR(32) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE achievements (id INT AUTO_INCREMENT PRIMARY KEY, achievement_key VARCHAR(64), title VARCHAR(255) NULL, description VARCHAR(500) NULL, category VARCHAR(32) NULL, icon VARCHAR(16) NULL, criteria_type VARCHAR(32), criteria_target INT DEFAULT 1, points INT DEFAULT 0, sort_order INT DEFAULT 0, enabled TINYINT DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE character_achievements (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, achievement_id INT, unlocked_at DATETIME NULL, UNIQUE KEY uniq_char_ach (character_id, achievement_id))');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, level INT DEFAULT 1, gold INT DEFAULT 0, telegram_user_id INT NULL)');
        $db->query('CREATE TABLE claimed_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, status VARCHAR(16) DEFAULT \'active\')');
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

    private function svc(): AchievementService
    {
        return new AchievementService(new AchievementModel(), new CharacterAchievementModel());
    }

    private function seedAchievement(string $key, string $type, int $target, int $enabled = 1, int $sort = 0): int
    {
        $db = Database::connect('tests');
        $db->table('achievements')->insert([
            'achievement_key' => $key, 'title' => $key, 'description' => 'd', 'category' => 'g',
            'icon' => '🏅', 'criteria_type' => $type, 'criteria_target' => $target, 'enabled' => $enabled, 'sort_order' => $sort,
        ]);
        return (int) $db->insertID();
    }

    private function seedChar(int $level = 1, int $gold = 0): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['level' => $level, 'gold' => $gold, 'telegram_user_id' => 1]);
        return (int) $db->insertID();
    }

    public function testKillswitchDefaultOff(): void
    {
        $this->assertFalse($this->svc()->isEnabled());
    }

    public function testKillswitchOn(): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'achievement.enabled', 'value_type' => 'bool', 'value_bool' => 1,
        ]);
        $this->cleanCache();
        $this->assertTrue($this->svc()->isEnabled());
    }

    public function testMaxAwardsPerTickDefault(): void
    {
        $this->assertSame(25, $this->svc()->maxAwardsPerTick());
    }

    public function testDefinitionsOnlyEnabledOrdered(): void
    {
        $this->seedAchievement('b', 'char_level', 5, 1, 20);
        $this->seedAchievement('a', 'char_level', 2, 1, 10);
        $this->seedAchievement('off', 'char_level', 1, 0, 5);

        $keys = array_map(static fn ($d) => $d['achievement_key'], $this->svc()->definitions());
        $this->assertSame(['a', 'b'], $keys);
    }

    public function testAwardIdempotent(): void
    {
        $svc   = $this->svc();
        $achId = $this->seedAchievement('first', 'char_level', 1);
        $cid   = $this->seedChar();

        $this->assertTrue($svc->award($cid, $achId));
        $this->assertFalse($svc->award($cid, $achId)); // повтор
        $count = Database::connect('tests')->table('character_achievements')
            ->where('character_id', $cid)->where('achievement_id', $achId)->countAllResults();
        $this->assertSame(1, $count);
        $this->assertSame([$achId], $svc->unlockedAchievementIds($cid));
    }

    public function testQualifyingCharLevel(): void
    {
        $achId = $this->seedAchievement('lvl5', 'char_level', 5);
        $c3    = $this->seedChar(3);
        $c5    = $this->seedChar(5);
        $c10   = $this->seedChar(10);

        $ach = (new AchievementModel())->find($achId);
        $ids = $this->svc()->qualifyingCharacterIds($ach);
        sort($ids);
        $this->assertSame([$c5, $c10], $ids); // level>=5
        $this->assertNotContains($c3, $ids);
    }

    public function testQualifyingGoldTotal(): void
    {
        $achId = $this->seedAchievement('rich', 'gold_total', 10000);
        $poor  = $this->seedChar(1, 500);
        $rich  = $this->seedChar(1, 12000);

        $ids = $this->svc()->qualifyingCharacterIds((new AchievementModel())->find($achId));
        $this->assertSame([$rich], $ids);
        $this->assertNotContains($poor, $ids);
    }

    public function testQualifyingHasBase(): void
    {
        $achId   = $this->seedAchievement('home', 'has_base', 1);
        $withBase = $this->seedChar();
        $noBase   = $this->seedChar();
        Database::connect('tests')->table('claimed_cells')->insert(['character_id' => $withBase, 'status' => 'active']);

        $ids = $this->svc()->qualifyingCharacterIds((new AchievementModel())->find($achId));
        $this->assertSame([$withBase], $ids);
        $this->assertNotContains($noBase, $ids);
    }

    public function testQualifyingExcludesAlreadyAwarded(): void
    {
        $svc   = $this->svc();
        $achId = $this->seedAchievement('lvl5', 'char_level', 5);
        $c5    = $this->seedChar(5);
        $c10   = $this->seedChar(10);

        $svc->award($c5, $achId);
        $ids = $svc->qualifyingCharacterIds((new AchievementModel())->find($achId));
        $this->assertSame([$c10], $ids); // c5 уже выдано → исключён
    }

    public function testQualifyingLimitCaps(): void
    {
        $achId = $this->seedAchievement('lvl1', 'char_level', 1);
        $this->seedChar(5);
        $this->seedChar(5);
        $this->seedChar(5);

        $ids = $this->svc()->qualifyingCharacterIds((new AchievementModel())->find($achId), 2);
        $this->assertCount(2, $ids);
    }

    public function testUnknownCriteriaReturnsEmpty(): void
    {
        $achId = $this->seedAchievement('weird', 'nonsense_type', 1);
        $this->seedChar(99);
        $this->assertSame([], $this->svc()->qualifyingCharacterIds((new AchievementModel())->find($achId)));
    }
}
