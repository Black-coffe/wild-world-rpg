<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\Onboarding\ComebackNudgeHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-136 — ComebackNudgeHandler: проактивный comeback-пинг ушедшим новичкам.
 *
 * Реальная отправка замокана через override sendComeback() (анонимный сабкласс) — проверяем
 * side-effects (кто получил пинг + one-shot маркер), без сети. Гейты — в SQL fetchAbsent + guard'ы
 * handle(): killswitch / hour / once-day-claim / окно отсутствия / level / opt-out / blocked / one-shot.
 *
 * @internal
 */
final class ComebackNudgeHandlerTest extends CIUnitTestCase
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

        // killswitch ON + send_hour = текущий серверный час (hour-guard проходит) для happy-path.
        $this->seedSetting('returnability.comeback.enabled', 'bool', ['value_bool' => 1]);
        $this->seedSetting('returnability.comeback.send_hour', 'int', ['value_int' => (int) date('G')]);

        // Квест-шаг цепочки (реальный title_en — для персонализации текста).
        $db->table('quests')->insert(['id' => 100, 'title_en' => 'OnbStepBuild', 'status' => 'active']);

        // Игрок A (happy-path): новичок L3, opt-in, достижим, ушёл 72ч назад (в окне [48,168]),
        // незавершённый онбординг-шаг. Время — DB-часами (NOW()-INTERVAL), без PHP-clock skew.
        $db->table('telegram_users')->insert(['id' => 10, 'telegram_id' => 1010, 'blocked_at' => null]);
        $this->setHoursAgo('telegram_users', 'last_seen', 'id = 10', 72);
        $db->table('characters')->insert(['id' => 1, 'telegram_user_id' => 10, 'level' => 3, 'daily_tips_enabled' => 1]);
        $db->table('quest_steps')->insert(['quest_id' => 100, 'character_id' => 1, 'is_completed' => 0]);
        $this->setHoursAgo('quest_steps', 'created_at', 'character_id = 1', 80);
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
            ->where('setting_key', 'returnability.comeback.last_run')
            ->update(['value_string' => '2000-01-01']);
        $this->cleanCache();
    }

    private function makeHandler(): ComebackNudgeHandler
    {
        return new class extends ComebackNudgeHandler {
            /** @var list<array{string,string}> */
            public array $sent = [];

            protected function sendComeback(string $tgId, string $text): void
            {
                $this->sent[] = [$tgId, $text];
            }
        };
    }

    private function pingCount(int $charId): int
    {
        return Database::connect('tests')->table('action_log')
            ->where('character_id', $charId)
            ->where('action_name', 'ComebackPing')
            ->countAllResults();
    }

    public function testHappyPathPingsAbsentNewbie(): void
    {
        $h = $this->makeHandler();
        $h->handle();

        $this->assertCount(1, $h->sent);
        $this->assertSame('1010', $h->sent[0][0]);
        $this->assertStringContainsString('Возвращайся', $h->sent[0][1]);
        // персонализация по незавершённому шагу OnbStepBuild («Первый камень»).
        $this->assertStringContainsString('Первый камень', $h->sent[0][1]);
        $this->assertSame(1, $this->pingCount(1)); // one-shot маркер записан

        // created_at проставлен явно (мониторинг — урок E4).
        $marker = Database::connect('tests')->table('action_log')
            ->where('character_id', 1)->where('action_name', 'ComebackPing')->get()->getRowArray();
        $this->assertNotNull($marker['created_at'] ?? null);
    }

    public function testOneShotForeverPreventsSecondPing(): void
    {
        $this->makeHandler()->handle();
        $this->resetDailyClaim(); // снимаем дневной claim — проверяем именно per-char one-shot
        $second = $this->makeHandler();
        $second->handle();

        $this->assertCount(0, $second->sent, 'повторный comeback-пинг тому же чару не шлётся');
        $this->assertSame(1, $this->pingCount(1));
    }

    public function testKillswitchOffSendsNothing(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'returnability.comeback.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $h = $this->makeHandler();
        $h->handle();
        $this->assertCount(0, $h->sent);
    }

    public function testWrongHourSendsNothing(): void
    {
        $otherHour = ((int) date('G') + 1) % 24;
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'returnability.comeback.send_hour')->update(['value_int' => $otherHour]);
        $this->cleanCache();

        $h = $this->makeHandler();
        $h->handle();
        $this->assertCount(0, $h->sent);
    }

    public function testOnceDayClaimBlocksSecondRun(): void
    {
        $this->makeHandler()->handle();            // забирает claim + пингует
        $this->seedAnotherAbsentNewbie();          // появился новый кандидат
        $second = $this->makeHandler();
        $second->handle();                          // claim уже взят сегодня → выход без рассылки

        $this->assertCount(0, $second->sent, 'второй прогон в те же сутки не шлёт (once/day-claim)');
    }

    public function testOptOutSendsNothing(): void
    {
        Database::connect('tests')->table('characters')->where('id', 1)->update(['daily_tips_enabled' => 0]);
        $h = $this->makeHandler();
        $h->handle();
        $this->assertCount(0, $h->sent);
    }

    public function testTooRecentNotYetAbsentSendsNothing(): void
    {
        // last_seen 10ч назад — младше min_absent (48ч): ещё «свежий», это зона E4, не comeback.
        $this->setHoursAgo('telegram_users', 'last_seen', 'id = 10', 10);
        $h = $this->makeHandler();
        $h->handle();
        $this->assertCount(0, $h->sent);
    }

    public function testTooOldChurnedSendsNothing(): void
    {
        // last_seen 30 дней назад — старше max_absent (168ч): сгинул, не пингаем.
        $this->setHoursAgo('telegram_users', 'last_seen', 'id = 10', 720);
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

    public function testFormatPersonalizedAndFallback(): void
    {
        $personalized = ComebackNudgeHandler::format('OnbStepMove');
        $this->assertStringContainsString('Первая вылазка', $personalized); // title_ru OnbStepMove
        $this->assertStringContainsString('Возвращайся', $personalized);

        $fallback = ComebackNudgeHandler::format(null);
        $this->assertStringContainsString('Твой лагерь', $fallback);
        $this->assertStringNotContainsString('остановился на шаге', $fallback);

        // неизвестный шаг → fallback (find вернёт null).
        $this->assertStringContainsString('Твой лагерь', ComebackNudgeHandler::format('NotAnOnbStep'));
    }

    /** Второй ушедший новичок (для once/day-claim теста). */
    private function seedAnotherAbsentNewbie(): void
    {
        $db = Database::connect('tests');
        $db->table('telegram_users')->insert(['id' => 11, 'telegram_id' => 1011, 'blocked_at' => null]);
        $this->setHoursAgo('telegram_users', 'last_seen', 'id = 11', 72);
        $db->table('characters')->insert(['id' => 2, 'telegram_user_id' => 11, 'level' => 2, 'daily_tips_enabled' => 1]);
    }
}
