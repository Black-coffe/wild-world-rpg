<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\Onboarding\Day2NudgeHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * S6 (ADR-140) — Day2NudgeHandler: проактивный day-2 пинг новичкам в окне D1→D2.
 *
 * Реальная отправка замокана через override sendNudge() (анонимный сабкласс). Гейты — в SQL
 * fetchAbsent + guard'ы handle(): killswitch / hour / once-day-claim / окно [16..44] / level /
 * opt-out / blocked / one-shot. Окно НИЖЕ 48ч-пола comeback'а (72ч → too-old для day-2).
 *
 * @internal
 */
final class Day2NudgeHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'characters', 'telegram_users', 'action_log'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, telegram_user_id INT NULL, level INT NOT NULL DEFAULT 1, daily_tips_enabled TINYINT NOT NULL DEFAULT 1)');
        $db->query('CREATE TABLE telegram_users (id INT PRIMARY KEY, telegram_id BIGINT NULL, blocked_at DATETIME NULL, last_seen DATETIME NULL)');
        $db->query('CREATE TABLE action_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, chat_id BIGINT NULL, action_name VARCHAR(255), action_status VARCHAR(20), description TEXT NULL, created_at DATETIME NULL)');

        // killswitch ON + send_hour = текущий серверный час (hour-guard проходит) для happy-path.
        $this->seedSetting('returnability.day2.enabled', 'bool', ['value_bool' => 1]);
        $this->seedSetting('returnability.day2.send_hour', 'int', ['value_int' => (int) date('G')]);
        // live-поводы (для текста): серия + дейлики включены.
        $this->seedSetting('returnability.streak.enabled', 'bool', ['value_bool' => 1]);
        $this->seedSetting('quests.daily.enabled', 'bool', ['value_bool' => 1]);

        // Игрок A (happy-path): новичок L3, opt-in, достижим, ушёл 24ч назад (в окне [16,44]).
        // Время — DB-часами (NOW()-INTERVAL), без PHP-clock skew.
        $db->table('telegram_users')->insert(['id' => 10, 'telegram_id' => 1010, 'blocked_at' => null]);
        $this->setHoursAgo('telegram_users', 'last_seen', 'id = 10', 24);
        $db->table('characters')->insert(['id' => 1, 'telegram_user_id' => 10, 'level' => 3, 'daily_tips_enabled' => 1]);
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

    /** Ставит колонку в «N часов назад» по DB-часам (NOW()-INTERVAL) — без PHP-clock skew. */
    private function setHoursAgo(string $table, string $col, string $where, int $hours): void
    {
        Database::connect('tests')->query(
            "UPDATE {$table} SET {$col} = NOW() - INTERVAL {$hours} HOUR WHERE {$where}"
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

    /** Сбрасывает once/day-claim, чтобы повторный handle() в одном тесте снова прошёл guard. */
    private function resetDailyClaim(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'returnability.day2.last_run')
            ->update(['value_string' => '2000-01-01']);
        $this->cleanCache();
    }

    private function makeHandler(): Day2NudgeHandler
    {
        return new class extends Day2NudgeHandler {
            /** @var list<array{string,string}> */
            public array $sent = [];

            protected function sendNudge(string $tgId, string $text): void
            {
                $this->sent[] = [$tgId, $text];
            }
        };
    }

    private function pingCount(int $charId): int
    {
        return Database::connect('tests')->table('action_log')
            ->where('character_id', $charId)
            ->where('action_name', 'Day2Ping')
            ->countAllResults();
    }

    public function testHappyPathPingsDay2Newbie(): void
    {
        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(1, $h->sent);
        $this->assertSame('1010', $h->sent[0][0]);
        $this->assertStringContainsString('День второй', $h->sent[0][1]);
        $this->assertStringContainsString('Серия входов', $h->sent[0][1]); // live-повод назван
        $this->assertStringContainsString('Задания дня', $h->sent[0][1]);
        $this->assertSame(1, $this->pingCount(1)); // one-shot маркер записан

        $marker = Database::connect('tests')->table('action_log')
            ->where('character_id', 1)->where('action_name', 'Day2Ping')->get()->getRowArray();
        $this->assertNotNull($marker['created_at'] ?? null);
    }

    public function testOneShotForeverPreventsSecondPing(): void
    {
        $this->makeHandler()->handle();
        $this->resetDailyClaim();
        $second = $this->makeHandler();
        $second->handle();

        $this->assertCount(0, $second->sent, 'повторный day-2 пинг тому же чару не шлётся');
        $this->assertSame(1, $this->pingCount(1));
    }

    public function testKillswitchOffSendsNothing(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'returnability.day2.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $h = $this->makeHandler();
        $h->handle();
        $this->assertCount(0, $h->sent);
    }

    public function testWrongHourSendsNothing(): void
    {
        $otherHour = ((int) date('G') + 1) % 24;
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'returnability.day2.send_hour')->update(['value_int' => $otherHour]);
        $this->cleanCache();

        $h = $this->makeHandler();
        $h->handle();
        $this->assertCount(0, $h->sent);
    }

    public function testOnceDayClaimBlocksSecondRun(): void
    {
        $this->makeHandler()->handle();
        $this->seedAnotherDay2Newbie();
        $second = $this->makeHandler();
        $second->handle();

        $this->assertCount(0, $second->sent, 'второй прогон в те же сутки не шлёт (once/day-claim)');
    }

    public function testOptOutSendsNothing(): void
    {
        Database::connect('tests')->table('characters')->where('id', 1)->update(['daily_tips_enabled' => 0]);
        $h = $this->makeHandler();
        $h->handle();
        $this->assertCount(0, $h->sent);
    }

    public function testTooRecentBelowWindowSendsNothing(): void
    {
        // last_seen 10ч назад — младше min_absent (16ч): ещё «свежий», это зона E4, не day-2.
        $this->setHoursAgo('telegram_users', 'last_seen', 'id = 10', 10);
        $h = $this->makeHandler();
        $h->handle();
        $this->assertCount(0, $h->sent);
    }

    public function testTooOldAboveWindowSendsNothing(): void
    {
        // last_seen 72ч назад — старше max_absent (44ч): это уже зона comeback (48ч+), не day-2.
        $this->setHoursAgo('telegram_users', 'last_seen', 'id = 10', 72);
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

    public function testFormatNamesOnlyLiveReasons(): void
    {
        $both = Day2NudgeHandler::format(true, true);
        $this->assertStringContainsString('День второй', $both);
        $this->assertStringContainsString('Серия входов', $both);
        $this->assertStringContainsString('Задания дня', $both);

        $streakOnly = Day2NudgeHandler::format(true, false);
        $this->assertStringContainsString('Серия входов', $streakOnly);
        $this->assertStringNotContainsString('Задания дня', $streakOnly);

        $none = Day2NudgeHandler::format(false, false);
        $this->assertStringContainsString('Твой лагерь', $none); // fallback
        $this->assertStringNotContainsString('Серия входов', $none);

        // markdown-баланс (парные *) во всех ветках
        foreach ([$both, $streakOnly, $none, Day2NudgeHandler::format(false, true)] as $txt) {
            $this->assertSame(0, substr_count($txt, '*') % 2, 'непарные * в day-2 тексте');
        }
    }

    /** Второй ушедший в день-2 новичок (для once/day-claim теста). */
    private function seedAnotherDay2Newbie(): void
    {
        $db = Database::connect('tests');
        $db->table('telegram_users')->insert(['id' => 11, 'telegram_id' => 1011, 'blocked_at' => null]);
        $this->setHoursAgo('telegram_users', 'last_seen', 'id = 11', 24);
        $db->table('characters')->insert(['id' => 2, 'telegram_user_id' => 11, 'level' => 2, 'daily_tips_enabled' => 1]);
    }
}
