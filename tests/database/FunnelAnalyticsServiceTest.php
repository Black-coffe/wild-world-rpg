<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Admin\FunnelAnalyticsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E1 (ROADMAP-100) — FunnelAnalyticsService: воронка, когорты, аномалии, квест-срез.
 * Сидим минимальные таблицы (паттерн CraftingEconomyServiceTest).
 *
 * @internal
 */
final class FunnelAnalyticsServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'characters', 'telegram_users', 'claimed_cells', 'character_factions',
        'explored_cells', 'quests', 'quest_steps', 'action_log', 'game_settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE telegram_users (id INT PRIMARY KEY, blocked_at DATETIME NULL, created_at DATETIME NULL, acquisition_source VARCHAR(191) NULL)');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, telegram_user_id INT NULL, level INT NOT NULL DEFAULT 1,
                    cell_number INT NULL, created_at DATETIME NULL)');
        $db->query("CREATE TABLE claimed_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, status VARCHAR(20) DEFAULT 'active')");
        $db->query('CREATE TABLE character_factions (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, faction_id INT, joined_at DATETIME NULL)');
        $db->query('CREATE TABLE explored_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, created_at DATETIME)');
        $db->query('CREATE TABLE quests (id INT PRIMARY KEY, objective_type VARCHAR(50) NULL, title_en VARCHAR(255) NULL)');
        $db->query("CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(190),
                    value_bool TINYINT NULL)");
        $db->query('CREATE TABLE quest_steps (id INT AUTO_INCREMENT PRIMARY KEY, quest_id INT, character_id INT,
                    is_completed TINYINT DEFAULT 0, created_at DATETIME)');
        $db->query('CREATE TABLE action_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT,
                    action_name VARCHAR(255), action_status VARCHAR(20), created_at DATETIME NULL)');

        $now       = date('Y-m-d H:i:s');
        $daysAgo   = static fn (int $d): string => date('Y-m-d H:i:s', strtotime("-{$d} days"));

        $db->table('telegram_users')->insertBatch([
            ['id' => 1, 'blocked_at' => null],
            ['id' => 2, 'blocked_at' => $now],
            ['id' => 3, 'blocked_at' => null],
            ['id' => 4, 'blocked_at' => null],
        ]);
        // c1: ветеран L10 с базой/фракцией/движением; c2: blocked, клетка 1, застрявший L1;
        // c3: свежий (5 дн), сделал шаг, вернулся после D1; c4: свежий, ни шагу.
        $db->table('characters')->insertBatch([
            ['id' => 1, 'telegram_user_id' => 1, 'level' => 10, 'cell_number' => 500500, 'created_at' => $daysAgo(60)],
            ['id' => 2, 'telegram_user_id' => 2, 'level' => 1,  'cell_number' => 1,      'created_at' => $daysAgo(60)],
            ['id' => 3, 'telegram_user_id' => 3, 'level' => 2,  'cell_number' => 400922, 'created_at' => $daysAgo(5)],
            ['id' => 4, 'telegram_user_id' => 4, 'level' => 1,  'cell_number' => 401922, 'created_at' => $daysAgo(5)],
        ]);
        $db->table('claimed_cells')->insertBatch([
            ['character_id' => 1, 'status' => 'active'],
        ]);
        $db->table('character_factions')->insertBatch([
            ['character_id' => 1, 'faction_id' => 2, 'joined_at' => '2026-06-09 00:00:00'], // после активации ADR-097
        ]);
        $db->table('explored_cells')->insertBatch([
            ['character_id' => 1, 'created_at' => $daysAgo(59)],
            ['character_id' => 1, 'created_at' => $daysAgo(2)],
            ['character_id' => 2, 'created_at' => $daysAgo(45)],
            ['character_id' => 3, 'created_at' => $daysAgo(5)],
            ['character_id' => 3, 'created_at' => $daysAgo(3)],
        ]);
        $db->table('quests')->insertBatch([
            ['id' => 1, 'objective_type' => 'level_milestone'],
            ['id' => 2, 'objective_type' => 'explore_cells'],
        ]);
        $db->table('quest_steps')->insertBatch([
            ['quest_id' => 1, 'character_id' => 1, 'is_completed' => 1, 'created_at' => $now],
            ['quest_id' => 1, 'character_id' => 3, 'is_completed' => 0, 'created_at' => $now],
            ['quest_id' => 2, 'character_id' => 1, 'is_completed' => 0, 'created_at' => $now],
            ['quest_id' => 2, 'character_id' => 1, 'is_completed' => 0, 'created_at' => '2026-05-01 00:00:00'], // до активации — не в срезе
        ]);

        // E4 — онбординг-цепочка: 3 квеста-шага (Move→Gather→Craft) + прогресс 3 чаров.
        // A(1): Move✓ Gather✓ Craft✗ (застрял на крафте); B(3): Move✓ Gather✗; C(4): Move✗.
        $db->table('quests')->insertBatch([
            ['id' => 100, 'objective_type' => 'explore_cells',   'title_en' => 'OnbStepMove'],
            ['id' => 101, 'objective_type' => 'collect_resource', 'title_en' => 'OnbStepGather'],
            ['id' => 102, 'objective_type' => 'craft_any',        'title_en' => 'OnbStepCraft'],
        ]);
        // created_at ДО активации квестов (2026-06-02) → не засоряют questSlice-срез;
        // onboardingChainSlice датой не фильтрует, поэтому считает их полностью.
        $onbAt = '2026-05-10 00:00:00';
        $db->table('quest_steps')->insertBatch([
            ['quest_id' => 100, 'character_id' => 1, 'is_completed' => 1, 'created_at' => $onbAt],
            ['quest_id' => 100, 'character_id' => 3, 'is_completed' => 1, 'created_at' => $onbAt],
            ['quest_id' => 100, 'character_id' => 4, 'is_completed' => 0, 'created_at' => $onbAt],
            ['quest_id' => 101, 'character_id' => 1, 'is_completed' => 1, 'created_at' => $onbAt],
            ['quest_id' => 101, 'character_id' => 3, 'is_completed' => 0, 'created_at' => $onbAt],
            ['quest_id' => 102, 'character_id' => 1, 'is_completed' => 0, 'created_at' => $onbAt],
        ]);
        $db->table('action_log')->insertBatch([
            ['character_id' => 1, 'action_name' => 'OnbHint_first_base', 'action_status' => 'Completed', 'created_at' => $now],
            ['character_id' => 3, 'action_name' => 'OnbHint_first_base', 'action_status' => 'Completed', 'created_at' => $now],
            ['character_id' => 1, 'action_name' => 'OnbEvent_open_base_screen', 'action_status' => 'Completed', 'created_at' => $now],
        ]);
        $db->table('game_settings')->insertBatch([
            ['setting_key' => 'onboarding.quest_chain.enabled', 'value_bool' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    public function testSummary(): void
    {
        $s = (new FunnelAnalyticsService())->summary();

        $this->assertSame(4, $s['tg_total']);
        $this->assertSame(1, $s['tg_blocked']);
        $this->assertSame(4, $s['chars_total']);
        $this->assertSame(3, $s['chars_reachable']);
        $this->assertSame(2, $s['active_7d']);   // c1 (2 дн) + c3 (3 дн)
        $this->assertSame(2, $s['active_30d']);
    }

    public function testFunnelAllTime(): void
    {
        $f = (new FunnelAnalyticsService())->funnel(null);
        $byKey = array_column($f, null, 'key');

        $this->assertSame(4, $byKey['total']['count']);
        $this->assertSame(3, $byKey['moved']['count']);    // c1,c2,c3; c4 без шага
        $this->assertSame(1, $byKey['base']['count']);
        $this->assertSame(1, $byKey['l10']['count']);
        $this->assertSame(1, $byKey['faction']['count']);
        $this->assertSame(0, $byKey['l25']['count']);
        $this->assertSame(75.0, $byKey['moved']['pct']);
    }

    public function testFunnelRecentCohort(): void
    {
        $f = (new FunnelAnalyticsService())->funnel(30);
        $byKey = array_column($f, null, 'key');

        $this->assertSame(2, $byKey['total']['count']);    // c3, c4
        $this->assertSame(1, $byKey['moved']['count']);    // только c3
        $this->assertSame(0, $byKey['base']['count']);
    }

    public function testLevelBuckets(): void
    {
        $b = (new FunnelAnalyticsService())->levelBuckets();
        $map = array_column($b, 'chars', 'bucket');

        $this->assertSame(2, $map['L1']);
        $this->assertSame(1, $map['L2-4']);
        $this->assertSame(1, $map['L10-24']);
    }

    public function testWeeklyCohortsCountReturnAfterD1(): void
    {
        $w = (new FunnelAnalyticsService())->weeklyCohorts(8);
        // c3/c4 зарегистрированы 5 дн назад → одна когорта; c3 вернулся спустя ≥1 день
        $this->assertNotEmpty($w);
        $last = end($w);
        $this->assertIsArray($last);
        $this->assertSame(2, $last['regs']);
        $this->assertSame(1, $last['moved']);
        $this->assertSame(1, $last['back_d1']);
        $this->assertSame(0, $last['back_d7']);
    }

    public function testAnomalies(): void
    {
        $a = (new FunnelAnalyticsService())->anomalies();

        $this->assertSame(1, $a['cell1_chars']);
        $this->assertSame(1, $a['cell1_blocked']);
        $this->assertSame(1, $a['stuck_l1']);     // c2 (L1, 60 дн, движение 45 дн назад); c4 свежий — не считается
    }

    public function testQuestSliceBucketsSinceActivation(): void
    {
        $q = (new FunnelAnalyticsService())->questSlice();

        $this->assertSame(2, $q['new']['started']);
        $this->assertSame(1, $q['new']['completed']);
        $this->assertSame(2, $q['new']['players']);
        $this->assertSame(1, $q['old']['started']); // запись до активации отфильтрована
        $this->assertSame(0, $q['old']['completed']);
    }

    public function testDashboardShape(): void
    {
        $d = (new FunnelAnalyticsService())->dashboard();

        foreach (['summary', 'funnel_all', 'funnel_30d', 'levels', 'weekly', 'anomalies', 'quests', 'e5', 'onboarding', 'faction', 'acquisition', 'generated_at'] as $key) {
            $this->assertArrayHasKey($key, $d);
        }
    }

    /** Атрибуция интейка: разбивка регистраций по acquisition_source (captured vs organic + топ). */
    public function testAcquisitionSlice(): void
    {
        $db = \Config\Database::connect('tests');
        // 4 tg-юзера: 2 захвачены (src_site_stalker ×1 свежий, src_habr_x ×1 свежий), 2 органика (NULL).
        $db->table('telegram_users')->where('id', 1)->update(['acquisition_source' => 'src_site_stalker', 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('telegram_users')->where('id', 2)->update(['acquisition_source' => 'src_habr_42', 'created_at' => date('Y-m-d H:i:s')]);
        // id 3,4 — без источника (органика).

        $a = (new FunnelAnalyticsService())->acquisitionSlice();

        $this->assertSame(4, $a['total']);
        $this->assertSame(2, $a['captured']);
        $this->assertSame(2, $a['organic']);
        $this->assertCount(2, $a['sources']);
        $srcNames = array_column($a['sources'], 'source');
        $this->assertContains('src_site_stalker', $srcNames);
        $this->assertContains('src_habr_42', $srcNames);
        // оба свежие → попадают в окно 14д.
        foreach ($a['sources'] as $s) {
            $this->assertSame(1, $s['total']);
            $this->assertSame(1, $s['last14d']);
        }
    }

    /** E7 — срез конверсии в фракцию: chosen/eligible/all + since-nudge + by-faction. */
    public function testFactionConversionSlice(): void
    {
        $f = (new FunnelAnalyticsService())->factionConversionSlice();

        $this->assertSame(4, $f['total']);            // c1-c4
        $this->assertSame(1, $f['l10_eligible']);      // только c1 (L10)
        $this->assertSame(1, $f['chosen']);            // c1 в фракции 2
        $this->assertSame(0, $f['unchosen_at_l10']);   // c1 (L10) уже в фракции
        $this->assertSame(100.0, $f['conversion_eligible_pct']); // 1/1
        $this->assertSame(25.0, $f['conversion_all_pct']);        // 1/4
        $this->assertSame(1, $f['chosen_since_nudge']);           // joined_at 2026-06-09 ≥ активации
        $this->assertCount(1, $f['by_faction']);
        $this->assertSame(2, $f['by_faction'][0]['faction_id']);
        $this->assertSame(1, $f['by_faction'][0]['chars']);
    }

    /** E4 — срез онбординг-цепочки: per-step reached/completed, started/graduated, killswitch, hints. */
    public function testOnboardingChainSlice(): void
    {
        $o = (new FunnelAnalyticsService())->onboardingChainSlice();

        $this->assertTrue($o['enabled']);
        $this->assertSame(3, $o['started']);    // Move reached: c1,c3,c4
        $this->assertSame(0, $o['graduated']);  // Level5 ни у кого

        $byEn = array_column($o['steps'], null, 'title_en');
        $this->assertSame(3, $byEn['OnbStepMove']['reached']);
        $this->assertSame(2, $byEn['OnbStepMove']['completed']);   // c1,c3
        $this->assertSame(2, $byEn['OnbStepGather']['reached']);    // c1,c3
        $this->assertSame(1, $byEn['OnbStepGather']['completed']);  // c1
        $this->assertSame(1, $byEn['OnbStepCraft']['reached']);     // c1
        $this->assertSame(0, $byEn['OnbStepCraft']['completed']);
        $this->assertSame(50.0, $byEn['OnbStepGather']['pct']);

        // Порядок шагов — каталожный (Move первым).
        $this->assertSame('OnbStepMove', $o['steps'][0]['title_en']);

        // Слой 1: подсказка показана 2 чарам, событие базы — 1.
        $hints = array_column($o['hints'], 'chars', 'action_name');
        $this->assertSame(2, $hints['OnbHint_first_base']);
        $this->assertSame(1, $hints['OnbEvent_open_base_screen']);
    }

    /**
     * E5 Ф4 — срез до/после. Активация инжектируется относительно NOW (4 дня назад),
     * сиды относительны ей → asserts time-stable (не дрейфуют по календарю).
     */
    public function testE5SliceCohorts(): void
    {
        $db  = Database::connect('tests');
        $act = date('Y-m-d H:i:s', strtotime('-4 days'));
        $at  = static fn (string $rel): string => date('Y-m-d H:i:s', strtotime($rel, strtotime($act)));

        // ДО: c10 (за 2 дня до активации, шаг через 10 мин); c11 (за 1 день до, без шага).
        // ПОСЛЕ: c12 (через 1 час после, набор+удача, шаг через 5 мин, продал);
        //        c13 (через 2 часа, набор, шаг через 90 мин — вне окна 30 мин).
        $db->table('characters')->insertBatch([
            ['id' => 10, 'telegram_user_id' => 1, 'level' => 1, 'cell_number' => 700001, 'created_at' => $at('-2 days')],
            ['id' => 11, 'telegram_user_id' => 1, 'level' => 1, 'cell_number' => 700002, 'created_at' => $at('-1 day')],
            ['id' => 12, 'telegram_user_id' => 1, 'level' => 2, 'cell_number' => 700003, 'created_at' => $at('+1 hour')],
            ['id' => 13, 'telegram_user_id' => 1, 'level' => 1, 'cell_number' => 700004, 'created_at' => $at('+2 hours')],
        ]);
        $db->table('explored_cells')->insertBatch([
            ['character_id' => 10, 'created_at' => $at('-2 days +10 minutes')],
            ['character_id' => 12, 'created_at' => $at('+1 hour +5 minutes')],
            ['character_id' => 12, 'created_at' => $at('+1 hour +20 minutes')], // не первый шаг — медиану не двигает
            ['character_id' => 13, 'created_at' => $at('+2 hours +90 minutes')],
        ]);
        $db->table('action_log')->insertBatch([
            ['character_id' => 12, 'action_name' => 'STARTER_KIT_GRANTED', 'action_status' => 'Completed', 'created_at' => $at('+1 hour')],
            ['character_id' => 12, 'action_name' => 'LUCKY_FIND_FIRST', 'action_status' => 'Completed', 'created_at' => $at('+65 minutes')],
            ['character_id' => 12, 'action_name' => 'SELL_RESOURCE', 'action_status' => 'Completed', 'created_at' => $at('+70 minutes')],
            ['character_id' => 13, 'action_name' => 'STARTER_KIT_GRANTED', 'action_status' => 'Completed', 'created_at' => $at('+2 hours')],
        ]);

        $e5 = (new FunnelAnalyticsService($act))->e5Slice();
        $b  = $e5['before'];
        $a  = $e5['after'];

        // ДО: c10, c11 + c3, c4 из общего сида (создані 5 дн назад = внутри окна 14 дн до активации-4дн... нет:
        // окно [акт-14д, акт) = [-18д, -4д); c3/c4 (созданы -5 дн) попадают в него. Итого regs=4.
        $this->assertSame(4, $b['regs']);
        $this->assertSame(2, $b['moved']);        // c10 + c3 (его первый шаг в день создания)
        $this->assertSame(0, $b['kit']);          // механики не существовали
        $this->assertSame(0, $b['lucky']);

        // ПОСЛЕ: ровно c12, c13.
        $this->assertSame(2, $a['regs']);
        $this->assertSame(2, $a['moved']);
        $this->assertSame(100.0, $a['moved_pct']);
        $this->assertSame(1, $a['moved_30m']);    // только c12 (5 мин); c13 — 90 мин
        $this->assertSame(50.0, $a['moved_30m_pct']);
        $this->assertSame(47.5, $a['median_first_move_min']); // медиана из [5, 90]
        $this->assertSame(2, $a['kit']);
        $this->assertSame(1, $a['lucky']);
        $this->assertSame(1, $a['sellers']);
        $this->assertSame(1, $a['l2plus']);
    }

    public function testMedianEdgeCases(): void
    {
        $this->assertNull(FunnelAnalyticsService::median([]));
        $this->assertSame(7.0, FunnelAnalyticsService::median([7.0]));
        $this->assertSame(10.0, FunnelAnalyticsService::median([30.0, 5.0, 10.0]));
        $this->assertSame(7.5, FunnelAnalyticsService::median([10.0, 5.0]));
    }
}
