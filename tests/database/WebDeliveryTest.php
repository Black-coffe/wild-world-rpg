<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Telegram\Request;
use App\Services\Web\DeliveryContext;
use App\Services\Web\VirtualChat;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebScreenStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\WebPlay;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Шпион настоящей отправки: доказывает, дошёл ли вызов до `parent::send()` (Longman → Telegram).
 */
final class WebDeliverySpyRequest extends Request
{
    /** @var list<array{0:string, 1:array<string,mixed>}> */
    public static array $calls = [];

    public static ?ServerResponse $last = null;

    protected static function transport(string $action, array $data): ServerResponse
    {
        self::$calls[] = [$action, $data];

        return self::$last = new ServerResponse(['ok' => true, 'result' => ['message_id' => 77, 'chat' => ['id' => 1], 'date' => 1]], '');
    }

    protected static function transportSendMessage(array $data, ?array &$extras = []): ServerResponse
    {
        return self::transport('sendMessage', $data);
    }
}

/**
 * web-bridge-p1-04 (ADR-189 §4a, §5, §9) — слой (а) `Request::send()` под PHPUnit:
 * виртуальный чат → входящие без Telegram (Ask 2), захват экрана `/play` (Ask 3), копия
 * привязанному игроку (Ask 4), прежнее поведение для всех остальных (Ask 5).
 *
 * Схема — исполнением настоящих миграций.
 *
 * @internal
 */
