<?php

declare(strict_types=1);

namespace Tests\Database;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * web-bridge-p1-01 (ADR-189) — схема игры на сайте: таблицы `web_play_state` / `web_inbox` /
 * `web_play_intents`, флаг `web.play_enabled`, канал firehose `web`, знак колонок с виртуальным id.
 *
 * Схема — исполнением настоящих миграций.
 *
 * @internal
 */
final class WebPlaySchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-03-18-134951_CreateActionLogTable',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-09-28-100000_Adr148CreatePlayerActionLogTable',
        '2026-09-28-130000_Adr148PlayerActionLogAddTaskSource',
        '2026-11-10-100000_Adr148AddUndeliveredStatus',
        '2026-11-19-100000_Adr168PlayerActionLogAddOrigin',
        '2026-12-10-100010_NullableTelegramKeys',
    ];

    private const TABLES = [
        'telegram_users', 'characters', 'action_log', 'game_settings', 'player_action_log',
        'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private BaseConnection $conn;

    private ?string $sqlMode = null;

    private ?Forge $forge = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $forge       = Database::forge();
        $this->forge = $forge instanceof Forge ? $forge : null;
        try {
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file)->up();
            }
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        }
        // STRICT на время теста (прод идёт под STRICT_TRANS_TABLES); соединение общее — вернуть в tearDown.
        $mode           = $this->conn->query('SELECT @@SESSION.sql_mode AS m')->getRowArray();
        $this->sqlMode  = is_array($mode) ? (string) $mode['m'] : '';
        $this->conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    protected function tearDown(): void
    {
        if ($this->sqlMode !== null) {
            $this->conn->query('SET SESSION sql_mode = ?', [$this->sqlMode]);
        }
        $this->dropTables();
        parent::tearDown();
    }

    public function testWebPlayTablesShapeAndDown(): void
    {
        $m = $this->migration('2026-12-11-100001_CreateWebPlayTables');
        $m->up();
        $this->conn->resetDataCache();

        $charId = $this->makeCharacter();
        $this->conn->table('web_play_state')->insert(['character_id' => $charId]);
        $state = $this->conn->table('web_play_state')->where('character_id', $charId)->get()->getRowArray();
        $this->assertIsArray($state);
        $this->assertSame(1000000000, (int) $state['next_message_id']);

        $msg = json_encode(['message_id' => 1000000001, 'text' => 'Привет', 'caption' => null, 'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => []]);
        $this->conn->table('web_inbox')->insert(['character_id' => $charId, 'message_id' => 1000000001, 'source' => 'virtual', 'payload' => $msg]);
        $this->assertFalse($this->tryInsert('web_inbox', ['character_id' => $charId, 'message_id' => 1000000001, 'source' => 'mirror', 'payload' => $msg]), 'UNIQUE(character_id, message_id)');
        $this->assertFalse($this->tryInsert('web_inbox', ['character_id' => $charId, 'message_id' => 1000000002, 'source' => 'other', 'payload' => $msg]), 'source ENUM');

        $this->conn->table('web_play_intents')->insert(['account_id' => 7, 'intent_id' => 'abc']);
        $this->assertFalse($this->tryInsert('web_play_intents', ['account_id' => 7, 'intent_id' => 'abc']), 'UNIQUE(account_id, intent_id)');
        $this->assertTrue($this->tryInsert('web_play_intents', ['account_id' => 8, 'intent_id' => 'abc']));

        // Персонаж удалён — его экран и входящие уходят каскадом.
        $this->conn->table('characters')->where('id', $charId)->delete();
        $this->assertSame(0, $this->conn->table('web_play_state')->countAllResults());
        $this->assertSame(0, $this->conn->table('web_inbox')->countAllResults());

        $m->down();
        $this->conn->resetDataCache();
        foreach (['web_play_state', 'web_inbox', 'web_play_intents'] as $t) {
            $this->assertFalse($this->conn->tableExists($t, false), $t);
        }
    }

    public function testPlayEnabledFlagSeededOffIdempotently(): void
    {
        $m = $this->migration('2026-12-11-100002_WebPlayEnabledSetting');
        $m->up();
        $m->up();

        $rows = $this->conn->table('game_settings')->where('setting_key', 'web.play_enabled')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('bool', $row['value_type']);
        $this->assertSame(0, (int) $row['value_bool']);
        $this->assertSame('false', $row['default_value_text']);
        foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text'] as $col) {
            $this->assertIsString($row[$col]);
            $this->assertNotSame('', trim((string) $row[$col]), $col);
        }

        $m->down();
        $this->assertSame(0, $this->conn->table('game_settings')->where('setting_key', 'web.play_enabled')->countAllResults());
    }

    public function testFirehoseAcceptsWebSourceAndDownFoldsIt(): void
    {
        $this->assertFalse($this->tryInsert('player_action_log', ['source' => 'web']), 'до миграции web не принимается');

        $m = $this->migration('2026-12-11-100003_PlayerActionLogWebSource');
        $m->up();
        $this->assertTrue($this->tryInsert('player_action_log', ['source' => 'web']));

        $m->down();
        $this->assertSame(0, $this->conn->table('player_action_log')->where('source', 'web')->countAllResults());
        $this->assertSame(1, $this->conn->table('player_action_log')->where('source', 'other')->countAllResults());
    }

    public function testSignedColumnsAcceptVirtualIdsAndDownRefusesWhileTheyExist(): void
    {
        $virtual = -(4503599627370496 + 1);
        $charId  = $this->makeCharacter();
        $this->assertSame('bigint unsigned', $this->columnType('action_log', 'chat_id'));
        $this->assertSame('bigint unsigned', $this->columnType('player_action_log', 'telegram_user_id'));

        $m = $this->migration('2026-12-11-100005_SignedTelegramIdColumns');
        $m->up();
        $m->up();

        $this->assertSame('bigint', $this->columnType('action_log', 'chat_id'));
        $this->assertSame('bigint', $this->columnType('player_action_log', 'telegram_user_id'));
        $this->assertTrue($this->tryInsert('action_log', ['character_id' => $charId, 'chat_id' => $virtual, 'action_name' => 'x']));
        $this->assertTrue($this->tryInsert('player_action_log', ['telegram_user_id' => $virtual, 'chat_id' => $virtual]));
        $this->assertTrue($this->tryInsert('action_log', ['character_id' => $charId, 'chat_id' => null, 'action_name' => 'y']), 'NULL-ность сохранена');

        try {
            $m->down();
            $this->fail('down() должен отказать при отрицательных id');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('negative', $e->getMessage());
        }

        $this->conn->table('action_log')->where('chat_id <', 0)->delete();
        $this->conn->table('player_action_log')->where('telegram_user_id <', 0)->delete();
        $m->down();
        $this->assertSame('bigint unsigned', $this->columnType('action_log', 'chat_id'));
        $this->assertSame('bigint unsigned', $this->columnType('player_action_log', 'telegram_user_id'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function makeCharacter(): int
    {
        $this->conn->table('characters')->insert([
            'name' => 'Схема', 'level' => 1, 'experience' => 0.01, 'health' => 100, 'tired' => 100,
            'strength' => 0.01, 'agility' => 0.01, 'intellect' => 0.01, 'gold' => 1000,
        ]);

        return (int) $this->conn->insertID();
    }

    /** @param array<string, int|string|null> $row */
    private function tryInsert(string $table, array $row): bool
    {
        try {
            return (bool) $this->conn->table($table)->insert($row);
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnType(string $table, string $column): string
    {
        $row = $this->conn->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->getRowArray();

        // MariaDB/MySQL 5.7 печатают ширину: bigint(20) unsigned → bigint unsigned.
        return is_array($row) ? (string) preg_replace('/\(\d+\)/', '', (string) $row['COLUMN_TYPE']) : '';
    }

    private function migration(string $file): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m     = new $class($this->forge);
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }
}
