<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Web\WebScreenStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Хранилище с швом между чтением и записью: второй писатель вклинивается ровно туда.
 */
final class InterleavedScreenStore extends WebScreenStore
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $between = null;

    protected function beforeWrite(int $characterId): void
    {
        $cb            = $this->between;
        $this->between = null;
        if ($cb !== null) {
            $cb();
        }
    }
}

/**
 * web-bridge-p1-11 (manual review #6, plan A16) — запись экрана `/play` под guard'ом и фоновая
 * правка на месте (`patchMessage`).
 *
 * Параллель — два соединения к `wildworld_tests`: второй писатель вклинивается между чтением и
 * записью первого. С guard'ом он упирается в блокировку (таймаут ожидания сжат до 1 с) и
 * повторяет после коммита первого — так ведёт себя ждущий клиент. Без guard'а его запись
 * затирается первой.
 *
 * Схема — исполнением настоящих миграций.
 *
 * @internal
 */
final class WebScreenStoreTest extends CIUnitTestCase
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

    private ?BaseConnection $second = null;

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
        if ($this->second !== null) {
            $this->second->close();
            $this->second = null;
        }
        $this->dropTables();
        parent::tearDown();
    }

    public function testInterleavedApplyCapturesKeepBothMessages(): void
    {
        $char  = $this->character();
        $store = new InterleavedScreenStore($this->conn);
        $store->applyCapture($char, $this->capture([$this->msg(1000000000, 'старт')]));

        $other   = new WebScreenStore($this->secondConnection());
        $blocked = false;
        $store->between = function () use ($other, $char, &$blocked): void {
            $blocked = $this->tryOrBlocked(fn () => $other->applyCapture($char, $this->capture([$this->msg(1000000002, 'второй')])));
        };

        $store->applyCapture($char, $this->capture([$this->msg(1000000001, 'первый')]));
        if ($blocked) {
            $other->applyCapture($char, $this->capture([$this->msg(1000000002, 'второй')]));
        }

        $texts = $this->allTexts($char);
        $this->assertContains('первый', $texts, 'захват первого действия сохранился');
        $this->assertContains('второй', $texts, 'захват второго действия не затёрт первым');
        $this->assertContains('старт', $texts);
        $this->assertTrue($blocked, 'второй писатель ждал блокировку, а не писал поверх');
    }

    public function testPatchInterleavedWithApplyCaptureIsNotLost(): void
    {
        $char  = $this->character();
        $store = new InterleavedScreenStore($this->conn);
        $store->applyCapture($char, $this->capture([$this->msg(1000000000, 'поход')]));

        $other   = new WebScreenStore($this->secondConnection());
        $blocked = false;
        $store->between = function () use ($other, $char, &$blocked): void {
            $blocked = $this->tryOrBlocked(fn () => $other->patchMessage($char, 1000000000, $this->msg(1000000000, 'поход: шаг 2')));
        };

        $store->applyCapture($char, $this->capture([$this->msg(1000000001, 'новый экран')]));
        if ($blocked) {
            $this->assertTrue($other->patchMessage($char, 1000000000, $this->msg(1000000000, 'поход: шаг 2')));
        }

        $state = $store->state($char);
        $this->assertSame(['новый экран'], array_column($state['screen'], 'text'));
        $this->assertSame(['поход: шаг 2'], array_column($state['history'][0], 'text'), 'фоновая правка не затёрта');
        $this->assertTrue($blocked, 'patchMessage идёт через тот же guard');
    }

    public function testPatchMessageReplacesInPlaceOnScreenOrHistoryAndNeverPromotes(): void
    {
        $char  = $this->character();
        $store = new WebScreenStore($this->conn);
        $store->applyCapture($char, $this->capture([$this->msg(1000000000, 'А')]));
        $store->applyCapture($char, $this->capture([$this->msg(1000000001, 'Б'), $this->msg(1000000002, 'В')]));

        $this->assertTrue($store->patchMessage($char, 1000000002, $this->msg(1000000002, 'В2')));
        $this->assertTrue($store->patchMessage($char, 1000000000, $this->msg(1000000000, 'А2')));
        $this->assertFalse($store->patchMessage($char, 1000000099, $this->msg(1000000099, 'нет')));
        $this->assertFalse($store->patchMessage($this->character(), 1000000000, $this->msg(1000000000, 'нет строки')));

        $state = $store->state($char);
        $this->assertSame(['Б', 'В2'], array_column($state['screen'], 'text'), 'правка на месте, экран не сменился');
        $this->assertCount(1, $state['history']);
        $this->assertSame(['А2'], array_column($state['history'][0], 'text'), 'история правится на месте, без подъёма');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** True — второй писатель упёрся в блокировку; false — прошёл сразу. */
    private function tryOrBlocked(\Closure $write): bool
    {
        try {
            $write();

            return false;
        } catch (\Throwable $e) {
            $message = $e->getMessage() . ($e->getPrevious()?->getMessage() ?? '');
            $this->assertMatchesRegularExpression('/lock wait timeout|screen write failed/i', $message);

            return true;
        }
    }

    private function secondConnection(): BaseConnection
    {
        $conn = Database::connect(null, false);
        $conn->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $this->second = $conn;
    }

    /** @return list<string|null> */
    private function allTexts(int $char): array
    {
        $state = (new WebScreenStore($this->conn))->state($char);
        $texts = array_column($state['screen'], 'text');
        foreach ($state['history'] as $entry) {
            $texts = array_merge($texts, array_column($entry, 'text'));
        }

        return $texts;
    }

    /**
     * @return array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>}
     */
    private function msg(int $id, string $text): array
    {
        return ['message_id' => $id, 'text' => $text, 'caption' => null, 'parse_mode' => null, 'photo_url' => null, 'inline_keyboard' => []];
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
            'name' => 'Экран', 'level' => 1, 'experience' => 0.01, 'health' => 100, 'tired' => 100,
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
