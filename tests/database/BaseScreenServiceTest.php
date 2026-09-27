<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Bases\BaseScopeResolver;
use App\Services\Bases\BaseScreenService;
use App\Services\Web\VirtualChat;
use App\Services\Web\WebDelivery;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Telegram;

/**
 * w2-n4-base-01 — ядро экранов базы {@see BaseScreenService}: выбор базы с перепроверкой явного id,
 * модель обзора (стопки, без запроса на каждое здание, без Markdown) и «открыл базу» (визит,
 * онбординг-событие, подсказка — веб-игроку во входящие).
 *
 * @internal
 */
final class BaseScreenServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** Схема из миграций — там, где поля пишет чужой код (персонаж, action_log, веб-входящие). */
    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-18-134951_CreateActionLogTable',
        '2026-12-11-100001_CreateWebPlayTables',
    ];

    /** @var array<string,string> Остальное — минимальный DDL колонок, которые читает ядро. */
    private const DDL = [
        'claimed_cells'       => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, map_cell_id INT NOT NULL, claimed_at DATETIME NULL, last_visited_at DATETIME NULL, last_warned_at DATETIME NULL, status VARCHAR(16) NOT NULL, camp_name VARCHAR(64) NULL, camp_flag VARCHAR(16) NULL, camp_hearth VARCHAR(64) NULL, camp_furniture VARCHAR(64) NULL, camp_pet VARCHAR(64) NULL',
        'buildings'           => 'id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL, building_type VARCHAR(64) NULL',
        'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL, map_cell_id INT NULL, level INT NULL DEFAULT 1, tax INT NULL, amount INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
    ];

    private const DROP = ['biomes', 'map', 'telegram_users', 'characters', 'action_log', 'web_play_state', 'web_inbox', 'web_play_intents'];

    private BaseConnection $conn;
    private int $towerId = 0;
    private int $warehouseId = 0;
    private int $greenhouseId = 0;
    private int $biomeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::DDL as $table => $cols) {
                $this->conn->query("CREATE TABLE `{$table}` ({$cols}) DEFAULT CHARSET=utf8mb4");
            }
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        service('cache')->clean();
        WebDelivery::reset();

        $this->conn->table('buildings')->insert(['name_ru' => 'Вышка связи', 'name_en' => 'CommunicationTower']);
        $this->towerId = (int) $this->conn->insertID();
        $this->conn->table('buildings')->insert(['name_ru' => 'Склад', 'name_en' => 'Warehouse', 'building_type' => 'storage']);
        $this->warehouseId = (int) $this->conn->insertID();
        $this->conn->table('buildings')->insert(['name_ru' => 'Теплица', 'name_en' => 'Greenhouse']);
        $this->greenhouseId = (int) $this->conn->insertID();
        $this->conn->table('biomes')->insert(['name' => 'Лес', 'danger_level' => 1, 'survival_difficulty' => 1]);
        $this->biomeId = (int) $this->conn->insertID();

        foreach ([[100, 10, 10], [200, 20, 20], [300, 11, 11], [400, 15, 15], [900, 900, 900]] as [$cell, $x, $y]) {
            $this->conn->table('map')->insert(['id' => $cell, 'cell_number' => $cell, 'coordinate_x' => $x, 'coordinate_y' => $y, 'biome_id' => $this->biomeId]);
        }
    }

    protected function tearDown(): void
    {
        WebDelivery::reset();
        service('cache')->clean();
        $this->dropTables();
        parent::tearDown();
    }

    // ── resolve(): явный id перепроверяется ──────────────────────────────────

    public function testForeignInactiveAndOutOfRangeBaseIdsAreUnavailable(): void
    {
        $me     = $this->character(900);
        $other  = $this->character(200);
        $mine   = $this->base($me, 100, 1);                 // своя, но вне сигнала (900 далеко)
        $gone   = $this->base($me, 400, null, 'abandoned');  // своя, неактивная
        $theirs = $this->base($other, 200, 1);              // чужая

        $screen = new BaseScreenService();
        foreach ([$mine, $gone, $theirs, 999_999] as $id) {
            $r = $screen->resolve($me, $id);
            $this->assertSame(BaseScreenService::STATE_UNAVAILABLE, $r['state'], "база {$id}");
            $this->assertSame(0, $r['base_id'], 'недоступная база не должна называться в модели');
            $this->assertSame(BaseScopeResolver::TEXT_UNAVAILABLE, $r['text']);
        }
        $this->assertNull($screen->overview($me, $theirs), 'обзор чужой базы — null, а не её данные');
        $this->assertNull($screen->overview($me, $gone), 'обзор неактивной базы — null');
    }

    public function testResolveStatesFollowTheBotRules(): void
    {
        $screen = new BaseScreenService();

        $none = $this->character(100);
        $this->assertSame(BaseScreenService::STATE_NO_BASE, $screen->resolve($none)['state']);

        $onBase = $this->character(100);
        $b      = $this->base($onBase, 100, null);
        $r      = $screen->resolve($onBase);
        $this->assertSame([BaseScreenService::STATE_BASE, $b, true, null], [$r['state'], $r['base_id'], $r['on_base'], $r['coverage']]);

        $far = $this->character(900);
        $bf  = $this->base($far, 200, 1);
        $this->assertSame([BaseScreenService::STATE_FAR, $bf], [$screen->resolve($far)['state'], $screen->resolve($far)['base_id']]);

        $two = $this->character(400);
        $this->base($two, 100, 1);
        $this->base($two, 200, 1);
        $picker = $screen->resolve($two);
        $this->assertSame(BaseScreenService::STATE_PICKER, $picker['state']);
        $this->assertCount(2, $picker['bases']);

        $tower = $this->character(300);
        $bt    = $this->base($tower, 100, 2);
        $r     = $screen->resolve($tower);
        $this->assertSame(BaseScreenService::STATE_BASE, $r['state']);
        $this->assertSame($bt, $r['base_id']);
        $this->assertFalse($r['on_base']);
        $this->assertSame(['covered' => true, 'tower_level' => 2, 'distance' => 1, 'max' => 200], $r['coverage']);
    }

    // ── overview(): стопки, без N+1, без Markdown ────────────────────────────

    public function testOverviewCarriesStackAmountAndBridgeCallbacks(): void
    {
        $c = $this->character(100);
        $b = $this->base($c, 100, 1);
        $this->building($c, $this->warehouseId, 100, 2, 5, 3);

        $o = (new BaseScreenService())->overview($c, $b);
        $this->assertNotNull($o);
        $this->assertSame(10, $o['base']['x']);
        $this->assertSame('Лес', $o['base']['biome']);
        $this->assertTrue($o['base']['on_base']);
        $this->assertNull($o['coverage']);
        $this->assertSame(2, $o['base']['count']);
        $this->assertSame(15, $o['base']['tax_total']);

        $warehouse = $o['buildings'][1];
        $this->assertSame('Warehouse', $warehouse['key']);
        $this->assertSame('Склад', $warehouse['name']);
        $this->assertSame(3, $warehouse['amount'], 'стопка — из `amount`, а не число строк');
        $this->assertSame(2, $warehouse['level']);
        $this->assertSame('storage', $warehouse['type']);
        $this->assertSame("building_{$this->warehouseId}_Warehouse_b{$b}", $warehouse['bridge_callback']);
    }

    public function testOverviewQueryCountDoesNotGrowWithBuildings(): void
    {
        $c1 = $this->character(100);
        $b1 = $this->base($c1, 100, 1);

        $c2 = $this->character(200);
        $b2 = $this->base($c2, 200, 1);
        foreach ([$this->warehouseId, $this->greenhouseId, $this->warehouseId, $this->greenhouseId] as $i => $bid) {
            $this->building($c2, $bid, 200, 1, 1, 1 + $i);
        }

        $this->assertSame(
            $this->countQueries(fn () => (new BaseScreenService())->overview($c1, $b1)),
            $this->countQueries(fn () => (new BaseScreenService())->overview($c2, $b2)),
            'обзор базы с 5 постройками обязан стоить столько же запросов, сколько с одной'
        );
    }

    public function testModelHasNoMarkdownOrTransport(): void
    {
        $c = $this->character(300);
        $b = $this->base($c, 100, 1, 'active', 'Мой *лагерь*');
        $this->building($c, $this->warehouseId, 100, 1, 5, 1);

        $screen = new BaseScreenService();
        $model  = ['resolve' => $screen->resolve($c), 'overview' => $screen->overview($c, $b)];
        $json   = (string) json_encode($model, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('chat_id', $json);
        $this->assertStringNotContainsString('parse_mode', $json);
        $this->assertStringNotContainsString('reply_markup', $json);
        $this->assertStringNotContainsString('Роби', $json, 'тексты экрана бота в модель не попадают');
        $this->assertSame('Мой *лагерь*', $model['overview']['base']['decor']['name'], 'данные игрока — как есть, эскейп — забота рендерера');
    }

    // ── open(): визит и онбординг, как у бота ────────────────────────────────

    public function testOpenTouchesVisitOnlyWhenStandingOnTheBase(): void
    {
        $on  = $this->character(100);
        $bOn = $this->base($on, 100, 1);
        $this->conn->table('claimed_cells')->where('id', $bOn)->update(['last_warned_at' => '2026-01-01 00:00:00']);

        $remote  = $this->character(300);
        $bRemote = $this->base($remote, 100, 2);

        $screen = new BaseScreenService();
        $screen->open($on, $bOn, 1);
        $screen->open($remote, $bRemote, 1);

        $rowOn = $this->conn->table('claimed_cells')->where('id', $bOn)->get()->getRowArray();
        $this->assertNotNull($rowOn['last_visited_at'], 'визит на самой базе продлевает срок');
        $this->assertNull($rowOn['last_warned_at'], 'визит сбрасывает предупреждение');
        $rowRemote = $this->conn->table('claimed_cells')->where('id', $bRemote)->get()->getRowArray();
        $this->assertNull($rowRemote['last_visited_at'], 'дистанционный просмотр под Вышкой — не визит');
    }

    public function testOpenOnForeignBaseDoesNothing(): void
    {
        $me    = $this->character(200, 1);
        $other = $this->character(200);
        $theirs = $this->base($other, 200, null);

        (new BaseScreenService())->open($me, $theirs, 1);

        $this->assertNull($this->conn->table('claimed_cells')->where('id', $theirs)->get()->getRowArray()['last_visited_at']);
        $this->assertSame(0, $this->conn->table('action_log')->countAllResults());
    }

    public function testOpenWritesOnboardingEventAndDeliversWebOnlyHintToInbox(): void
    {
        $this->setting('onboarding_quest_chain_enabled', true);
        $this->setting('web_play_enabled', true);

        $virtualChat = VirtualChat::idForAccount(7);
        $c = $this->character(100, 1, $virtualChat);
        $b = $this->base($c, 100, null); // база без построек → подсказка «первая постройка»

        (new BaseScreenService())->open($c, $b); // без chat_id: чат берётся у персонажа

        $events = array_column($this->conn->table('action_log')->where('character_id', $c)->get()->getResultArray(), 'action_name');
        $this->assertContains('OnbEvent_open_base_screen', $events, 'онбординг-событие «открыл базу» — как у бота');
        $this->assertNotEmpty(array_filter($events, static fn ($n): bool => $n === 'OnbHint_first_build'), 'подсказка первой постройки записана one-shot');

        $inbox = $this->conn->table('web_inbox')->where('character_id', $c)->get()->getResultArray();
        $this->assertCount(1, $inbox, 'подсказка веб-игроку без Telegram — во входящих /play, а не потеряна');
        $this->assertSame('virtual', $inbox[0]['source']);
        $this->assertStringContainsString('Построй первую постройку', (string) $inbox[0]['payload']);
    }

    public function testOpenUnderWebCaptureLandsHintOnTheScreen(): void
    {
        $virtualChat = VirtualChat::idForAccount(8);
        $c = $this->character(100, 1, $virtualChat);
        $b = $this->base($c, 100, null);

        WebDelivery::beginCapture($virtualChat, $c);
        try {
            (new BaseScreenService())->open($c, $b, $virtualChat);
        } finally {
            $capture = WebDelivery::endCapture();
        }

        $this->assertCount(1, $capture['sent']);
        $this->assertStringContainsString('Построй первую постройку', (string) $capture['sent'][0]['text']);
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function character(int $cell, int $level = 99, ?int $telegramId = null): int
    {
        $this->conn->table('telegram_users')->insert(['telegram_id' => $telegramId ?? random_int(700_000_000, 799_999_999)]);
        $tu = (int) $this->conn->insertID();
        $this->conn->table('characters')->insert(['telegram_user_id' => $tu, 'cell_number' => $cell, 'level' => $level]);

        return (int) $this->conn->insertID();
    }

    private function base(int $char, int $cell, ?int $towerLevel, string $status = 'active', ?string $name = null): int
    {
        $this->conn->table('claimed_cells')->insert([
            'character_id' => $char, 'map_cell_id' => $cell, 'claimed_at' => date('Y-m-d H:i:s'),
            'status' => $status, 'camp_name' => $name,
        ]);
        $id = (int) $this->conn->insertID();
        if ($towerLevel !== null) {
            $this->building($char, $this->towerId, $cell, $towerLevel, 10, 1);
        }

        return $id;
    }

    private function building(int $char, int $buildingId, int $cell, int $level, int $tax, int $amount): void
    {
        $this->conn->table('character_buildings')->insert([
            'character_id' => $char, 'building_id' => $buildingId, 'map_cell_id' => $cell,
            'level' => $level, 'tax' => $tax, 'amount' => $amount,
        ]);
    }

    private function setting(string $cacheKey, bool $value): void
    {
        service('cache')->save('game_settings_' . $cacheKey, ['v' => $value, 't' => 'bool'], 60);
    }

    private function countQueries(callable $fn): int
    {
        $n        = 0;
        $listener = static function () use (&$n): void {
            $n++;
        };
        Events::on('DBQuery', $listener);
        try {
            $fn();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $n;
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([...self::DROP, ...array_keys(self::DDL)] as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
