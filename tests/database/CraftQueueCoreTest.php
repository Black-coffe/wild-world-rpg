<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Craft\CancelQueuedCraftAction;
use App\Controllers\Telegram\Commands\Actions\Craft\ShowCraftQueueAction;
use App\Services\Craft\CraftDurationService;
use App\Services\Craft\CraftQueueService;
use App\Services\Telegram\TelegramBridge;
use App\Services\Web\VirtualChat;
use App\Services\Web\WebDelivery;
use App\TaskHandlers\Craft\GenericCraftCompletionHandler;
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
 * W2.N3-02 (ADR-190) — очередь крафта как ядро.
 *
 * @internal
 */
final class CraftQueueCoreTest extends CIUnitTestCase
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
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-04-16-122053_CreateCraftedItemsLogTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2026-05-29-500000_W3aCreateBaseStorage',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2026-05-25-170000_V20CreateFactionProjects',
        '2026-11-24-100000_CreateCharacterDebuffs',
        '2026-12-10-100001_CreateAccountsTables',
        '2026-12-10-100002_LinkCharactersToAccounts',
        '2026-12-10-100010_NullableTelegramKeys',
        '2026-12-11-100001_CreateWebPlayTables',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources', 'character_resources', 'tasks',
        'character_tasks', 'crafted_items', 'crafted_items_log', 'claimed_cells', 'buildings', 'character_buildings',
        'base_storage', 'game_settings', 'factions', 'character_factions', 'faction_projects', 'character_debuffs',
        'accounts', 'account_identities', 'account_tokens', 'account_link_codes', 'web_play_state', 'web_inbox',
        'web_play_intents',
    ];

    private const ENV = ['telegram.API_KEY' => '123456:TEST_TOKEN', 'telegram.BOT_USERNAME' => 'wildworldtest_bot'];

    private const CHAR = 1;
    private const TG   = 555003;

    private const RESOURCES = [1 => 'Травы', 2 => 'Кора деревьев', 3 => 'Водоросли', 4 => 'Грибы', 5 => 'Мед', 6 => 'Алоэ', 7 => 'Вода'];

    private BaseConnection $conn;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' biome_id TEXT NULL, is_tradeable TINYINT(1) NOT NULL DEFAULT 1, type VARCHAR(255) NULL, price INT NULL, buy_price INT NULL,'
                . ' sell_price INT NULL, rarity INT NULL, level_required INT NULL, icon_text VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
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
        foreach (self::ENV as $k => $v) {
            $this->envBackup[$k] = getenv($k);
            putenv("{$k}={$v}");
        }
        $this->mockCache();
        $this->installRecorder();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            putenv($v === false ? $k : "{$k}={$v}");
        }
        $this->dropTables();
        parent::tearDown();
    }

    private const CASES = ['queue_empty', 'queue_full', 'cancel_ok', 'cancel_gone', 'complete'];

    /**
     * Снимок бота ДО переноса логики в ядро (снят с прежних ShowCraftQueueAction / CancelQueuedCraftAction /
     * GenericCraftCompletionHandler на этой фикстуре): запросы Bot API и состояние БД. «Завершится в *HH:MM*»
     * замаскировано — зависит от часов.
     */
    private const BOT_BEFORE = <<<'JSON'
        {
            "queue_empty": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555003",
                        "message_id": "77",
                        "text": "📋 *Очередь крафта пуста.*\n\nЗапусти крафт на верстаке — если что-то уже крафтится, новый такой же рецепт встанет в очередь.",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\ud83e\\uddd1\\u200d\\ud83c\\udf3e \\u0414\\u0435\\u0439\\u0441\\u0442\\u0432\\u0438\\u044f \\ud83d\\udee0\\ufe0f\",\"callback_data\":\"characterActions\"}]]}"
                    }
                ],
                "rows": [],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "storage": [],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150"
            },
            "queue_full": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555003",
                        "message_id": "77",
                        "text": "📋 *Очередь крафта*\nАктивных: *1*, в очереди: *2*\n\n🔨 *Сейчас крафтится:*\n• Изготовление повязки ×1 — готово через 59 мин.\n\n⏳ *В очереди* (активируются по мере завершения):\n1. Изготовление повязки ×2\n2. Изготовление аптечки ×1\n\n_Отмена очереди возвращает списанные ресурсы._",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u274c \\u041e\\u0442\\u043c\\u0435\\u043d\\u0438\\u0442\\u044c: \\u0418\\u0437\\u0433\\u043e\\u0442\\u043e\\u0432\\u043b\\u0435\\u043d\\u0438\\u0435 \\u043f\\u043e\\u0432\\u044f\\u0437\\u043a\\u0438 \\u00d72\",\"callback_data\":\"cancelQueued_2\"},{\"text\":\"\\u274c \\u041e\\u0442\\u043c\\u0435\\u043d\\u0438\\u0442\\u044c: \\u0418\\u0437\\u0433\\u043e\\u0442\\u043e\\u0432\\u043b\\u0435\\u043d\\u0438\\u0435 \\u0430\\u043f\\u0442\\u0435\\u0447\\u043a\\u0438 \\u00d71\",\"callback_data\":\"cancelQueued_3\"}],[{\"text\":\"\\ud83d\\udd04 \\u041e\\u0431\\u043d\\u043e\\u0432\\u0438\\u0442\\u044c\",\"callback_data\":\"craftQueue\"},{\"text\":\"\\ud83e\\uddd1\\u200d\\ud83c\\udf3e \\u0414\\u0435\\u0439\\u0441\\u0442\\u0432\\u0438\\u044f \\ud83d\\udee0\\ufe0f\",\"callback_data\":\"characterActions\"}]]}"
                    }
                ],
                "rows": [
                    {
                        "id": "1",
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": "60",
                        "task_settings": "{\"recipe\":\"Bandage\",\"quantity\":1}"
                    },
                    {
                        "id": "2",
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": "{\"recipe\":\"Bandage\",\"quantity\":2}"
                    },
                    {
                        "id": "3",
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBasicMedKit",
                        "due": null,
                        "task_settings": "{\"recipe\":\"BasicMedKit\",\"quantity\":1}"
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "storage": [],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150"
            },
            "cancel_ok": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1",
                        "text": "Задача отменена, ресурсы возвращены."
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555003",
                        "message_id": "77",
                        "text": "🗑 *Задача из очереди отменена*\n\nВозвращены ресурсы для крафта *Повязка* x2 шт.",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\ud83d\\udccb \\u041e\\u0447\\u0435\\u0440\\u0435\\u0434\\u044c \\u043a\\u0440\\u0430\\u0444\\u0442\\u0430\",\"callback_data\":\"craftQueue\"}]]}"
                    }
                ],
                "rows": [
                    {
                        "id": "1",
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": "60",
                        "task_settings": "{\"recipe\":\"Bandage\",\"quantity\":1}"
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "24"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "24"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "36"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "storage": [],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150"
            },
            "cancel_gone": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555003",
                        "text": "Задача в очереди не найдена или уже активирована.",
                        "parse_mode": "Markdown"
                    }
                ],
                "rows": [
                    {
                        "id": "1",
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": "60",
                        "task_settings": "{\"recipe\":\"Bandage\",\"quantity\":2}"
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "storage": [],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150"
            },
            "complete": {
                "sent": [
                    {
                        "method": "sendMessage",
                        "chat_id": "555003",
                        "text": "📌 *Крафт завершён!*\n\nТы создал: 🩹 *Повязка* x2 шт.\n\nТеперь у тебя *9 шт.* в инвентаре.\n\nЗона применения: *медицина* 💊",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\ud83d\\udd04 \\u041a\\u0440\\u0430\\u0444\\u0442\\u0438\\u0442\\u044c \\u0435\\u0449\\u0435\",\"callback_data\":\"genericCraft_Bandage_2\"},{\"text\":\"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c\",\"callback_data\":\"inventory\"}]]}"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555003",
                        "text": "▶️ *Очередь активирована!*\n\nЗапущен крафт: 🩹 *Повязку* x3 шт.\n\nЗавершится в *HH:MM*.",
                        "parse_mode": "Markdown"
                    }
                ],
                "rows": [
                    {
                        "id": "1",
                        "status": "completed",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": "60",
                        "task_settings": "{\"recipe\":\"Bandage\",\"quantity\":2}"
                    },
                    {
                        "id": "2",
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": "90",
                        "task_settings": "{\"recipe\":\"Bandage\",\"quantity\":3}"
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "storage": [],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "9"
                    }
                ],
                "gold": "150"
            }
        }
        JSON;

    /**
     * Паритет бота: экран очереди (пустой и с активным + двумя ожидающими), отмена (успех и «уже
     * активирована»), «📌 Крафт завершён!» + «▶️ Очередь активирована» — те же запросы и строки, что до ядра.
     * Отдельный процесс: соседние тесты определяют `PHPUNIT_TESTSUITE`, и Longman под ним отвечает фейком.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBotSendsTheSameMessagesAsBefore(): void
    {
        $before = json_decode(self::BOT_BEFORE, true);
        $this->assertIsArray($before);
        $this->assertSame(self::CASES, array_keys($before));
        foreach (self::CASES as $case) {
            $this->assertSame($before[$case], $this->runCase($case), "паритет бота: {$case}");
        }
    }

    /** Отмена возвращает сырьё в рюкзак и на склад по разбивке старта, компоненты и золото — как списаны. */
    public function testCancelReturnsWhereEachPartWasTakenFrom(): void
    {
        $this->resetCase();
        $this->conn->query('INSERT INTO base_storage (character_id, resource_id, quantity) VALUES (1, 2, 10)');
        $id = $this->charTask($this->taskId('craftBandage'), 'queued', 'Bandage', 2, [
            'resources'     => ['Травы' => ['backpack' => 1, 'storage' => 3], 'Кора деревьев' => ['backpack' => 0, 'storage' => 4], 'Водоросли' => ['backpack' => 6, 'storage' => 0]],
            'crafted_items' => ['Bandage' => 5],
            'gold'          => 40,
        ]);

        $out = (new CraftQueueService())->cancel(self::CHAR, $id);

        $this->assertSame([true, CraftQueueService::CANCELLED, 'Повязка', 2], [$out['ok'], $out['code'], $out['name'], $out['qty']]);
        $this->assertSame(['1' => '21', '2' => '20', '3' => '36'], $this->backpack([1, 2, 3]));
        $state = $this->state();
        $this->assertSame([['resource_id' => '1', 'quantity' => '3'], ['resource_id' => '2', 'quantity' => '14']], $state['storage']);
        $this->assertSame([['crafted_item_id' => '1', 'quantity' => '12']], $state['items']);
        $this->assertSame('190', (string) $state['gold']);
        $this->assertSame([], $state['rows']);
    }

    /** Строка без разбивки (старт до story 01) — рецепт × количество в рюкзак, склад не трогается. */
    public function testCancelOfRowWithoutBreakdownReturnsToBackpack(): void
    {
        $this->resetCase();
        $this->conn->query('INSERT INTO base_storage (character_id, resource_id, quantity) VALUES (1, 1, 10)');
        $id = $this->charTask($this->taskId('craftBandage'), 'queued', 'Bandage', 2);

        $this->assertTrue((new CraftQueueService())->cancel(self::CHAR, $id)['ok']);

        $this->assertSame(['1' => '24', '2' => '24', '3' => '36'], $this->backpack([1, 2, 3]));
        $this->assertSame([['resource_id' => '1', 'quantity' => '10']], $this->state()['storage']);
    }

    /**
     * Отмена и продвижение одной строки — срабатывает ровно одно из двух, даже когда второй шаг
     * прочитал строку ещё `queued` (снимок до чужой записи, как у параллельного запроса).
     */
    public function testCancelAndPromoteOfOneRowNeverBothApply(): void
    {
        $bandage = $this->taskId('craftBandage');

        // Продвижение первым; отмена со снимком «ещё queued» — отказ без возврата.
        $this->resetCase();
        $id    = $this->charTask($bandage, 'queued', 'Bandage', 2);
        $stale = $this->row($id);
        $this->assertSame($id, (new CraftQueueService())->promoteNext(self::CHAR, $bandage)['charTaskId'] ?? null);
        $afterPromote = $this->state();
        $cancel       = $this->staleCancel($stale)->cancel(self::CHAR, $id);
        $this->assertSame([false, CraftQueueService::NOT_FOUND], [$cancel['ok'], $cancel['code']]);
        $this->assertSame($afterPromote, $this->state(), 'отмена после продвижения ничего не вернула и не сняла');
        $this->assertSame('in_work', $this->row($id)['status']);

        // Отмена первой; продвижение со снимком «ещё queued» — не запускает снятую строку.
        $this->resetCase();
        $id    = $this->charTask($bandage, 'queued', 'Bandage', 2);
        $stale = $this->row($id);
        $this->assertTrue((new CraftQueueService())->cancel(self::CHAR, $id)['ok']);
        $afterCancel = $this->state();
        $this->assertNull($this->stalePromote([$stale])->promoteNext(self::CHAR, $bandage));
        $this->assertSame($afterCancel, $this->state());
    }

    /** Два продвижения одной строки (два воркера по одному снимку) — запускает только первое. */
    public function testSecondPromoteOfTheSameRowDoesNothing(): void
    {
        $bandage = $this->taskId('craftBandage');
        $this->resetCase();
        $id    = $this->charTask($bandage, 'queued', 'Bandage', 2);
        $stale = $this->row($id);

        $this->assertNotNull($this->stalePromote([$stale])->promoteNext(self::CHAR, $bandage));
        $this->conn->query("UPDATE character_tasks SET end_time = '2030-01-01 00:00:00' WHERE id = ?", [$id]);
        $afterFirst = $this->state();

        $this->assertNull($this->stalePromote([$stale])->promoteNext(self::CHAR, $bandage));
        $this->assertSame($afterFirst, $this->state(), 'второе продвижение не перезапустило строку');
    }

    /** Снятая отменой голова очереди пропускается — запускается следующая строка того же рецепта. */
    public function testPromoteSkipsARowCancelledUnderIt(): void
    {
        $bandage = $this->taskId('craftBandage');
        $this->resetCase();
        $first  = $this->charTask($bandage, 'queued', 'Bandage', 2);
        $second = $this->charTask($bandage, 'queued', 'Bandage', 1);
        $stale  = $this->row($first);
        $this->assertTrue((new CraftQueueService())->cancel(self::CHAR, $first)['ok']);

        $promoted = $this->stalePromote([$stale])->promoteNext(self::CHAR, $bandage);

        $this->assertSame($second, $promoted['charTaskId'] ?? null);
        $this->assertSame('in_work', $this->row($second)['status']);
    }

    /**
     * Оценка ожидающих: остаток активного крафта того же рецепта + `minutesForOne × qty` каждого
     * ожидающего того же рецепта впереди; рецепт без активного — с нуля.
     */
    public function testQueuedEstimateFollowsTheFormula(): void
    {
        $this->resetCase();
        $bandage = $this->taskId('craftBandage');
        $medkit  = $this->taskId('craftBasicMedKit');
        $this->charTask($bandage, 'in_work', 'Bandage', 1, null, 600);
        $q1 = $this->charTask($bandage, 'queued', 'Bandage', 3);
        $q2 = $this->charTask($medkit, 'queued', 'BasicMedKit', 1);
        $q3 = $this->charTask($bandage, 'queued', 'Bandage', 2);

        $duration = new class () extends CraftDurationService {
            public function __construct()
            {
            }

            public function minutesForOne(array|\App\Entities\CharacterEntity $character, array $taskRow, array $recipe = []): int
            {
                return ($taskRow['name'] ?? '') === 'craftBandage' ? 20 : 45;
            }
        };
        $out = (new CraftQueueService(null, $duration))->forCharacter(self::CHAR);

        $this->assertCount(1, $out['active']);
        $left = $out['active'][0]['seconds_left'];
        $this->assertGreaterThan(590, $left);
        $got = array_map(static fn (array $q): array => [$q['charTaskId'], $q['position'], $q['minutes_total'], $q['starts_in_seconds']], $out['queued']);
        $this->assertSame([
            [$q1, 1, 60, $left],
            [$q2, 2, 45, 0],
            [$q3, 3, 40, $left + 60 * 60],
        ], $got);
    }

    /**
     * Web-only игрок (виртуальный id): «📌 Крафт завершён!» и «▶️ Очередь активирована» доходят до
     * `WebDelivery` — две строки входящих, в Telegram не уходит ничего.
     */
    public function testCompletionAndActivationReachWebOnlyInbox(): void
    {
        $this->resetCase();
        WebDelivery::reset();
        service('cache')->save('game_settings_web_play_enabled', ['v' => true, 't' => 'bool'], 60);
        $this->conn->query('UPDATE telegram_users SET telegram_id = ? WHERE id = 7', [VirtualChat::idForAccount(1)]);
        $bandage = $this->taskId('craftBandage');
        $id      = $this->charTask($bandage, 'in_work', 'Bandage', 2);
        $this->charTask($bandage, 'queued', 'Bandage', 3);
        $this->sent = [];

        try {
            (new GenericCraftCompletionHandler())->handle($this->row($id));
        } finally {
            WebDelivery::reset();
        }

        $texts = [];
        foreach ($this->conn->query("SELECT payload FROM web_inbox WHERE character_id = 1 AND source = 'virtual' ORDER BY id")->getResultArray() as $r) {
            $payload = json_decode((string) $r['payload'], true);
            $texts[] = is_array($payload) ? (string) ($payload['text'] ?? '') : '';
        }
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('Крафт завершён', $texts[0]);
        $this->assertStringContainsString('Очередь активирована', $texts[1]);
        $this->assertSame([], $this->sent, 'в Telegram не ушло ничего');
    }

    /** @param array<string, mixed> $stale */
    private function staleCancel(array $stale): CraftQueueService
    {
        return new class ($stale) extends CraftQueueService {
            /** @param array<string, mixed> $stale */
            public function __construct(private array $stale)
            {
                parent::__construct();
            }

            protected function findQueued(int $characterId, int $charTaskId): ?array
            {
                return $this->stale;
            }
        };
    }

    /** @param list<array<string, mixed>> $stale снимки, отдаваемые вместо живого чтения */
    private function stalePromote(array $stale): CraftQueueService
    {
        return new class ($stale) extends CraftQueueService {
            /** @param list<array<string, mixed>> $stale */
            public function __construct(private array $stale)
            {
                parent::__construct();
            }

            protected function nextQueued(int $characterId, int $taskId, int $afterId): ?array
            {
                foreach ($this->stale as $row) {
                    if ((int) $row['id'] > $afterId) {
                        return $row;
                    }
                }

                return parent::nextQueued($characterId, $taskId, $afterId);
            }
        };
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $row = $this->conn->query('SELECT * FROM character_tasks WHERE id = ?', [$id])->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @param list<int> $ids
     * @return array<string, string>
     */
    private function backpack(array $ids): array
    {
        $out = [];
        foreach ($this->state()['resources'] as $r) {
            if (in_array((int) $r['id_resources'], $ids, true)) {
                $out[(string) $r['id_resources']] = (string) $r['quantity'];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function runCase(string $case): array
    {
        $this->resetCase();
        $bandage = $this->taskId('craftBandage');
        $medkit  = $this->taskId('craftBasicMedKit');
        $this->sent = [];
        switch ($case) {
            case 'queue_empty':
                $this->press(new ShowCraftQueueAction($this->cb('craftQueue')));
                break;
            case 'queue_full':
                $this->charTask($bandage, 'in_work', 'Bandage', 1);
                $this->charTask($bandage, 'queued', 'Bandage', 2);
                $this->charTask($medkit, 'queued', 'BasicMedKit', 1);
                $this->press(new ShowCraftQueueAction($this->cb('craftQueue')));
                break;
            case 'cancel_ok':
                $this->charTask($bandage, 'in_work', 'Bandage', 1);
                $id = $this->charTask($bandage, 'queued', 'Bandage', 2);
                $this->press(new CancelQueuedCraftAction($this->cb('cancelQueued_' . $id)));
                break;
            case 'cancel_gone':
                $id = $this->charTask($bandage, 'in_work', 'Bandage', 2);
                $this->press(new CancelQueuedCraftAction($this->cb('cancelQueued_' . $id)));
                break;
            case 'complete':
                $id = $this->charTask($bandage, 'in_work', 'Bandage', 2);
                $this->charTask($bandage, 'queued', 'Bandage', 3);
                $row = $this->conn->query('SELECT * FROM character_tasks WHERE id = ?', [$id])->getRowArray();
                $this->assertIsArray($row);
                (new GenericCraftCompletionHandler())->handle($row);
                break;
        }

        return ['sent' => $this->normalizedSent()] + $this->state();
    }

    /** @return list<array<string, mixed>> время «Завершится в *HH:MM*» зависит от часов — маскируем. */
    private function normalizedSent(): array
    {
        $out = [];
        foreach ($this->sent as $call) {
            foreach ($call as $k => $v) {
                if (is_string($v)) {
                    $call[$k] = (string) preg_replace('/\*\d{2}:\d{2}\*/u', '*HH:MM*', $v);
                }
            }
            $out[] = $call;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $rows = $this->conn->query(
            'SELECT ct.id, ct.status, ct.telegram_user_id, t.name AS task, TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time) AS due, ct.task_settings'
            . ' FROM character_tasks ct LEFT JOIN tasks t ON t.id = ct.task_id ORDER BY ct.id'
        )->getResultArray();

        return [
            'rows'      => $rows,
            'resources' => $this->conn->query('SELECT id_resources, quantity FROM character_resources ORDER BY id_resources')->getResultArray(),
            'storage'   => $this->conn->query('SELECT resource_id, quantity FROM base_storage ORDER BY resource_id')->getResultArray(),
            'items'     => $this->conn->query('SELECT crafted_item_id, quantity FROM crafted_items_log ORDER BY id')->getResultArray(),
            'gold'      => $this->conn->query('SELECT gold FROM characters WHERE id = 1')->getRow('gold'),
        ];
    }

    private function resetCase(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['character_tasks', 'action_log', 'character_resources', 'crafted_items_log', 'claimed_cells', 'game_settings', 'base_storage', 'web_inbox'] as $t) {
            $this->conn->query("TRUNCATE TABLE {$t}");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        foreach ([1 => 20, 2 => 20, 3 => 30, 4 => 8, 5 => 4, 6 => 8, 7 => 22] as $id => $qty) {
            $this->conn->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, ?, ?)', [$id, $qty]);
        }
        $this->conn->query("INSERT INTO crafted_items_log (character_id, crafted_item_id, type, quantity) VALUES (1, 1, 'drug', 7)");
        $this->conn->query('UPDATE characters SET gold = 150 WHERE id = 1');
        service('cache')->clean();
    }

    /** @param array<string, mixed>|null $consumed */
    private function charTask(int $taskId, string $status, string $recipe, int $qty, ?array $consumed = null, int $endIn = 3570): int
    {
        $settings = ['recipe' => $recipe, 'quantity' => $qty];
        if ($consumed !== null) {
            $settings['consumed'] = $consumed;
        }
        $this->conn->query(
            'INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings, created_at, updated_at) VALUES (1, 7, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$taskId, date('Y-m-d H:i:s', time() - 30), $status === 'in_work' ? date('Y-m-d H:i:s', time() + $endIn) : null, $status, json_encode($settings, JSON_UNESCAPED_UNICODE)]
        );

        return (int) $this->conn->insertID();
    }

    private function taskId(string $name): int
    {
        $id = $this->conn->query('SELECT id FROM tasks WHERE name = ?', [$name])->getRow('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    private function cb(string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 77, 'date' => 0, 'chat' => ['id' => self::TG, 'type' => 'private']],
            'data'    => $data,
        ]);
    }

    private function press(ShowCraftQueueAction|CancelQueuedCraftAction $action): void
    {
        $action->handle();
    }

    private function installRecorder(): void
    {
        $this->sent = [];
        new Telegram(self::ENV['telegram.API_KEY'], self::ENV['telegram.BOT_USERNAME']);
        // Мост task-handler'ов при первом подъёме ставит свой HTTP-клиент — поднимаем его до записывающего.
        TelegramBridge::ensure();
        $client = new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path         = explode('/', $request->getUri()->getPath());
            $this->sent[] = ['method' => (string) end($path)] + $params;

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]);
        LongmanRequest::setClient($client);
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'b1', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (6, 6, 5, 0, 1)');
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, 'Тест')", [self::TG]);
        $this->conn->query(
            "INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)"
            . " VALUES (1, 7, 'Тест', 5, 1.5, 90, 10, 0.5, 0.01, 0.01, 150, 6, 1)"
        );
        foreach (self::RESOURCES as $id => $name) {
            $this->conn->query("INSERT INTO resources (id, name, name_en, type, rarity) VALUES (?, ?, ?, 'plant', 1)", [$id, $name, 'r' . $id]);
        }
        $this->conn->query("INSERT INTO crafted_items (id, name_rus, name_eng, type) VALUES (1, 'Повязка', 'Bandage', 'drug')");
        foreach ([
            ['craftBandage', 'Изготовление повязки', 1],
            ['craftBasicMedKit', 'Изготовление аптечки', 0],
        ] as [$name, $rus, $parallel]) {
            $this->conn->query("INSERT INTO tasks (name, name_rus, min_duration, max_duration, type, parallel_execution_allowed) VALUES (?, ?, 10, 30, 'craft', ?)", [$name, $rus, $parallel]);
        }
    }

    private function migration(string $file, ?Forge $forge): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m     = new $class($forge);
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
