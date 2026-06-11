<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\TitleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E11 (ADR-112) — TitleService: killswitch + idempotent award + auto-equip + source-based qualify
 * (level / achievement) + setActive ownership-gate + rarity.
 *
 * @internal
 */
final class TitleServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'titles', 'character_titles', 'characters', 'achievements', 'character_achievements'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE titles (id INT AUTO_INCREMENT PRIMARY KEY, title_key VARCHAR(64), name VARCHAR(64), description VARCHAR(255) NULL, source_type VARCHAR(16), source_ref VARCHAR(64), icon VARCHAR(16) NULL, sort_order INT DEFAULT 0, enabled TINYINT DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE character_titles (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, title_id INT, unlocked_at DATETIME NULL, UNIQUE KEY uniq_ct (character_id, title_id))');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, level INT DEFAULT 1, active_title_id INT NULL)');
        $db->query('CREATE TABLE achievements (id INT AUTO_INCREMENT PRIMARY KEY, achievement_key VARCHAR(64), enabled TINYINT DEFAULT 1)');
        $db->query('CREATE TABLE character_achievements (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, achievement_id INT, UNIQUE KEY uniq_ca (character_id, achievement_id))');
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

    private function enable(): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => 'titles.enabled', 'value_type' => 'bool', 'value_bool' => 1]);
        $this->cleanCache();
    }

    private function seedTitle(string $key, string $type, string $ref, int $sort = 0, int $enabled = 1): int
    {
        $db = Database::connect('tests');
        $db->table('titles')->insert([
            'title_key' => $key, 'name' => $key, 'description' => 'd', 'source_type' => $type,
            'source_ref' => $ref, 'icon' => '🎖', 'sort_order' => $sort, 'enabled' => $enabled,
        ]);
        return (int) $db->insertID();
    }

    private function seedChar(int $level = 1): int
    {
        $db = Database::connect('tests');
        $db->table('characters')->insert(['level' => $level]);
        return (int) $db->insertID();
    }

    public function testKillswitchDefaultOff(): void
    {
        $this->assertFalse((new TitleService())->enabled());
    }

    public function testKillswitchOn(): void
    {
        $this->enable();
        $this->assertTrue((new TitleService())->enabled());
    }

    public function testDefinitionsOnlyEnabledOrdered(): void
    {
        $this->seedTitle('b', 'level', '5', 20);
        $this->seedTitle('a', 'level', '1', 10);
        $this->seedTitle('off', 'level', '1', 5, 0);
        $keys = array_map(static fn ($d) => $d['title_key'], (new TitleService())->definitions());
        $this->assertSame(['a', 'b'], $keys);
    }

    public function testAwardIdempotentAndAutoEquipsFirst(): void
    {
        $svc = new TitleService();
        $tid = $this->seedTitle('surv', 'level', '1');
        $cid = $this->seedChar();

        $this->assertTrue($svc->award($cid, $tid));
        $this->assertFalse($svc->award($cid, $tid)); // повтор
        $this->assertSame([$tid], $svc->unlockedTitleIds($cid));
        // первый титул авто-экипирован
        $this->assertSame($tid, $svc->activeTitleId($cid));
    }

    public function testAwardSecondDoesNotChangeActive(): void
    {
        $svc = new TitleService();
        $t1  = $this->seedTitle('first', 'level', '1', 10);
        $t2  = $this->seedTitle('second', 'level', '5', 20);
        $cid = $this->seedChar(5);

        $svc->award($cid, $t1);
        $svc->award($cid, $t2);
        $this->assertSame($t1, $svc->activeTitleId($cid)); // активным остаётся первый
    }

    /**
     * E11 Ф2 (фикс-лист среза E12): backfill через cron авто-экипирует ВЫСШИЙ титул
     * (обратный порядок sort_order), а не L1 «Выживший» — ветеран с пачкой титулов
     * за один тик получает активным самый престижный.
     */
    public function testCronBackfillAutoEquipsHighest(): void
    {
        $this->enable();
        $tLow  = $this->seedTitle('survivor', 'level', '1', 10);
        $tMid  = $this->seedTitle('wanderer', 'level', '5', 20);
        $tHigh = $this->seedTitle('veteran', 'level', '10', 30);
        $cid   = $this->seedChar(10); // квалифицируется на все три разом (backfill)

        $cron = new SilentTitleCheckCron(new TitleService());
        $cron->handle();

        $svc = new TitleService();
        $this->assertEqualsCanonicalizing([$tLow, $tMid, $tHigh], $svc->unlockedTitleIds($cid));
        $this->assertSame($tHigh, $svc->activeTitleId($cid)); // экипирован высший, не «Выживший»
    }

    public function testQualifyingLevelSource(): void
    {
        $svc = new TitleService();
        $tid = $this->seedTitle('lvl10', 'level', '10');
        $c5  = $this->seedChar(5);
        $c10 = $this->seedChar(10);
        $c20 = $this->seedChar(20);

        $ids = $svc->qualifyingCharacterIds(['id' => $tid, 'source_type' => 'level', 'source_ref' => '10']);
        sort($ids);
        $this->assertSame([$c10, $c20], $ids);
        $this->assertNotContains($c5, $ids);
    }

    public function testQualifyingAchievementSource(): void
    {
        $svc = new TitleService();
        $db  = Database::connect('tests');
        $tid = $this->seedTitle('boss', 'achievement', 'boss_all');
        $db->table('achievements')->insert(['achievement_key' => 'boss_all', 'enabled' => 1]);
        $achId = (int) $db->insertID();
        $hasIt = $this->seedChar(30);
        $noIt  = $this->seedChar(30);
        $db->table('character_achievements')->insert(['character_id' => $hasIt, 'achievement_id' => $achId]);

        $ids = $svc->qualifyingCharacterIds(['id' => $tid, 'source_type' => 'achievement', 'source_ref' => 'boss_all']);
        $this->assertSame([$hasIt], $ids);
        $this->assertNotContains($noIt, $ids);
    }

    public function testQualifyingExcludesAlreadyAwarded(): void
    {
        $svc = new TitleService();
        $tid = $this->seedTitle('lvl5', 'level', '5');
        $c5  = $this->seedChar(5);
        $c10 = $this->seedChar(10);
        $svc->award($c5, $tid);

        $ids = $svc->qualifyingCharacterIds(['id' => $tid, 'source_type' => 'level', 'source_ref' => '5']);
        $this->assertSame([$c10], $ids);
    }

    public function testSetActiveRequiresOwnership(): void
    {
        $svc = new TitleService();
        $tid = $this->seedTitle('lvl1', 'level', '1');
        $cid = $this->seedChar();

        $this->assertFalse($svc->setActive($cid, $tid)); // не владеет
        $this->assertNull($svc->activeTitleId($cid));

        $svc->award($cid, $tid); // владеет (и авто-экип)
        $other = $this->seedTitle('lvl5', 'level', '5');
        $this->assertFalse($svc->setActive($cid, $other)); // чужой не открыт
        $this->assertTrue($svc->setActive($cid, $tid));
        $this->assertSame($tid, $svc->activeTitleId($cid));
    }

    public function testActiveTitleLabel(): void
    {
        $svc = new TitleService();
        $db  = Database::connect('tests');
        $db->table('titles')->insert(['title_key' => 'k', 'name' => 'Хозяин Севера', 'source_type' => 'level', 'source_ref' => '1', 'icon' => '👑', 'enabled' => 1]);
        $tid = (int) $db->insertID();
        $cid = $this->seedChar();
        $svc->award($cid, $tid);

        $this->assertSame('👑 Хозяин Севера', $svc->activeTitleLabel($cid));
        $this->assertNull($svc->activeTitleLabel($this->seedChar())); // без титула
    }

    public function testRarityPercentMap(): void
    {
        $svc = new TitleService();
        $t1  = $this->seedTitle('common', 'level', '1', 10);
        $t2  = $this->seedTitle('rare', 'level', '50', 20);
        $c1  = $this->seedChar();
        $this->seedChar();
        $this->seedChar();
        $this->seedChar(); // 4 чара
        $svc->award($c1, $t1); // 1/4 = 25%

        $map = $svc->rarityPercentMap();
        $this->assertEqualsWithDelta(25.0, $map[$t1], 0.01);
        $this->assertEqualsWithDelta(0.0, $map[$t2], 0.01);
    }
}

/**
 * Test-double: notify() -> no-op (в тест-схеме нет telegram_users/telegram_user_id;
 * урок feedback_taskhandler_telegram_init_in_tests - не дёргать Telegram на CI).
 *
 * @internal
 */
final class SilentTitleCheckCron extends \App\TaskHandlers\Titles\TitleCheckCron
{
    protected function notify(int $characterId, array $titleList): void
    {
        // no-op
    }
}
