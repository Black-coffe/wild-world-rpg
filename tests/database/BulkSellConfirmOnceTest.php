<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Sell\BulkSellAction;
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
 * hotfix-bulk-confirm-once — кнопка подтверждения оптовой продажи в боте исполняется один раз.
 *
 * Владелец 2026-10-08: «Оптовая продажа («🧺 Всё», «💰 N%»), похоже, всё ещё платит дважды при двойном
 * нажатии.» Замер: повторное «✅ Да, продать 50%» продавало ещё половину остатка. Теперь в callback
 * едет отпечаток плана из превью; второе нажатие — отказ, одна запись `BULK_SELL`.
 *
 * Отдельный процесс: соседние тесты определяют `PHPUNIT_TESTSUITE`, и Longman под ним отвечает фейком.
 *
 * @internal
 */
final class BulkSellConfirmOnceTest extends CIUnitTestCase
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
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'game_settings',
        'resources', 'character_resources', 'resources_bank',
    ];

    private const TG = 771000036;

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
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' icon_text VARCHAR(255) NULL, rarity INT NULL, sell_price DECIMAL(10,2) NULL, buy_price DECIMAL(10,2) NULL,'
                . ' is_tradeable TINYINT NOT NULL DEFAULT 1, level_required INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE resources_bank (id INT AUTO_INCREMENT PRIMARY KEY, resource_id INT, current_quantity INT DEFAULT 0,'
                . ' resources_purchased INT DEFAULT 0, resources_sold INT DEFAULT 0, last_update DATETIME NULL, UNIQUE KEY uq_res (resource_id))'
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
    public function testSameConfirmButtonTwiceSellsOnce(): void
    {
        $go = $this->confirmButton('bulkSell_all_50');
        $this->assertMatchesRegularExpression('/^bulkSell_go_all_50_[0-9a-f]{8}$/', $go);

        $first = $this->sent(BulkSellAction::class, $go);
        $this->assertStringContainsString('Оптовая продажа выполнена', (string) $first['text']);

        $second = $this->sent(BulkSellAction::class, $go);
        $this->assertSame(
            '🧺 Эта оптовая продажа уже выполнена или запас изменился — открой оптовую продажу заново.',
            $second['text']
        );
        $this->assertSame(
            json_encode(['inline_keyboard' => [[['text' => '⬅️ Назад', 'callback_data' => 'sell'], ['text' => '🛒 Магазин', 'callback_data' => 'shop']]]]),
            $second['reply_markup']
        );

        $this->assertSame(20, $this->owned(7));
        $this->assertSame(2, $this->owned(8));
        $this->assertSame(1092.0, $this->gold());
        $this->assertSame(1, $this->bulkLogs());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRarityConfirmTwiceAndOldButton(): void
    {
        $go = $this->confirmButton('bulkSell_rarity_1_100');
        $this->assertMatchesRegularExpression('/^bulkSell_go_rarity_1_100_[0-9a-f]{8}$/', $go);
        $this->sent(BulkSellAction::class, $go);
        $this->sent(BulkSellAction::class, $go);
        $this->assertSame(1, $this->bulkLogs());
        $this->assertSame(1186.0, $this->gold());

        // Кнопка подтверждения старого формата (без отпечатка) — не продаёт.
        $this->conn->query("INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, 7, 30)");
        foreach (['bulkSell_go_all_50', 'bulkSell_go_rarity_1_50'] as $old) {
            $msg = $this->sent(BulkSellAction::class, $old);
            $this->assertSame('🧺 Кнопка устарела — открой оптовую продажу заново.', $msg['text']);
        }
        $this->assertSame(30, $this->owned(7));
        $this->assertSame(1, $this->bulkLogs());
    }

    /** Самый длинный callback подтверждения укладывается в лимит Telegram (64 байта). */
    public function testLongestConfirmCallbackFitsTelegramLimit(): void
    {
        $longest = 'bulkSell_go_rarity_10_100_' . str_repeat('f', \App\Services\Player\Trade\ResourceTradeService::BULK_TOKEN_LENGTH);
        $this->assertLessThanOrEqual(64, strlen($longest));
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, ?)', [self::TG, 'Тест']);
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 1000, 5, 0)"
        );
        $this->conn->query(
            'INSERT INTO resources (id, name, icon_text, rarity, sell_price, buy_price, is_tradeable, level_required) VALUES'
            . " (7, 'Ржавый лом', '🔧', 1, 4.50, 10.00, 1, 0),"
            . " (8, 'Глина', '🟫', 1, 2.00, 5.00, 1, 0),"
            . " (9, 'Семена', '🌱', 1, 0.00, 0.00, 0, 0),"
            . " (10, 'Уран', '☢️', 2, 100.00, 250.50, 1, 50)"
        );
        $now = date('Y-m-d H:i:s');
        foreach ([[7, 40], [8, 3], [9, 6]] as [$res, $qty]) {
            $this->conn->table('character_resources')->insert([
                'id_characters' => 1, 'id_resources' => $res, 'quantity' => $qty, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $base = [
            'category' => 'world', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->conn->table('game_settings')->insert($base + [
            'setting_key' => 'onboarding.contextual_hints.enabled', 'value_type' => 'bool', 'value_bool' => 0, 'default_value_text' => '1',
        ]);
        $this->conn->table('game_settings')->insert($base + [
            'setting_key' => BulkSellAction::KEY_ENABLED, 'value_type' => 'bool', 'value_bool' => 1, 'default_value_text' => '1',
        ]);
    }

    private function owned(int $resourceId): int
    {
        $row = $this->conn->query('SELECT COALESCE(SUM(quantity), 0) AS q FROM character_resources WHERE id_characters = 1 AND id_resources = ?', [$resourceId])->getRowArray();

        return (int) ($row['q'] ?? 0);
    }

    private function gold(): float
    {
        return (float) ($this->conn->query('SELECT gold FROM characters WHERE id = 1')->getRowArray()['gold'] ?? -1);
    }

    private function confirmButton(string $previewData): string
    {
        $preview = $this->sent(BulkSellAction::class, $previewData);
        $kb      = json_decode((string) $preview['reply_markup'], true);
        $this->assertIsArray($kb);
        foreach ($kb['inline_keyboard'] ?? [] as $row) {
            foreach ($row as $btn) {
                if (str_starts_with((string) ($btn['callback_data'] ?? ''), 'bulkSell_go_')) {
                    return (string) $btn['callback_data'];
                }
            }
        }
        $this->fail('нет кнопки подтверждения в ' . $preview['reply_markup']);
    }

    private function bulkLogs(): int
    {
        return (int) ($this->conn->query("SELECT COUNT(*) AS n FROM action_log WHERE action_name = 'BULK_SELL'")->getRowArray()['n'] ?? -1);
    }

    /** @return array{method: string, text: mixed, reply_markup: mixed} */
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

        $calls = array_values(array_filter($sent, static fn (array $c): bool => in_array($c['method'], ['sendMessage', 'editMessageText'], true)));
        $alert = array_values(array_filter($sent, static fn (array $c): bool => $c['method'] === 'answerCallbackQuery' && isset($c['text'])));
        if ($calls === [] && $alert !== []) {
            return ['method' => 'answerCallbackQuery', 'text' => $alert[0]['text'], 'reply_markup' => null];
        }
        $this->assertCount(1, $calls, 'screen calls in ' . json_encode(array_column($sent, 'method')));

        return ['method' => $calls[0]['method'], 'text' => $calls[0]['text'] ?? null, 'reply_markup' => $calls[0]['reply_markup'] ?? null];
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
