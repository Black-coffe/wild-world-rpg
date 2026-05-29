<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Notifications\SilentNotificationPolicy;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W28 (ADR-083) — SilentNotificationPolicy: killswitch + per-char notify_sound override.
 *
 * Проверяет двойной гейт «тихого порога»: рутинное уведомление приходит тихо
 * (disable_notification) только если killswitch ON И игрок не вернул себе звук.
 *
 * @internal
 */
final class SilentNotificationPolicyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        SilentNotificationPolicy::reset();
        $db = Database::connect('tests');
        foreach (['game_settings', 'characters', 'telegram_users'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');
        $db->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NOT NULL)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NOT NULL, name VARCHAR(100) NULL, notify_sound TINYINT NOT NULL DEFAULT 0)');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['game_settings', 'characters', 'telegram_users'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        SilentNotificationPolicy::reset();
        parent::tearDown();
    }

    private function enableKillswitch(): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => 'notifications.silent_threshold.enabled',
            'category'    => 'experimental',
            'value_type'  => 'bool',
            'value_bool'  => 1,
        ]);
        $this->cleanCache();
        SilentNotificationPolicy::reset();
    }

    /** chatId 700 → telegram_users.id → characters.notify_sound */
    private function seedPlayer(int $telegramId, int $notifySound): void
    {
        $db = Database::connect('tests');
        $db->table('telegram_users')->insert(['id' => 1, 'telegram_id' => $telegramId]);
        $db->table('characters')->insert(['id' => 1, 'telegram_user_id' => 1, 'name' => 'Hero', 'notify_sound' => $notifySound]);
        SilentNotificationPolicy::reset();
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

    // ── killswitch dormant (default prod) ──────────────────────────────────

    public function testDormantWhenKillswitchAbsent(): void
    {
        // Нет строки game_settings → enabled() false → всегда со звуком.
        $this->assertFalse(SilentNotificationPolicy::enabled());
        $this->seedPlayer(700, 0);
        $this->assertFalse(SilentNotificationPolicy::routineSilentFor(700));
    }

    public function testDormantEvenForDefaultCharWhenOff(): void
    {
        $this->seedPlayer(700, 0);
        // killswitch не включён → даже notify_sound=0 не делает тихо.
        $this->assertFalse(SilentNotificationPolicy::routineSilentFor(700));
    }

    // ── killswitch ON ──────────────────────────────────────────────────────

    public function testSilentWhenEnabledAndCharDefault(): void
    {
        $this->enableKillswitch();
        $this->seedPlayer(700, 0);
        $this->assertTrue(SilentNotificationPolicy::enabled());
        // notify_sound=0 (default) + killswitch ON → тихо.
        $this->assertTrue(SilentNotificationPolicy::routineSilentFor(700));
    }

    public function testNotSilentWhenCharWantsSound(): void
    {
        $this->enableKillswitch();
        $this->seedPlayer(700, 1);
        // notify_sound=1 → игрок вернул звук → НЕ тихо, даже при killswitch ON.
        $this->assertFalse(SilentNotificationPolicy::routineSilentFor(700));
    }

    public function testInvalidChatIdNeverSilent(): void
    {
        $this->enableKillswitch();
        $this->assertFalse(SilentNotificationPolicy::routineSilentFor(0));
        $this->assertFalse(SilentNotificationPolicy::routineSilentFor(-5));
    }

    public function testUnknownChatFollowsDefaultSilentWhenEnabled(): void
    {
        $this->enableKillswitch();
        // Нет telegram_user/character для этого chatId → дефолт (notify_sound 0) → тихо.
        $this->assertTrue(SilentNotificationPolicy::routineSilentFor(999999));
    }
}
