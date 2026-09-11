<?php

namespace Tests\Database;

use App\Services\PVE\TowerAlertService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * S26b (ADR-031) — TowerAlertService: alert-range detection вышки.
 *
 * Когда игрок перемещается, вышки чужих баз в радиусе пингуют владельцев.
 * Евклидова метрика (как PlayerDetection), exclude own, distinct-owner once,
 * cache-кулдаун, broken (hp=0) исключены. Telegram-доставка подменена стабом.
 *
 * @internal
 */
final class TowerAlertServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private int $towerBuildingId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        foreach (['character_buildings', 'buildings', 'map', 'characters', 'game_settings', 'telegram_users', 'action_log'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('CREATE TABLE buildings (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(150) NULL)');
        $db->query('
            CREATE TABLE character_buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL, building_id INT NULL,
                map_cell_id INT NULL, building_type VARCHAR(32) NULL, hp INT NULL
            )');
        $db->query('
            CREATE TABLE map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL
            )');
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, telegram_user_id INT NULL
            )');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL, value_string TEXT NULL,
                hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL
            )');
        // pvp-detection-clarity-04: нужны только для теста аудита (ownerChatId join + ActionLogModel).
        $db->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL)');
        $db->query('
            CREATE TABLE action_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL, chat_id BIGINT NULL, action_name VARCHAR(255) NULL,
                action_status VARCHAR(32) NULL, description TEXT NULL,
                created_at DATETIME NULL, updated_at DATETIME NULL
            )');

        $db->table('buildings')->insert(['name_en' => 'WatchTower']);
        $this->towerBuildingId = (int) $db->insertID();

        foreach ([
            ['defense.tower.alert_range_cells', 5],
            ['defense.tower.alert_cooldown_sec', 1800],
        ] as [$key, $val]) {
            $db->table('game_settings')->insert([
                'setting_key' => $key, 'category' => 'combat', 'value_type' => 'int',
                'value_int' => $val, 'hard_min' => '0', 'hard_max' => '86400',
            ]);
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['character_buildings', 'buildings', 'map', 'characters', 'game_settings', 'telegram_users', 'action_log'] as $t) {
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

    /** Создаёт чара, клетку (cell=x*1000+y), вышку этого чара на этой клетке. */
    private function placeTower(int $ownerId, int $x, int $y, int $hp = 300): void
    {
        $db   = Database::connect('tests');
        $cell = $x * 1000 + $y;
        $db->table('map')->insert(['cell_number' => $cell, 'coordinate_x' => $x, 'coordinate_y' => $y]);
        $db->table('character_buildings')->insert([
            'character_id' => $ownerId, 'building_id' => $this->towerBuildingId,
            'map_cell_id' => $cell, 'building_type' => 'defensive', 'hp' => $hp,
        ]);
    }

    private function makeMover(int $id, string $name): void
    {
        Database::connect('tests')->table('characters')->insert(['id' => $id, 'name' => $name]);
    }

    private function service(): RecordingTowerAlert
    {
        return new RecordingTowerAlert();
    }

    public function testPingsOwnerWhenInRange(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 7, 5); // owner=7 at (7,5)

        $svc  = $this->service();
        $sent = $svc->notifyTowersNear(1, 5, 5); // mover at (5,5), dist=2 ≤ 5

        $this->assertSame(1, $sent);
        $this->assertCount(1, $svc->pings);
        $this->assertSame(7, $svc->pings[0]['owner']);
        $this->assertSame('Raider', $svc->pings[0]['mover']);
        $this->assertSame(2, $svc->pings[0]['dist']);
    }

    public function testNoPingOutOfRange(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 12, 5); // (12,5), dist from (5,5) = 7 > 5

        $svc  = $this->service();
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    public function testNoPingForOwnTower(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(1, 6, 5); // вышка самого идущего

        $svc = $this->service();
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    public function testBrokenTowerNoPing(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 6, 5, 0); // hp=0

        $svc = $this->service();
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    public function testCooldownSuppressesSecondPing(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 6, 5);

        $svc = $this->service();
        $this->assertSame(1, $svc->notifyTowersNear(1, 5, 5));
        // Повтор по той же паре (owner 7, mover 1) — на кулдауне.
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    public function testDistinctOwnerPingedOncePerPass(): void
    {
        $this->makeMover(1, 'Raider');
        // У владельца 7 две вышки на разных соседних клетках, обе в радиусе.
        $this->placeTower(7, 6, 5);
        $this->placeTower(7, 5, 6);

        $svc  = $this->service();
        $sent = $svc->notifyTowersNear(1, 5, 5);
        $this->assertSame(1, $sent); // один пинг на владельца за проход
    }

    public function testZeroRangeDisablesAlerts(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'defense.tower.alert_range_cells')
            ->update(['value_int' => 0]);

        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 5, 5); // прямо на клетке

        $svc = $this->service();
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    /**
     * pvp-detection-clarity-04: имя со спецсимволами Markdown («*», «_») больше не может
     * дать Telegram 400 — сообщение переведено на HTML, эти символы там не значимы.
     */
    public function testAlertMessagePreservesMarkdownSpecialCharsVerbatim(): void
    {
        $svc = new MessageExposingTowerAlert();
        $msg = $svc->exposeBuildAlertMessage('a*b_c', 3, 10, 10);

        $this->assertStringContainsString('a*b_c', $msg);
    }

    /** HTML-режим требует экранирования собственных спецсимволов ('<','>','&'). */
    public function testAlertMessageEscapesHtmlSpecialChars(): void
    {
        $svc = new MessageExposingTowerAlert();
        $msg = $svc->exposeBuildAlertMessage('a<b>c&d', 3, 10, 10);

        $this->assertStringContainsString('a&lt;b&gt;c&amp;d', $msg);
        $this->assertStringNotContainsString('<b>c', $msg);
    }

    /**
     * pvp-detection-clarity-04: срабатывание пишет `tower_alert_sent` в action_log —
     * иначе измерить постфактум, работала ли вышка, по-прежнему нечем.
     */
    public function testTowerAlertSentWritesAuditLog(): void
    {
        $db = Database::connect('tests');
        $db->table('telegram_users')->insert(['telegram_id' => 555]);
        $tgId = (int) $db->insertID();

        $this->makeMover(7, 'Owner');
        $db->table('characters')->where('id', 7)->update(['telegram_user_id' => $tgId]);
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 6, 5);

        $svc  = new DeliveryStubTowerAlert();
        $sent = $svc->notifyTowersNear(1, 5, 5);

        $this->assertSame(1, $sent);
        $rows = $db->table('action_log')->where('action_name', 'tower_alert_sent')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['character_id']);
        $this->assertSame('Completed', $rows[0]['action_status']);
    }
}