final class WebDeliveryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-12-10-100001_CreateAccountsTables',
        '2026-12-10-100002_LinkCharactersToAccounts',
        '2026-12-10-100010_NullableTelegramKeys',
        '2026-12-11-100001_CreateWebPlayTables',
    ];

    private const TABLES = [
        'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private const FLAG_CACHE = 'game_settings_web_play_enabled';

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
        WebDelivery::reset();
        DeliveryContext::reset();
        WebDeliverySpyRequest::$calls = [];
        $this->setFlag(true);
    }

    protected function tearDown(): void
    {
        WebDelivery::reset();
        DeliveryContext::reset();
        service('cache')->delete(self::FLAG_CACHE);
        $this->dropTables();
        parent::tearDown();
    }

    // ── Ask 2: виртуальный чат ───────────────────────────────────────────

    public function testVirtualSendGoesToInboxOnlyAndNeverToTelegram(): void
    {
        [$charId, $chat] = $this->webOnly();

        $r = WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Крафт готов', 'parse_mode' => 'Markdown']);
        $this->assertTrue($r->isOk());
        $this->assertSame([], WebDeliverySpyRequest::$calls, 'sendMessage на виртуальный id не дошёл до parent::send()');
        $rows = $this->inbox($charId);
        $this->assertCount(1, $rows);
        $this->assertSame('virtual', $rows[0]['source']);
        $payload = json_decode((string) $rows[0]['payload'], true);
        $this->assertIsArray($payload);
        $this->assertSame('Крафт готов', $payload['text']);
        $result = $r->getResult();
        $this->assertInstanceOf(Message::class, $result);
        $this->assertSame((int) $rows[0]['message_id'], $result->getMessageId());

        $photo = fopen(FCPATH . 'apple-touch-icon.png', 'rb');
        $this->assertIsResource($photo);
        $r2 = WebDeliverySpyRequest::sendPhoto(['chat_id' => $chat, 'photo' => $photo, 'caption' => 'Нападение на базу']);
        $this->assertTrue($r2->isOk());
        $this->assertSame([], WebDeliverySpyRequest::$calls, 'sendPhoto на виртуальный id не дошёл до parent::send()');
        $this->assertCount(2, $this->inbox($charId));
    }

    public function testVirtualEditAndDeleteWriteNothingAndReturnOk(): void
    {
        [$charId, $chat] = $this->webOnly();

        $this->assertTrue(WebDeliverySpyRequest::editMessageText(['chat_id' => $chat, 'message_id' => 5, 'text' => 'x'])->isOk());
        $this->assertTrue(WebDeliverySpyRequest::deleteMessage(['chat_id' => $chat, 'message_id' => 5])->isOk());
        $this->assertSame([], WebDeliverySpyRequest::$calls);
        $this->assertCount(0, $this->inbox($charId));
    }

    public function testVirtualWithFlagOffWritesNothingAndStillSendsNothing(): void
    {
        [$charId, $chat] = $this->webOnly();
        $this->setFlag(false);

        $this->assertTrue(WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'x'])->isOk());
        $this->assertTrue(WebDeliverySpyRequest::send('sendPhoto', ['chat_id' => (string) $chat, 'photo' => 'file-id', 'caption' => 'y'])->isOk());
        $this->assertSame([], WebDeliverySpyRequest::$calls);
        $this->assertCount(0, $this->inbox($charId));
    }

    // ── Ask 3: захват экрана ─────────────────────────────────────────────

    public function testSendThenEditOfSameIdYieldsOneScreenWithEditedText(): void
    {
        [$charId, $chat] = $this->webOnly();
        WebDelivery::beginCapture($chat, $charId);

        $sent = WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Было', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => 'A', 'callback_data' => 'a']]]])]);
        $id   = $this->messageId($sent);
        $edit = WebDeliverySpyRequest::editMessageText(['chat_id' => $chat, 'message_id' => $id, 'text' => 'Стало']);
        $this->assertTrue($edit->isOk());

        $capture = WebDelivery::endCapture();
        $this->assertSame([], WebDeliverySpyRequest::$calls, 'захват актора не уходит в Telegram');
        $this->assertCount(0, $this->inbox($charId), 'ответ актору не копируется во входящие');
        (new WebScreenStore($this->conn))->applyCapture($charId, $capture);

        $state = (new WebScreenStore($this->conn))->state($charId);
        $this->assertCount(1, $state['screen']);
        $this->assertSame('Стало', $state['screen'][0]['text']);
        $this->assertSame([], $state['screen'][0]['inline_keyboard'], 'editMessageText без клавиатуры снимает её, как в Telegram');
        $this->assertSame([], $state['history']);
        $this->assertSame(['sent' => [], 'edited' => [], 'deleted' => [], 'alert' => null, 'dock' => null, 'dock_removed' => false, 'input' => null], WebDelivery::endCapture(), 'endCapture идемпотентен');
    }

    public function testTwoSendsMakeOneScreenAndOldScreensMoveToCappedHistory(): void
    {
        [$charId, $chat] = $this->webOnly();
        $config              = new WebPlay();
        $config->historySize = 2;
        $store               = new WebScreenStore($this->conn, $config);
        WebDelivery::useServices($store);

        foreach (['один', 'два', 'три'] as $text) {
            WebDelivery::beginCapture($chat, $charId);
            WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => $text]);
            $store->applyCapture($charId, WebDelivery::endCapture());
        }
        WebDelivery::beginCapture($chat, $charId);
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'четыре-а']);
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'четыре-б']);
        $store->applyCapture($charId, WebDelivery::endCapture());

        $state = $store->state($charId);
        $this->assertSame(['четыре-а', 'четыре-б'], array_column($state['screen'], 'text'));
        $this->assertCount(2, $state['history'], 'история обрезана до historySize');
        $this->assertSame('три', $state['history'][0][0]['text'], 'новые первыми');
        $this->assertSame('два', $state['history'][1][0]['text']);
    }

    public function testEditOfHistoryMessageBecomesCurrentScreen(): void
    {
        [$charId, $chat] = $this->webOnly();
        $store = new WebScreenStore($this->conn);
        WebDelivery::useServices($store);

        WebDelivery::beginCapture($chat, $charId);
        $old = $this->messageId(WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'карта']));
        $store->applyCapture($charId, WebDelivery::endCapture());
        WebDelivery::beginCapture($chat, $charId);
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'другой экран']);
        $store->applyCapture($charId, WebDelivery::endCapture());

        WebDelivery::beginCapture($chat, $charId);
        $markup = json_encode(['inline_keyboard' => [[['text' => 'Идти', 'callback_data' => 'go_n']]]]);
        $this->assertTrue(WebDeliverySpyRequest::editMessageText(['chat_id' => $chat, 'message_id' => $old, 'text' => 'карта 2', 'reply_markup' => $markup])->isOk());
        $store->applyCapture($charId, WebDelivery::endCapture());

        $state = $store->state($charId);
        $this->assertSame(['карта 2'], array_column($state['screen'], 'text'), 'правка из истории — текущий экран');
        $this->assertSame($old, $state['screen'][0]['message_id']);
        $this->assertCount(1, $state['history'], 'опустевшая запись истории удалена');
        $this->assertSame(['другой экран'], array_column($state['history'][0], 'text'), 'прошлый экран — новейшая запись истории');
        $this->assertNoDuplicateIds($state);
        $this->assertTrue($store->callbackAllowed($charId, 'go_n'), 'кнопка поднятого сообщения разрешена');
        $found = $store->findMessage($charId, $old);
        $this->assertNotNull($found);
        $this->assertSame('карта 2', $found['text'], 'findMessage находит по исходному id');
    }

    public function testEditOfCurrentScreenMessageReplacesInPlaceAndKeepsHistory(): void
    {
        [$charId, $chat] = $this->webOnly();
        $store = new WebScreenStore($this->conn);
        WebDelivery::useServices($store);

        WebDelivery::beginCapture($chat, $charId);
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'первый']);
        $store->applyCapture($charId, WebDelivery::endCapture());
        WebDelivery::beginCapture($chat, $charId);
        $a = $this->messageId(WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'текущий-а']));
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'текущий-б']);
        $store->applyCapture($charId, WebDelivery::endCapture());
        $historyBefore = $store->state($charId)['history'];

        WebDelivery::beginCapture($chat, $charId);
        $this->assertTrue(WebDeliverySpyRequest::editMessageText(['chat_id' => $chat, 'message_id' => $a, 'text' => 'текущий-а2'])->isOk());
        $store->applyCapture($charId, WebDelivery::endCapture());

        $state = $store->state($charId);
        $this->assertSame(['текущий-а2', 'текущий-б'], array_column($state['screen'], 'text'), 'правка на месте');
        $this->assertSame($historyBefore, $state['history'], 'история не тронута');
    }

    public function testEditOfHistoryPlusSendsMakeOneScreenEditedFirstWithOneHistoryPush(): void
    {
        [$charId, $chat] = $this->webOnly();
        $config              = new WebPlay();
        $config->historySize = 2;
        $store               = new WebScreenStore($this->conn, $config);
        WebDelivery::useServices($store);

        $ids = [];
        foreach (['один', 'два', 'три'] as $text) {
            WebDelivery::beginCapture($chat, $charId);
            WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => $text . '-x']);
            $ids[$text] = $this->messageId(WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => $text]));
            $store->applyCapture($charId, WebDelivery::endCapture());
        }
        // Экран: три; история: [два, один].
        WebDelivery::beginCapture($chat, $charId);
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'новое-а']);
        $this->assertTrue(WebDeliverySpyRequest::editMessageText(['chat_id' => $chat, 'message_id' => $ids['один'], 'text' => 'один 2'])->isOk());
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'новое-б']);
        $store->applyCapture($charId, WebDelivery::endCapture());

        $state = $store->state($charId);
        $this->assertSame(['один 2', 'новое-а', 'новое-б'], array_column($state['screen'], 'text'), 'правленое первым');
        $this->assertCount(2, $state['history'], 'история обрезана до historySize');
        $this->assertSame(['три-x', 'три'], array_column($state['history'][0], 'text'), 'одна запись истории на весь захват');
        $this->assertSame(['два-x', 'два'], array_column($state['history'][1], 'text'));
        $this->assertNoDuplicateIds($state);
    }

    public function testHistoryStaysCappedAfterPromotion(): void
    {
        [$charId, $chat] = $this->webOnly();
        $config              = new WebPlay();
        $config->historySize = 2;
        $store               = new WebScreenStore($this->conn, $config);
        WebDelivery::useServices($store);

        $ids = [];
        foreach (['один', 'два', 'три', 'четыре'] as $text) {
            WebDelivery::beginCapture($chat, $charId);
            $ids[$text] = $this->messageId(WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => $text]));
            WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => $text . '-x']);
            $store->applyCapture($charId, WebDelivery::endCapture());
        }
        // Экран: четыре; история: [три, два].
        WebDelivery::beginCapture($chat, $charId);
        $this->assertTrue(WebDeliverySpyRequest::editMessageText(['chat_id' => $chat, 'message_id' => $ids['два'], 'text' => 'два 2'])->isOk());
        $store->applyCapture($charId, WebDelivery::endCapture());

        $state = $store->state($charId);
        $this->assertSame(['два 2'], array_column($state['screen'], 'text'));
        $this->assertCount(2, $state['history'], 'история обрезана до historySize');
        $this->assertSame(['четыре', 'четыре-x'], array_column($state['history'][0], 'text'));
        $this->assertSame(['три', 'три-x'], array_column($state['history'][1], 'text'), 'старая запись «два» без правленого стала [два-x] и ушла за край');
        $this->assertNoDuplicateIds($state);
    }

    public function testEditOfUnknownMessageWhileCapturingAnswersNotFoundSoCallerFallsBackToSend(): void
    {
        [$charId, $chat] = $this->webOnly();
        WebDelivery::beginCapture($chat, $charId);

        $r = WebDeliverySpyRequest::editMessageText(['chat_id' => $chat, 'message_id' => 4242, 'text' => 'x']);
        $this->assertFalse($r->isOk());
        $this->assertStringContainsString('message to edit not found', (string) $r->getDescription());
        WebDelivery::endCapture();
    }

    public function testPhotoUrlUnderPublicAndCaptionOnlyOtherwise(): void
    {
        [$charId, $chat] = $this->webOnly();
        $tmp = tempnam(WRITEPATH, 'wdp');
        $this->assertIsString($tmp);
        file_put_contents($tmp, 'x');
        $caption = str_repeat('Длинная подпись. ', 40);

        WebDelivery::beginCapture($chat, $charId);
        $pub = fopen(FCPATH . 'apple-touch-icon.png', 'rb');
        $out = fopen($tmp, 'rb');
        $this->assertIsResource($pub);
        $this->assertIsResource($out);
        WebDeliverySpyRequest::sendPhoto(['chat_id' => $chat, 'photo' => $pub, 'caption' => 'В паблике']);
        WebDeliverySpyRequest::sendPhoto(['chat_id' => $chat, 'photo' => $out, 'caption' => $caption]);
        WebDeliverySpyRequest::sendPhoto(['chat_id' => $chat, 'photo' => 'https://example.com/a.jpg', 'caption' => 'url']);
        $capture = WebDelivery::endCapture();
        fclose($out);
        unlink($tmp);

        $this->assertIsString($capture['sent'][0]['photo_url']);
        $this->assertStringEndsWith('/apple-touch-icon.png', $capture['sent'][0]['photo_url']);
        $this->assertNull($capture['sent'][1]['photo_url']);
        $this->assertSame($caption, $capture['sent'][1]['caption'], 'подпись целиком (media-off)');
        $this->assertSame('https://example.com/a.jpg', $capture['sent'][2]['photo_url']);
    }

    public function testReplyKeyboardForceReplyAndCallbackAlert(): void
    {
        [$charId, $chat] = $this->webOnly();
        $store = new WebScreenStore($this->conn);

        WebDelivery::beginCapture($chat, $charId);
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => '🧭 Меню ниже.', 'reply_markup' => json_encode([
            'keyboard' => [[['text' => '🗺 Карта'], '🎒 Инвентарь'], [['text' => '⚙️ Настройки']]], 'resize_keyboard' => true,
        ])]);
        $ask = $this->messageId(WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Как назовёшь базу?', 'reply_markup' => json_encode([
            'force_reply' => true, 'input_field_placeholder' => 'Название',
        ])]));
        WebDeliverySpyRequest::answerCallbackQuery(['callback_query_id' => 'w-1', 'text' => 'Мало золота', 'show_alert' => true]);
        $capture = WebDelivery::endCapture();

        $this->assertSame([], WebDeliverySpyRequest::$calls);
        $this->assertSame('Мало золота', $capture['alert']);
        $this->assertSame([['🗺 Карта', '🎒 Инвентарь'], ['⚙️ Настройки']], $capture['dock']);
        $this->assertSame(['placeholder' => 'Название', 'reply_to' => $ask], $capture['input']);

        $store->applyCapture($charId, $capture);
        $state = $store->state($charId);
        $this->assertSame([['🗺 Карта', '🎒 Инвентарь'], ['⚙️ Настройки']], $state['dock']);
        $this->assertSame(['placeholder' => 'Название', 'reply_to' => $ask], $state['input']);

        WebDelivery::beginCapture($chat, $charId);
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Док убран', 'reply_markup' => json_encode(['remove_keyboard' => true])]);
        $store->applyCapture($charId, WebDelivery::endCapture());
        $this->assertSame([], $store->state($charId)['dock']);
    }

    public function testSyntheticIdsStartAtBaseAndGrowPerCharacter(): void
    {
        [$a, $chatA] = $this->webOnly();
        [$b]         = $this->webOnly();
        $store       = new WebScreenStore($this->conn);
        $base        = (new WebPlay())->firstMessageId;

        $this->assertSame($base, $store->nextMessageId($a));
        $this->assertSame($base + 1, $store->nextMessageId($a));
        $this->assertSame($base, $store->nextMessageId($b), 'счётчик свой у каждого персонажа');

        WebDelivery::beginCapture($chatA, $a);
        $this->assertSame($base + 2, $this->messageId(WebDeliverySpyRequest::sendMessage(['chat_id' => $chatA, 'text' => 'x'])));
        WebDelivery::endCapture();
    }

    // ── Ask 4: копия привязанному ────────────────────────────────────────

    public function testLinkedPlayerGetsTelegramAndOneMirrorRowButNeverAsActorOrForEdits(): void
    {
        $chat   = 700100200;
        $charId = $this->linked($chat, true);

        $r = WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'Поход завершён']);
        $this->assertSame(WebDeliverySpyRequest::$last, $r);
        $this->assertCount(1, WebDeliverySpyRequest::$calls, 'сообщение ушло в Telegram');
        $rows = $this->inbox($charId);
        $this->assertCount(1, $rows);
        $this->assertSame('mirror', $rows[0]['source']);

        WebDeliverySpyRequest::editMessageText(['chat_id' => $chat, 'message_id' => 3, 'text' => 'правка']);
        WebDeliverySpyRequest::deleteMessage(['chat_id' => $chat, 'message_id' => 3]);
        $this->assertCount(3, WebDeliverySpyRequest::$calls);
        $this->assertCount(1, $this->inbox($charId), 'edit/delete не копируются');

        DeliveryContext::setActor($chat);
        WebDeliverySpyRequest::sendMessage(['chat_id' => $chat, 'text' => 'ответ актору']);
        $this->assertCount(4, WebDeliverySpyRequest::$calls);
        $this->assertCount(1, $this->inbox($charId), 'ответ актору не копируется');
    }

    public function testBotOnlyPlayerGetsNoInboxRow(): void
    {
        $neverLoggedIn = $this->linked(700100300, false);
        $noAccount     = $this->botOnly(700100400);

        WebDeliverySpyRequest::sendMessage(['chat_id' => 700100300, 'text' => 'x']);
        WebDeliverySpyRequest::sendMessage(['chat_id' => 700100400, 'text' => 'y']);

        $this->assertCount(2, WebDeliverySpyRequest::$calls);
        $this->assertCount(0, $this->inbox($neverLoggedIn));
        $this->assertCount(0, $this->inbox($noAccount));
    }

    // ── Ask 5: всё прочее — как раньше ───────────────────────────────────

    public function testPassthroughDataAndReturnAreUnchanged(): void
    {
        $this->botOnly(700100500);
        $data = ['chat_id' => 700100500, 'text' => 'Привет', 'parse_mode' => 'Markdown', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => 'A', 'callback_data' => 'a'], ['text' => 'B', 'callback_data' => 'b']]]])];

        $r = WebDeliverySpyRequest::sendMessage($data);
        $this->assertSame(WebDeliverySpyRequest::$last, $r);
        $this->assertSame([['sendMessage', $data]], WebDeliverySpyRequest::$calls);

        $group = ['chat_id' => -1001234567890, 'message_id' => 9];
        $r2    = WebDeliverySpyRequest::deleteMessage($group);
        $this->assertSame(WebDeliverySpyRequest::$last, $r2);
        $this->assertSame(['deleteMessage', $group], WebDeliverySpyRequest::$calls[1], 'групповой отрицательный id не виртуальный');
        $this->assertSame(0, $this->conn->table('web_inbox')->countAllResults());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array{0:int, 1:int} [character_id, virtual chat id] */
    private function webOnly(): array
    {
        $this->conn->table('accounts')->insert(['acquisition_source' => 'web', 'created_at' => date('Y-m-d H:i:s')]);
        $accountId = (int) $this->conn->insertID();
        $chat      = VirtualChat::idForAccount($accountId);
        $tuId      = $this->telegramUser($chat);

        return [$this->character($tuId, $accountId), $chat];
    }

    private function linked(int $chat, bool $loggedIn): int
    {
        $this->conn->table('accounts')->insert([
            'acquisition_source' => 'telegram',
            'created_at'         => date('Y-m-d H:i:s'),
            'last_login_at'      => $loggedIn ? date('Y-m-d H:i:s') : null,
        ]);

        return $this->character($this->telegramUser($chat), (int) $this->conn->insertID());
    }

    private function botOnly(int $chat): int
    {
        return $this->character($this->telegramUser($chat), null);
    }

    private function telegramUser(int $telegramId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert(['telegram_id' => $telegramId, 'created_at' => $now, 'updated_at' => $now]);

        return (int) $this->conn->insertID();
    }

    private function character(int $tuId, ?int $accountId): int
    {
        $this->conn->table('characters')->insert([
            'telegram_user_id' => $tuId, 'account_id' => $accountId, 'name' => 'Игрок' . $tuId,
            'level' => 1, 'experience' => 0.01, 'health' => 100, 'tired' => 100,
            'strength' => 0.01, 'agility' => 0.01, 'intellect' => 0.01, 'gold' => 1000,
        ]);

        return (int) $this->conn->insertID();
    }

    /** @return list<array<string,mixed>> */
    private function inbox(int $charId): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->conn->table('web_inbox')->where('character_id', $charId)->orderBy('id')->get()->getResultArray();

        return $rows;
    }

    /** @param array{screen:list<array{message_id:int}>, history:list<list<array{message_id:int}>>} $state */
    private function assertNoDuplicateIds(array $state): void
    {
        $ids = array_column($state['screen'], 'message_id');
        foreach ($state['history'] as $entry) {
            $ids = array_merge($ids, array_column($entry, 'message_id'));
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'message_id не повторяется в экране и истории');
    }

    private function messageId(ServerResponse $r): int
    {
        $result = $r->getResult();
        $this->assertInstanceOf(Message::class, $result);

        return (int) $result->getMessageId();
    }

    private function setFlag(bool $on): void
    {
        service('cache')->save(self::FLAG_CACHE, ['v' => $on, 't' => 'bool'], 60);
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
