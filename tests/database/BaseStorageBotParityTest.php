<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageDepositAction;
use App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageListAction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\RequestInterface;

/**
 * w2-n6-trade-storage-01 — паритет бота: «📦 Склад базы» и «📥 Положить на склад» после выноса в
 * `BaseStorageService` дают тот же текст и те же кнопки. Ожидания сняты со старых handler'ов: тест
 * зелёный и на коде до переноса (снимок «до»), и после. Гейт «на базе» — настоящий: игрок на клетке 5,
 * база на клетке 5 (на базе) или 9 (не на базе).
 *
 * Отдельный процесс: соседние тесты определяют `PHPUNIT_TESTSUITE`, и Longman под ним отвечает фейком.
 *
 * @internal
 */
final class BaseStorageBotParityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2026-05-29-500000_W3aCreateBaseStorage',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'claimed_cells', 'base_storage',
        'game_settings', 'resources', 'character_resources',
    ];

    private const TG = 771000016;

    private const LIST_ON_BASE_KB = [
        [['• 🕒 Недавние', 'baseStorageList_sort_recent'], ['🔤 Название', 'baseStorageList_sort_name'], ['🔢 Кол-во', 'baseStorageList_sort_qty']],
        [['💧 Вода', 'baseStorageList_res_1_recent'], ['🟫 Глина', 'baseStorageList_res_2_recent']],
        [['🎒 Забрать всё', 'baseStorageList_all'], ['📥 Положить на склад', 'baseStorageDeposit']],
        [['🚚 Карго-дрон', 'cargoDroneList'], ['🏠 База', 'Base']],
    ];

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, icon_text VARCHAR(255) NULL,'
                . ' rarity INT NULL, sell_price INT NULL, buy_price INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStorageListOnBaseOffBaseAndEmpty(): void
    {
        $this->base(5);
        $this->store(1, 30, '2026-10-01 10:00:00');
        $this->store(2, 12, '2026-09-30 10:00:00');

        $msg = $this->sent(BaseStorageListAction::class, 'baseStorageList');
        $this->assertSame(
            "📦 *Склад базы*\n\n💧 Вода — *30* шт.\n🟫 Глина — *12* шт.\n\nИтого: *42* шт.\n\n"
            . 'Ты на базе — можно забрать всё в инвентарь, забрать один вид, или, наоборот, сложить сюда добычу из рюкзака.',
            $msg['text']
        );
        $this->assertSame($this->kb(self::LIST_ON_BASE_KB), $msg['reply_markup']);

        $this->conn->query('UPDATE claimed_cells SET map_cell_id = 9');
        $msg = $this->sent(BaseStorageListAction::class, 'baseStorageList_sort_qty');
        $this->assertSame(
            "📦 *Склад базы*\n\n💧 Вода — *30* шт.\n🟫 Глина — *12* шт.\n\nИтого: *42* шт.\n\n"
            . '_Склад физически на базе. Вернись на свою клейм-клетку, чтобы забрать или сложить руками — '
            . 'а из поля груз домой носит карго-дрон._',
            $msg['text']
        );
        $this->assertSame($this->kb([
            [['🕒 Недавние', 'baseStorageList_sort_recent'], ['🔤 Название', 'baseStorageList_sort_name'], ['• 🔢 Кол-во', 'baseStorageList_sort_qty']],
            [['Карта', 'move'], ['🚚 Карго-дрон', 'cargoDroneList']],
        ]), $msg['reply_markup']);

        $this->conn->query('DELETE FROM base_storage');
        $msg = $this->sent(BaseStorageListAction::class, 'baseStorageList');
        $this->assertSame(
            "📦 *Склад базы пуст*\n\nСюда можно сложить добычу двумя путями: руками, стоя на базе, или карго-дроном с любой клетки.",
            $msg['text']
        );
        $this->assertSame($this->kb([[['📥 Положить на склад', 'baseStorageDeposit'], ['🚚 Карго-дрон', 'cargoDroneList'], ['🏠 База', 'Base']]]), $msg['reply_markup']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRetrieveAllAndOne(): void
    {
        $this->base(5);
        $this->store(1, 30, '2026-10-01 10:00:00');
        $this->store(2, 12, '2026-09-30 10:00:00');

        $msg = $this->sent(BaseStorageListAction::class, 'baseStorageList_res_1_name');
        $this->assertSame("🎒 *Забрано со склада*\n\n  💧 *Вода* × *30* шт.\n\nРесурс теперь в рюкзаке.", $msg['text']);
        $this->assertSame($this->kb([
            [['🎒 Забрать ещё', 'baseStorageList_sort_name'], ['📥 Положить на склад', 'baseStorageDeposit']],
            [['🎒 Инвентарь', 'inventory'], ['🏠 База', 'Base']],
        ]), $msg['reply_markup']);

        $msg = $this->sent(BaseStorageListAction::class, 'baseStorageList_all');
        $this->assertSame("🎒 *Забрано со склада: 12 шт.*\n\nВсе ресурсы перенесены в инвентарь.", $msg['text']);
        $this->assertSame($this->kb([[['🎒 Инвентарь', 'inventory'], ['🏠 База', 'Base']]]), $msg['reply_markup']);

        $msg = $this->sent(BaseStorageListAction::class, 'baseStorageList_all');
        $this->assertSame('📦 Склад уже пуст — забирать нечего.', $msg['text']);

        $this->assertSame(30, $this->backpack(1));
        $this->assertSame(12, $this->backpack(2));

        $this->store(1, 4, '2026-10-02 10:00:00');
        $this->conn->query('UPDATE claimed_cells SET map_cell_id = 9');
        $msg = $this->sent(BaseStorageListAction::class, 'baseStorageList_all');
        $this->assertSame('🚫 Чтобы забрать со склада, нужно быть на своей клейм-клетке. Вернись на базу.', $msg['text']);
        $this->assertSame(4, $this->stored(1));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDepositScreensAndMoves(): void
    {
        $this->base(5);
        $this->carry(1, 3);
        $this->carry(2, 8);

        $msg = $this->sent(BaseStorageDepositAction::class, 'baseStorageDeposit');
        $this->assertSame(
            "📥 *Положить на склад*\n\nТы на базе — можно переложить добытое из рюкзака на склад руками, без карго-дрона.\n\n"
            . "🟫 Глина — *8* шт.\n💧 Вода — *3* шт.\n\nВсего в рюкзаке: *11* шт.\n\nНажми на ресурс, чтобы отправить его на склад целиком.",
            $msg['text']
        );
        $this->assertSame($this->kb([
            [['🟫 Глина', 'baseStorageDeposit_res_2'], ['💧 Вода', 'baseStorageDeposit_res_1']],
            [['📥 Положить всё', 'baseStorageDeposit_all'], ['📦 Склад базы', 'baseStorageList']],
            [['🎒 Инвентарь', 'inventory'], ['🏠 База', 'Base']],
        ]), $msg['reply_markup']);

        $msg = $this->sent(BaseStorageDepositAction::class, 'baseStorageDeposit_res_2');
        $this->assertSame(
            "📥 *Убрано на склад*\n\n  🟫 *Глина* × *8* шт.\n\nРесурс никуда не делся — он на складе базы, забрать можно там же, стоя на базе.",
            $msg['text']
        );
        $this->assertSame($this->kb([
            [['📥 Положить ещё', 'baseStorageDeposit'], ['📦 Склад базы', 'baseStorageList']],
            [['🎒 Инвентарь', 'inventory'], ['🏠 База', 'Base']],
        ]), $msg['reply_markup']);

        $msg = $this->sent(BaseStorageDepositAction::class, 'baseStorageDeposit_all');
        $this->assertSame(
            "📥 *Убрано на склад: 3 шт.*\n\nВидов ресурсов: *1*. Рюкзак пуст, всё лежит на складе базы — забрать можно там же.",
            $msg['text']
        );
        $this->assertSame($this->kb([[['📦 Склад базы', 'baseStorageList'], ['🏠 База', 'Base']]]), $msg['reply_markup']);

        $msg = $this->sent(BaseStorageDepositAction::class, 'baseStorageDeposit_all');
        $this->assertSame('🎒 В рюкзаке пусто — складывать нечего.', $msg['text']);

        $this->assertSame(8, $this->stored(2));
        $this->assertSame(3, $this->stored(1));
        $this->assertSame(1, (int) $this->conn->query("SELECT COUNT(*) AS n FROM action_log WHERE action_name = 'BASE_STORAGE_DEPOSIT' AND chat_id = ?", [self::TG])->getRowArray()['n']);

        $this->carry(1, 2);
        $this->conn->query('UPDATE claimed_cells SET map_cell_id = 9');
        $msg = $this->sent(BaseStorageDepositAction::class, 'baseStorageDeposit_res_1');
        $this->assertSame(
            "🔒 *Положить на склад можно только на базе*\n\nСклад стоит на твоей клейм-клетке — руками занести туда добычу получится, "
            . "только когда ты сам на базе.\n\nИз поля работает карго-дрон: он заберёт до 30 кг с любой клетки и отвезёт их на склад сам.",
            $msg['text']
        );
        $this->assertSame($this->kb([
            // Одиночный ряд нормализатор доклеивает к предыдущему — снимок «до» именно такой.
            [['🚚 Карго-дрон', 'cargoDroneList'], ['Карта', 'move'], ['📦 Склад базы', 'baseStorageList']],
        ]), $msg['reply_markup']);
        $this->assertSame(2, $this->backpack(1));
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (9, 9, 8, 0, 1)');
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, ?)', [self::TG, 'Тест']);
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
        );
        $this->conn->query("INSERT INTO resources (id, name, icon_text) VALUES (1, 'Вода', '💧'), (2, 'Глина', '🟫')");
        $now = date('Y-m-d H:i:s');
        // Подсказка первого открытия склада шлёт второе сообщение — снимок про сам экран.
        $this->conn->table('game_settings')->insert([
            'setting_key' => 'onboarding.contextual_hints.enabled', 'category' => 'world', 'value_type' => 'bool', 'value_bool' => 0,
            'default_value_text' => '1', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function base(int $cell): void
    {
        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (1, ?, 'active')", [$cell]);
    }

    private function store(int $resourceId, int $qty, string $at): void
    {
        $this->conn->table('base_storage')->insert([
            'character_id' => 1, 'resource_id' => $resourceId, 'quantity' => $qty, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function carry(int $resourceId, int $qty): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('character_resources')->insert([
            'id_characters' => 1, 'id_resources' => $resourceId, 'quantity' => $qty, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function stored(int $resourceId): int
    {
        $row = $this->conn->query('SELECT COALESCE(SUM(quantity), 0) AS q FROM base_storage WHERE character_id = 1 AND resource_id = ?', [$resourceId])->getRowArray();

        return (int) ($row['q'] ?? 0);
    }

    private function backpack(int $resourceId): int
    {
        $row = $this->conn->query('SELECT COALESCE(SUM(quantity), 0) AS q FROM character_resources WHERE id_characters = 1 AND id_resources = ?', [$resourceId])->getRowArray();

        return (int) ($row['q'] ?? 0);
    }

    /**
     * @param list<list<array{0:string,1:string}>> $rows
     */
    private function kb(array $rows): string|false
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = array_map(static fn (array $b): array => ['text' => $b[0], 'callback_data' => $b[1]], $row);
        }

        return json_encode(['inline_keyboard' => $out]);
    }

    /** @return array{text: mixed, reply_markup: mixed} */
    private function sent(string $actionClass, string $data): array
    {
        $sent = [];
        new Telegram('123456:TEST_TOKEN', 'wildworldtest_bot');
        LongmanRequest::setClient(new Client(['handler' => static function (RequestInterface $request) use (&$sent): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path   = explode('/', $request->getUri()->getPath());
            $sent[] = ['method' => (string) end($path)] + $params;

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]));

        $cbq = new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => self::TG, 'type' => 'private'], 'text' => 'x'],
            'chat_instance' => 'ci', 'data' => $data,
        ]);
        $action = new $actionClass($cbq);
        $this->assertTrue(method_exists($action, 'handle'));
        $action->handle();

        $calls = array_values(array_filter($sent, static fn (array $c): bool => $c['method'] === 'sendMessage'));
        $this->assertCount(1, $calls, 'sendMessage in ' . json_encode(array_column($sent, 'method')));

        return ['text' => $calls[0]['text'] ?? null, 'reply_markup' => $calls[0]['reply_markup'] ?? null];
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
