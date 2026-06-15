<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Admin\DashboardAnalyticsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Главный admin-дашборд: DashboardAnalyticsService — KPI, распределения,
 * тренды, лидерборды + drift-safe деградация (нет таблицы → 0/[], не падаем).
 * Сидим минимальные таблицы (паттерн FunnelAnalyticsServiceTest).
 *
 * @internal
 */
final class DashboardAnalyticsServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** Создаём+сидируем эти; battle_logs/pvp_ladder НАМЕРЕННО отсутствуют (тест guard'а). */
    private const CREATE = [
        'telegram_users', 'characters', 'explored_cells', 'claimed_cells',
        'character_factions', 'factions', 'biomes', 'character_buildings',
        'transactions', 'crafted_items', 'crafted_items_log', 'resources',
        'resources_bank', 'tasks', 'character_tasks', 'world_objects',
        'active_events', 'quests', 'quest_steps', 'map',
    ];
    private const ABSENT = ['battle_logs', 'pvp_ladder'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');

        foreach ([...self::CREATE, ...self::ABSENT] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('CREATE TABLE telegram_users (id INT PRIMARY KEY, blocked_at DATETIME NULL)');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(100) NULL,
                    level INT NOT NULL DEFAULT 1, gold BIGINT NULL, biome_id INT NULL,
                    experience DECIMAL(7,2) NOT NULL DEFAULT 0, created_at DATETIME NULL)');
        $db->query('CREATE TABLE explored_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, created_at DATETIME)');
        $db->query("CREATE TABLE claimed_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, status VARCHAR(20) DEFAULT 'active')");
        $db->query('CREATE TABLE character_factions (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, faction_id INT)');
        $db->query('CREATE TABLE factions (id INT PRIMARY KEY, name VARCHAR(100))');
        $db->query('CREATE TABLE biomes (id INT PRIMARY KEY, name VARCHAR(100))');
        $db->query('CREATE TABLE character_buildings (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT,
                    building_type VARCHAR(30), amount INT DEFAULT 1)');
        $db->query("CREATE TABLE transactions (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, type VARCHAR(10),
                    quantity INT, price DECIMAL(10,2), transaction_date DATETIME)");
        $db->query('CREATE TABLE crafted_items (id INT PRIMARY KEY, name_rus VARCHAR(255))');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, crafted_item_id INT, quantity INT, created_at DATETIME)');
        $db->query('CREATE TABLE resources (id INT PRIMARY KEY, name VARCHAR(255))');
        $db->query('CREATE TABLE resources_bank (id INT AUTO_INCREMENT PRIMARY KEY, resource_id INT, current_quantity BIGINT)');
        $db->query('CREATE TABLE tasks (id INT PRIMARY KEY, type VARCHAR(255))');
        $db->query("CREATE TABLE character_tasks (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT, status VARCHAR(20) DEFAULT 'in_work')");
        $db->query("CREATE TABLE world_objects (id INT PRIMARY KEY, status VARCHAR(20) DEFAULT 'active')");
        $db->query("CREATE TABLE active_events (id INT PRIMARY KEY, status VARCHAR(20) DEFAULT 'active')");
        $db->query('CREATE TABLE quests (id INT PRIMARY KEY)');
        $db->query('CREATE TABLE quest_steps (id INT AUTO_INCREMENT PRIMARY KEY, is_completed TINYINT DEFAULT 0)');
        $db->query('CREATE TABLE map (id INT PRIMARY KEY)');

        $daysAgo = static fn (int $d): string => date('Y-m-d H:i:s', strtotime("-{$d} days"));

        $db->table('telegram_users')->insertBatch([
            ['id' => 1, 'blocked_at' => null],
            ['id' => 2, 'blocked_at' => $daysAgo(1)], // заблокирован
            ['id' => 3, 'blocked_at' => null],
            ['id' => 4, 'blocked_at' => null],
        ]);
        // c1 ветеран-богач L10; c2 blocked L1 нищий; c3 свежий L2; c4 свежий L1 средний.
        $db->table('characters')->insertBatch([
            ['id' => 1, 'telegram_user_id' => 1, 'name' => 'Vet',  'level' => 10, 'gold' => 5000000, 'biome_id' => 1, 'experience' => 999, 'created_at' => $daysAgo(60)],
            ['id' => 2, 'telegram_user_id' => 2, 'name' => 'Poor', 'level' => 1,  'gold' => 0,       'biome_id' => null, 'experience' => 1, 'created_at' => $daysAgo(60)],
            ['id' => 3, 'telegram_user_id' => 3, 'name' => 'New2', 'level' => 2,  'gold' => 500,     'biome_id' => 1, 'experience' => 50, 'created_at' => $daysAgo(5)],
            ['id' => 4, 'telegram_user_id' => 4, 'name' => 'Mid',  'level' => 1,  'gold' => 50000,   'biome_id' => 2, 'experience' => 5,  'created_at' => $daysAgo(5)],
        ]);
        $db->table('explored_cells')->insertBatch([
            ['character_id' => 1, 'created_at' => $daysAgo(2)],
            ['character_id' => 3, 'created_at' => $daysAgo(3)],
            ['character_id' => 2, 'created_at' => $daysAgo(45)], // вне 30д
        ]);
        $db->table('claimed_cells')->insertBatch([['character_id' => 1, 'status' => 'active']]);
        $db->table('character_factions')->insertBatch([['character_id' => 1, 'faction_id' => 2]]);
        $db->table('factions')->insertBatch([['id' => 2, 'name' => 'Партизаны']]);
        $db->table('biomes')->insertBatch([['id' => 1, 'name' => 'Лес'], ['id' => 2, 'name' => 'Горы']]);
        $db->table('character_buildings')->insertBatch([
            ['character_id' => 1, 'building_type' => 'farming',  'amount' => 2],
            ['character_id' => 1, 'building_type' => 'military', 'amount' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach ([...self::CREATE, ...self::ABSENT] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    public function testKpi(): void
    {
        $k = (new DashboardAnalyticsService())->kpi();

        $this->assertSame(4, $k['chars_total']);
        $this->assertSame(3, $k['reachable']);                 // 1 заблокирован
        $this->assertSame(5050500.0, $k['gold_total']);        // 5M + 0 + 500 + 50K
        $this->assertSame(2, $k['active_7d']);                 // c1(2д) + c3(3д)
        $this->assertSame(2, $k['active_30d']);
        $this->assertSame(2, $k['new_30d']);                   // c3, c4
        $this->assertSame(1, $k['bases_active']);
        $this->assertSame(3, $k['buildings_total']);           // 2 + 1
        $this->assertSame(10, $k['max_level']);
        $this->assertSame(3.5, $k['avg_level']);
        $this->assertSame(0, $k['battles_total']);             // battle_logs отсутствует → guard
    }

    public function testLevelBuckets(): void
    {
        $map = array_column((new DashboardAnalyticsService())->levelBuckets(), 'chars', 'bucket');

        $this->assertSame(2, $map['L1']);     // c2, c4
        $this->assertSame(1, $map['L2-4']);   // c3
        $this->assertSame(1, $map['L10-24']); // c1
    }

    public function testGoldBuckets(): void
    {
        $map = array_column((new DashboardAnalyticsService())->goldBuckets(), 'chars', 'bucket');

        $this->assertSame(1, $map['0']);
        $this->assertSame(1, $map['1–999']);
        $this->assertSame(1, $map['10K–100K']);
        $this->assertSame(1, $map['1M+']);
    }

    public function testBiomeDistribution(): void
    {
        $map = array_column((new DashboardAnalyticsService())->biomeDistribution(), 'chars', 'name');

        $this->assertSame(2, $map['Лес']);          // c1, c3
        $this->assertSame(1, $map['Горы']);         // c4
        $this->assertSame(1, $map['— без биома —']); // c2
    }

    public function testFactionDistribution(): void
    {
        $f = (new DashboardAnalyticsService())->factionDistribution();

        $this->assertCount(1, $f);
        $this->assertSame('Партизаны', $f[0]['name']);
        $this->assertSame(1, $f[0]['chars']);
    }

    public function testBuildingsByType(): void
    {
        $map = array_column((new DashboardAnalyticsService())->buildingsByType(), 'count', 'type');

        $this->assertSame(2, $map['🌾 Фермерские']);
        $this->assertSame(1, $map['⚔️ Военные']);
    }

    /** Drift-safe: отсутствующие battle_logs/pvp_ladder не валят дашборд. */
    public function testGracefulDegradationOnMissingTables(): void
    {
        $svc = new DashboardAnalyticsService();

        $battles = $svc->battlesSlice(30);
        $this->assertSame(0, $battles['total']);
        $this->assertSame([], $battles['by_type']);
        $this->assertCount(30, $battles['labels']);          // ось всё равно zero-filled
        $this->assertSame([], $svc->pvpLadderTop());
    }

    public function testGrowthTrendZeroFilledLength(): void
    {
        $g = (new DashboardAnalyticsService())->growthTrend(30);

        $this->assertCount(30, $g['labels']);
        $this->assertCount(30, $g['regs']);
        $this->assertCount(30, $g['active']);
        $this->assertSame(2, array_sum($g['regs']));   // c3, c4 за последние 30д
        $this->assertSame(2, array_sum($g['active']));
    }

    public function testTopPlayersOrder(): void
    {
        $top = (new DashboardAnalyticsService())->topPlayers();

        $this->assertSame('Vet', $top[0]['name']);   // L10 первый
        $this->assertSame(10, $top[0]['level']);
    }

    public function testDashboardShape(): void
    {
        $d = (new DashboardAnalyticsService())->dashboard();

        foreach ([
            'kpi', 'growth', 'levels', 'biomes', 'factions', 'gold_buckets', 'battles',
            'pvp_ladder', 'economy', 'top_crafted', 'top_resources', 'buildings', 'tasks',
            'world', 'top_players', 'top_rich', 'trend_days', 'generated_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $d);
        }
    }
}
