<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\Tips\DailyTipBroadcastHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-038 Фаза C4 — DailyTipBroadcastHandler: killswitch / hour-guard /
 * once-day-guard / фильтр eligible-char (daily_tips_enabled=1) / дедуп+награда.
 *
 * Реальная отправка в Telegram замокана через override sendTip() (анонимный сабкласс) —
 * проверяем side-effects (кто получил совет + лог + награда), без сети.
 *
 * @internal
 */
final class DailyTipBroadcastHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'game_tips', 'character_game_tips', 'characters', 'telegram_users'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');
        $db->query('CREATE TABLE game_tips (id INT AUTO_INCREMENT PRIMARY KEY, title_ru VARCHAR(255) NULL, title_en VARCHAR(255) NULL, tip_type VARCHAR(32) NULL, content TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE character_game_tips (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, game_tip_id INT NOT NULL, viewed_at DATETIME NULL)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, experience DECIMAL(12,2) NOT NULL DEFAULT 0, agility DECIMAL(12,2) NOT NULL DEFAULT 0, intellect DECIMAL(12,2) NOT NULL DEFAULT 0, daily_tips_enabled TINYINT NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL, blocked_at DATETIME NULL)');

        // Happy-path настройки: рассылка включена, час = текущий.
        $this->seedSetting('tips.daily_enabled', 'bool', ['value_bool' => 1]);
        $this->seedSetting('tips.daily_hour', 'int', ['value_int' => (int) date('G')]);

        // Советы.
        for ($i = 1; $i <= 3; $i++) {
            $db->table('game_tips')->insert(['title_ru' => "Совет {$i}", 'title_en' => "Tip{$i}", 'tip_type' => 'общие', 'content' => "Тело {$i}"]);
        }

        // Игроки: A — opt-in (получит), B — opt-out (нет), C — opt-in, но заблокировал бота (нет).
        $db->table('telegram_users')->insert(['id' => 10, 'telegram_id' => 1010]);
        $db->table('telegram_users')->insert(['id' => 20, 'telegram_id' => 2020]);
        $db->table('telegram_users')->insert(['id' => 30, 'telegram_id' => 3030, 'blocked_at' => date('Y-m-d H:i:s')]);
        $db->table('characters')->insert(['id' => 1, 'telegram_user_id' => 10, 'experience' => 1.00, 'agility' => 2.00, 'intellect' => 3.00, 'daily_tips_enabled' => 1]);
        $db->table('characters')->insert(['id' => 2, 'telegram_user_id' => 20, 'experience' => 1.00, 'agility' => 2.00, 'intellect' => 3.00, 'daily_tips_enabled' => 0]);
        $db->table('characters')->insert(['id' => 3, 'telegram_user_id' => 30, 'experience' => 1.00, 'agility' => 2.00, 'intellect' => 3.00, 'daily_tips_enabled' => 1]);
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

    /**
     * @return object{sent: list<array{int,string}>}
     */
    private function makeHandler(): DailyTipBroadcastHandler
    {
        return new class extends DailyTipBroadcastHandler {
            /** @var list<array{int,string}> */
            public array $sent = [];

            protected function sendTip(int $tgId, string $text): void
            {
                $this->sent[] = [$tgId, $text];
            }
        };
    }

    private function viewCount(int $charId): int
    {
        return Database::connect('tests')->table('character_game_tips')->where('character_id', $charId)->countAllResults();
    }

    public function testHappyPathSendsOnlyToOptInChars(): void
    {
        $handler = $this->makeHandler();
        $handler->handle();

        // Только игрок A (opt-in) получил.
        $this->assertCount(1, $handler->sent);
        $this->assertSame(1010, $handler->sent[0][0]);

        // Лог показа создан только у A.
        $this->assertSame(1, $this->viewCount(1));
        $this->assertSame(0, $this->viewCount(2));

        // Рассылка НЕ награждает (d1-relevel-l1-l2): статы A не тронуты.
        $row = Database::connect('tests')->table('characters')->where('id', 1)->get()->getRowArray();
        $this->assertEqualsWithDelta(1.00, (float) $row['experience'], 0.0001);
        $this->assertEqualsWithDelta(2.00, (float) $row['agility'], 0.0001);
        $this->assertEqualsWithDelta(3.00, (float) $row['intellect'], 0.0001);
    }

    public function testBlockedUserIsSkipped(): void
    {
        $handler = $this->makeHandler();
        $handler->handle();

        $this->assertNotContains(3030, array_column($handler->sent, 0), 'заблокировавшему бота не шлём');
        $this->assertSame(0, $this->viewCount(3));
    }

    public function testKillswitchOffSendsNothing(): void
    {
        Database::connect('tests')->table('game_settings')->where('setting_key', 'tips.daily_enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $handler = $this->makeHandler();
        $handler->handle();

        $this->assertCount(0, $handler->sent);
        $this->assertSame(0, $this->viewCount(1));
    }

    public function testWrongHourSendsNothing(): void
    {
        $other = ((int) date('G') + 1) % 24;
        Database::connect('tests')->table('game_settings')->where('setting_key', 'tips.daily_hour')->update(['value_int' => $other]);
        $this->cleanCache();

        $handler = $this->makeHandler();
        $handler->handle();

        $this->assertCount(0, $handler->sent);
    }

    public function testOnceDayGuardPreventsSecondRun(): void
    {
        $first = $this->makeHandler();
        $first->handle();
        $this->assertCount(1, $first->sent);

        // Повторный запуск в тот же день (DB-маркер tips.daily_last_broadcast выставлен) — не шлёт.
        $second = $this->makeHandler();
        $second->handle();
        $this->assertCount(0, $second->sent);
        $this->assertSame(1, $this->viewCount(1)); // у A по-прежнему 1 показ
    }

    public function testDbGuardSurvivesCacheClear(): void
    {
        // Закалка: once/day-guard теперь в БД, а не в cache. Очистка cache между прогонами
        // (имитирует cache:clear/деплой в час рассылки) НЕ должна вызывать повторную рассылку.
        $first = $this->makeHandler();
        $first->handle();
        $this->assertCount(1, $first->sent);

        $this->cleanCache(); // ← раньше это «сбрасывало» guard и вызывало повторный спам

        $second = $this->makeHandler();
        $second->handle();
        $this->assertCount(0, $second->sent, 'DB-маркер должен пережить cache:clear');
        $this->assertSame(1, $this->viewCount(1));
    }

    public function testMarkerRowSetToTodayAfterBroadcast(): void
    {
        $this->makeHandler()->handle();
        $row = Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'tips.daily_last_broadcast')->get()->getRowArray();
        $this->assertNotNull($row, 'маркер-строка создана (self-heal)');
        $this->assertSame(date('Y-m-d'), $row['value_string']);
    }
}
