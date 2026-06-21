<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\Onboarding\OnboardingNudgeHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E4 (ROADMAP-100) — OnboardingNudgeHandler: авто-эскалация «застрял».
 *
 * Реальная отправка замокана через override sendNudge() (анонимный сабкласс) — проверяем
 * side-effects (кто получил подсказку + one-shot маркер), без сети. Гейты — в SQL fetchStuck:
 * killswitch / opt-out / level / last_seen / возраст шага / one-shot.
 *
 * @internal
 */
final class OnboardingNudgeHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'quests', 'quest_steps', 'characters', 'telegram_users', 'action_log'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');
        $db->query('CREATE TABLE quests (id INT PRIMARY KEY, title_en VARCHAR(255) NULL, status VARCHAR(20) NULL)');
        $db->query('CREATE TABLE quest_steps (id INT AUTO_INCREMENT PRIMARY KEY, quest_id INT, character_id INT, is_completed TINYINT DEFAULT 0, created_at DATETIME NULL)');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, telegram_user_id INT NULL, level INT NOT NULL DEFAULT 1, daily_tips_enabled TINYINT NOT NULL DEFAULT 1)');
        $db->query('CREATE TABLE telegram_users (id INT PRIMARY KEY, telegram_id BIGINT NULL, blocked_at DATETIME NULL, last_seen DATETIME NULL)');
        $db->query('CREATE TABLE action_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, chat_id BIGINT NULL, action_name VARCHAR(255), action_status VARCHAR(20), description TEXT NULL, created_at DATETIME NULL)');

        // killswitch ON для happy-path.
        $this->seedSetting('onboarding.auto_escalation.enabled', 'bool', ['value_bool' => 1]);

        // Квест-шаг цепочки (реальный title_en из каталога — иначе nudgeText вернёт null).
        $db->table('quests')->insert(['id' => 100, 'title_en' => 'OnbStepGather', 'status' => 'active']);

        // Игрок A (happy-path): новичок, opt-in, активен 5 мин назад, шаг висит 60 мин.
        // Время сеется DB-часами (NOW() - INTERVAL), а не PHP date() — иначе при расхождении
        // таймзон PHP↔DB на CI окна last_seen/created_at смещаются и выборка пустеет.
        $db->table('telegram_users')->insert(['id' => 10, 'telegram_id' => 1010, 'blocked_at' => null]);
        $this->setMinutesAgo('telegram_users', 'last_seen', 'id = 10', 5);
        $db->table('characters')->insert(['id' => 1, 'telegram_user_id' => 10, 'level' => 2, 'daily_tips_enabled' => 1]);
        $db->table('quest_steps')->insert(['quest_id' => 100, 'character_id' => 1, 'is_completed' => 0]);
        $this->setMinutesAgo('quest_steps', 'created_at', 'character_id = 1', 60);
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

    /** Ставит колонку в «N минут назад» по DB-часам (NOW()-INTERVAL) — без PHP-clock skew. */
    private function setMinutesAgo(string $table, string $col, string $where, int $minutes): void
    {
        Database::connect('tests')->query(
            "UPDATE {$table} SET {$col} = NOW() - INTERVAL {$minutes} MINUTE WHERE {$where}"
        );
    }

    /**
     * @param array<string,int> $valueCols
     */
    private function seedSetting(string $key, string $type, array $valueCols): void
    {
        Database::connect('tests')->table('game_settings')->insert(array_merge(
            ['setting_key' => $key, 'category' => 'world', 'value_type' => $type],
            $valueCols
        ));
    }

    private function makeHandler(): OnboardingNudgeHandler
    {
        return new class extends OnboardingNudgeHandler {
            /** @var list<array{int,string}> */
            public array $sent = [];

            protected function sendNudge(int $tgId, string $text): void
            {
                $this->sent[] = [$tgId, $text];
            }
        };
    }

    private function nudgeCount(int $charId): int
    {
        return Database::connect('tests')->table('action_log')
            ->where('character_id', $charId)
            ->like('action_name', 'OnbNudge_', 'after')
            ->countAllResults();
    }

    public function testHappyPathNudgesStuckActiveNewbie(): void
    {
        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(1, $h->sent);
        $this->assertSame(1010, $h->sent[0][0]);
        $this->assertStringContainsString('Застрял', $h->sent[0][1]);
        $this->assertSame(1, $this->nudgeCount(1)); // one-shot маркер записан

        // created_at проставлен явно (мониторинг тайминга/объёма авто-эскалации).
        $marker = Database::connect('tests')->table('action_log')
            ->where('character_id', 1)->like('action_name', 'OnbNudge_', 'after')
            ->get()->getRowArray();
        $this->assertNotNull($marker['created_at'] ?? null);
        $this->assertNotSame('', (string) ($marker['created_at'] ?? ''));
    }

    public function testOneShotPreventsSecondNudge(): void
    {
        $this->makeHandler()->handle();
        $second = $this->makeHandler();
        $second->handle();

        $this->assertCount(0, $second->sent, 'повторная подсказка по тому же шагу не шлётся');
        $this->assertSame(1, $this->nudgeCount(1));
    }

    public function testKillswitchOffSendsNothing(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'onboarding.auto_escalation.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(0, $h->sent);
    }

    public function testOptOutSendsNothing(): void
    {
        Database::connect('tests')->table('characters')->where('id', 1)->update(['daily_tips_enabled' => 0]);

        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(0, $h->sent);
    }

    public function testOfflineGhostSendsNothing(): void
    {
        // last_seen 2 часа назад — вне окна active_window (30 мин).
        $this->setMinutesAgo('telegram_users', 'last_seen', 'id = 10', 120);

        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(0, $h->sent);
    }

    public function testFreshStepNotYetStuckSendsNothing(): void
    {
        // Шаг назначен 5 мин назад — младше stuck_minutes (45).
        $this->setMinutesAgo('quest_steps', 'created_at', 'character_id = 1', 5);

        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(0, $h->sent);
    }

    public function testVeteranAboveLevelCeilingSendsNothing(): void
    {
        Database::connect('tests')->table('characters')->where('id', 1)->update(['level' => 20]);

        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(0, $h->sent);
    }

    public function testBlockedUserSendsNothing(): void
    {
        Database::connect('tests')->table('telegram_users')->where('id', 10)
            ->update(['blocked_at' => date('Y-m-d H:i:s')]);

        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(0, $h->sent);
    }

    public function testNudgeTextNullForUnknownStep(): void
    {
        $this->assertNull($this->makeHandler()->nudgeText('NotAnOnbStep'));
        $this->assertNotNull($this->makeHandler()->nudgeText('OnbStepMove'));
    }
}
