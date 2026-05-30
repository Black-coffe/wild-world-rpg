<?php

declare(strict_types=1);

namespace Tests\Database;

use App\TaskHandlers\PVP\PvpLadderWeeklyBroadcastHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * A9 (ADR-072 + DB-claim закалка) — PvpLadderWeeklyBroadcastHandler: killswitch (оба флага) /
 * day-guard / hour-guard / once-week-guard через АТОМАРНЫЙ DB-claim (durable, переживает cache:clear).
 *
 * Закалка once/week-guard: cache-маркер → game_settings `pvp.ladder.weekly_last_broadcast`
 * (conditional UPDATE WHERE value_string != week + affectedRows()===1). Урок tips ADR-038/W23
 * (cache:clear/деплой в час рассылки стирал cache-маркер → дубли).
 *
 * Используется РЕАЛЬНЫЙ PvpLadderService (final — не застаблить) против тест-БД: seed game_settings +
 * pvp_ladder + characters. Реальная отправка замокана через sendBroadcast override (без сети).
 *
 * @internal
 */
final class PvpLadderWeeklyBroadcastHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'characters', 'telegram_users', 'pvp_ladder'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE characters (id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(64) NULL)');
        $db->query('CREATE TABLE telegram_users (id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL)');
        $db->query('CREATE TABLE pvp_ladder (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, faction_id INT NOT NULL DEFAULT 0, points INT NOT NULL DEFAULT 0, week_points INT NOT NULL DEFAULT 0, week_wins INT NOT NULL DEFAULT 0)');

        // Happy-path: оба killswitch ON, день/час = текущие.
        $this->seedSetting('pvp.ladder.enabled', 'bool', ['value_bool' => 1]);
        $this->seedSetting('pvp.ladder.broadcast_enabled', 'bool', ['value_bool' => 1]);
        $this->seedSetting('pvp.ladder.broadcast_day', 'int', ['value_int' => (int) date('N')]);
        $this->seedSetting('pvp.ladder.broadcast_hour', 'int', ['value_int' => (int) date('G')]);
        $this->seedSetting('pvp.ladder.broadcast_top_n', 'int', ['value_int' => 5]);

        // Игрок с telegram-связью + строкой ладдера (week_points>0 → попадёт в weeklyTop).
        $db->table('telegram_users')->insert(['id' => 10, 'telegram_id' => 1010]);
        $db->table('characters')->insert(['id' => 1, 'telegram_user_id' => 10, 'name' => 'A']);
        $db->table('pvp_ladder')->insert(['character_id' => 1, 'faction_id' => 0, 'points' => 3, 'week_points' => 3, 'week_wins' => 1]);
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
        cache()->clean();
    }

    /**
     * @param array<string,int> $valueCols
     */
    private function seedSetting(string $key, string $type, array $valueCols): void
    {
        Database::connect('tests')->table('game_settings')->insert(array_merge(
            ['setting_key' => $key, 'category' => 'combat', 'value_type' => $type],
            $valueCols
        ));
    }

    /**
     * @return PvpLadderWeeklyBroadcastHandler&object{sent: list<array{int,string}>}
     */
    private function makeHandler(): PvpLadderWeeklyBroadcastHandler
    {
        return new class extends PvpLadderWeeklyBroadcastHandler {
            /** @var list<array{int,string}> */
            public array $sent = [];

            protected function sendBroadcast(int $tgId, string $text): void
            {
                $this->sent[] = [$tgId, $text];
            }
        };
    }

    private function weekPoints(int $charId): int
    {
        $q   = Database::connect('tests')->table('pvp_ladder')->where('character_id', $charId)->get();
        $row = $q === false ? null : $q->getRowArray();
        return (int) ($row['week_points'] ?? -1);
    }

    public function testHappyPathBroadcastsAndResetsWeek(): void
    {
        $handler = $this->makeHandler();
        $handler->handle();

        $this->assertCount(1, $handler->sent);
        $this->assertSame(1010, $handler->sent[0][0]);
        $this->assertSame(0, $this->weekPoints(1), 'недельный сезон сброшен после рассылки');
    }

    public function testBroadcastKillswitchOffSendsNothing(): void
    {
        // ladder.enabled=ON, broadcast_enabled=OFF → ладдер работает, но рассылки нет.
        Database::connect('tests')->table('game_settings')->where('setting_key', 'pvp.ladder.broadcast_enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $handler = $this->makeHandler();
        $handler->handle();
        $this->assertCount(0, $handler->sent);
        $this->assertSame(3, $this->weekPoints(1), 'без рассылки сезон не сбрасывается');
    }

    public function testWrongHourSendsNothing(): void
    {
        $other = ((int) date('G') + 1) % 24;
        Database::connect('tests')->table('game_settings')->where('setting_key', 'pvp.ladder.broadcast_hour')->update(['value_int' => $other]);
        $this->cleanCache();

        $handler = $this->makeHandler();
        $handler->handle();
        $this->assertCount(0, $handler->sent);
    }

    public function testOnceWeekGuardPreventsSecondRun(): void
    {
        $first = $this->makeHandler();
        $first->handle();
        $this->assertCount(1, $first->sent);

        // Повтор той же недели (DB-маркер выставлен) — не шлёт.
        $second = $this->makeHandler();
        $second->handle();
        $this->assertCount(0, $second->sent);
    }

    public function testDbGuardSurvivesCacheClear(): void
    {
        // Закалка: once/week-guard в БД, не в cache. cache:clear между прогонами
        // (имитирует деплой в час рассылки) НЕ должна вызывать повторную рассылку.
        $first = $this->makeHandler();
        $first->handle();
        $this->assertCount(1, $first->sent);

        $this->cleanCache(); // ← раньше cache-only guard сбрасывался → повторный спам

        $second = $this->makeHandler();
        $second->handle();
        $this->assertCount(0, $second->sent, 'DB-маркер должен пережить cache:clear');
    }

    public function testMarkerRowSetToWeekAfterBroadcast(): void
    {
        $this->makeHandler()->handle();
        $q   = Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'pvp.ladder.weekly_last_broadcast')->get();
        $row = $q === false ? null : $q->getRowArray();
        $this->assertIsArray($row, 'маркер-строка создана (self-heal)');
        $this->assertSame(date('o-W'), $row['value_string']);
    }
}
