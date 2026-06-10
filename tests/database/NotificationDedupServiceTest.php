<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Notifications\NotificationDedupService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E6 (ADR-108) Фаза 4 — NotificationDedupService: подавление дублей рутинных
 * уведомлений в окне (killswitch + cache).
 *
 * @internal
 */
final class NotificationDedupServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        NotificationDedupService::reset();
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        NotificationDedupService::reset();
        parent::tearDown();
    }

    private function enableDedup(int $window = 60): void
    {
        $db = Database::connect('tests');
        $db->table('game_settings')->insert(['setting_key' => 'notifications.dedup.enabled', 'category' => 'experimental', 'value_type' => 'bool', 'value_bool' => 1]);
        $db->table('game_settings')->insert(['setting_key' => 'notifications.dedup.window_seconds', 'category' => 'experimental', 'value_type' => 'int', 'value_int' => $window]);
        $this->cleanCache();
        NotificationDedupService::reset();
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

    public function testDormantNeverSuppresses(): void
    {
        // Нет killswitch → dormant → даже повтор не подавляется.
        $this->assertFalse(NotificationDedupService::shouldSuppress(700, 'Крафт готов'));
        $this->assertFalse(NotificationDedupService::shouldSuppress(700, 'Крафт готов'));
    }

    public function testFirstDeliversSecondSuppressed(): void
    {
        $this->enableDedup(60);
        // Первый раз — доставить (false), второй в окне — подавить (true).
        $this->assertFalse(NotificationDedupService::shouldSuppress(700, 'Крафт готов'));
        $this->assertTrue(NotificationDedupService::shouldSuppress(700, 'Крафт готов'));
    }

    public function testDifferentBodyNotSuppressed(): void
    {
        $this->enableDedup(60);
        $this->assertFalse(NotificationDedupService::shouldSuppress(700, 'Крафт готов'));
        $this->assertFalse(NotificationDedupService::shouldSuppress(700, 'Добыча завершена')); // другое тело
        $this->assertFalse(NotificationDedupService::shouldSuppress(701, 'Крафт готов'));       // другой чат
    }

    public function testInvalidChatNeverSuppresses(): void
    {
        $this->enableDedup(60);
        $this->assertFalse(NotificationDedupService::shouldSuppress(0, 'X'));
    }
}
