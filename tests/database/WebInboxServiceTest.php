<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Web\WebInboxService;
use App\Services\Web\WebScreenStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\WebPlay;

/**
 * web-bridge-p1-04 (ADR-189 §5, §6) — входящие на сайте: запись, счётчик, чтение, отметка
 * «прочитано», обрезка при записи (plan A7) и белый список callback по экрану и входящим.
 *
 * Схема — исполнением настоящих миграций.
 *
 * @internal
 */
final class WebInboxServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-12-11-100001_CreateWebPlayTables',
    ];

    private const TABLES = ['telegram_users', 'characters', 'web_play_state', 'web_inbox', 'web_play_intents'];

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function testAppendCountLatestMarkReadAndFind(): void
    {
        $char  = $this->character();
        $other = $this->character();
        $inbox = new WebInboxService($this->conn);

        $inbox->append($char, $this->msg(1000000000, 'Первое'), WebInboxService::SOURCE_VIRTUAL);
        $inbox->append($char, $this->msg(1000000001, 'Второе'), WebInboxService::SOURCE_MIRROR);
        $inbox->append($other, $this->msg(1000000000, 'Чужое'), WebInboxService::SOURCE_VIRTUAL);

        $this->assertSame(2, $inbox->unreadCount($char));
        $latest = $inbox->latest($char, 10);
        $this->assertSame(['Второе', 'Первое'], array_map(static fn (array $i): ?string => $i['msg']['text'], $latest), 'новые первыми');
        $this->assertFalse($latest[0]['read']);
        $this->assertCount(1, $inbox->latest($char, 1));

        $inbox->markAllRead($char);
        $this->assertSame(0, $inbox->unreadCount($char));
        $this->assertTrue($inbox->latest($char, 10)[0]['read']);
        $this->assertSame(1, $inbox->unreadCount($other), 'чужие входящие не тронуты');

        $found = $inbox->findMessage($char, 1000000001);
        $this->assertNotNull($found);
        $this->assertSame('Второе', $found['text']);
        $this->assertNull($inbox->findMessage($char, 42));
    }

    public function testAppendPrunesToInboxKeep(): void
    {
        $char              = $this->character();
        $config            = new WebPlay();
        $config->inboxKeep = 3;
        $inbox             = new WebInboxService($this->conn, $config);

        for ($i = 0; $i < 5; $i++) {
            $inbox->append($char, $this->msg(1000000000 + $i, 'm' . $i), WebInboxService::SOURCE_VIRTUAL);
        }

        $this->assertSame(['m4', 'm3', 'm2'], array_map(static fn (array $i): ?string => $i['msg']['text'], $inbox->latest($char, 10)));
        $this->assertSame(3, $this->conn->table('web_inbox')->where('character_id', $char)->countAllResults());
    }

    public function testUpsertEditKeepsOneRowReplacesPayloadAndMarksUnread(): void
    {
        $char  = $this->character();
        $inbox = new WebInboxService($this->conn);

        $inbox->upsertEdit($char, 1000000005, $this->msg(1000000005, 'шаг 1'), WebInboxService::SOURCE_MIRROR);
        $inbox->markAllRead($char);
        $inbox->upsertEdit($char, 1000000005, $this->msg(1000000005, 'шаг 2'), WebInboxService::SOURCE_MIRROR);
        $inbox->upsertEdit($char, 1000000005, $this->msg(1000000005, 'шаг 3'), WebInboxService::SOURCE_MIRROR);

        $this->assertSame(1, $this->conn->table('web_inbox')->where('character_id', $char)->countAllResults(), 'одна строка на message_id');
        $this->assertSame(1, $inbox->unreadCount($char), 'правка снова непрочитана');
        $found = $inbox->findMessage($char, 1000000005);
        $this->assertNotNull($found);
        $this->assertSame('шаг 3', $found['text']);
    }

    public function testCallbackAllowedOnlyForButtonsOnScreenHistoryOrInbox(): void
    {
        $char  = $this->character();
        $other = $this->character();
        $store = new WebScreenStore($this->conn);
        $inbox = new WebInboxService($this->conn);

        $store->applyCapture($char, $this->capture([$this->msg(1000000000, 'экран', 'on_history')]));
        $store->applyCapture($char, $this->capture([$this->msg(1000000001, 'экран', 'on_screen')]));
        $inbox->append($char, $this->msg(1000000002, 'весть', 'on_inbox'), WebInboxService::SOURCE_VIRTUAL);
        $inbox->append($other, $this->msg(1000000000, 'чужая', 'foreign'), WebInboxService::SOURCE_VIRTUAL);

        $this->assertTrue($store->callbackAllowed($char, 'on_screen'));
        $this->assertTrue($store->callbackAllowed($char, 'on_history'));
        $this->assertTrue($store->callbackAllowed($char, 'on_inbox'));
        $this->assertFalse($store->callbackAllowed($char, 'foreign'), 'кнопка чужого персонажа');
        $this->assertFalse($store->callbackAllowed($char, 'forged_by_browser'));
        $this->assertFalse($store->callbackAllowed($char, ''));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @return array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>}
     */
    private function msg(int $id, string $text, ?string $callback = null): array
    {
        return [
            'message_id'      => $id,
            'text'            => $text,
            'caption'         => null,
            'parse_mode'      => null,
            'photo_url'       => null,
            'inline_keyboard' => $callback === null ? [] : [[['text' => 'Кнопка', 'callback_data' => $callback]]],
        ];
    }

    /**
     * @param list<array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>}> $sent
     * @return array{sent:list<array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>}>, edited:array{}, deleted:list<int>, alert:null, dock:null, dock_removed:false, input:null}
     */
    private function capture(array $sent): array
    {
        return ['sent' => $sent, 'edited' => [], 'deleted' => [], 'alert' => null, 'dock' => null, 'dock_removed' => false, 'input' => null];
    }

    private function character(): int
    {
        $this->conn->table('characters')->insert([
            'name' => 'Входящие', 'level' => 1, 'experience' => 0.01, 'health' => 100, 'tired' => 100,
            'strength' => 0.01, 'agility' => 0.01, 'intellect' => 0.01, 'gold' => 1000,
        ]);

        return (int) $this->conn->insertID();
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