/**
 * Подменяет Telegram-доставку на запись (вся остальная логика — настоящая).
 *
 * @internal
 */
final class RecordingTowerAlert extends TowerAlertService
{
    /** @var list<array{owner:int, mover:string, dist:int, x:int, y:int}> */
    public array $pings = [];

    protected function sendAlert(int $ownerId, string $moverName, int $dist, int $x, int $y): bool
    {
        $this->pings[] = ['owner' => $ownerId, 'mover' => $moverName, 'dist' => $dist, 'x' => $x, 'y' => $y];
        return true;
    }
}

/** Открывает buildAlertMessage() для прямой проверки HTML-экранирования (без Telegram/БД). */
final class MessageExposingTowerAlert extends TowerAlertService
{
    public function exposeBuildAlertMessage(string $moverName, int $dist, int $x, int $y): string
    {
        return $this->buildAlertMessage($moverName, $dist, $x, $y);
    }
}

/**
 * Подменяет только доставку Telegram-сообщения — ownerChatId() и logAlertSent()
 * реальные, чтобы проверить, что успешная отправка пишет action_log.
 *
 * @internal
 */
final class DeliveryStubTowerAlert extends TowerAlertService
{
    protected function deliverAlertMessage(int $chatId, string $moverName, int $dist, int $x, int $y): bool
    {
        return true;
    }
}
