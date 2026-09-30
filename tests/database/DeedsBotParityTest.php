<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\EventAction;
use App\Controllers\Telegram\Commands\Actions\Quest\ActiveQuests;
use App\Controllers\Telegram\Commands\Actions\Quest\AvailableQuests;
use App\Controllers\Telegram\Commands\Actions\Quest\CompletedQuests;
use App\Controllers\Telegram\Commands\Actions\TasksHubAction;
use App\Services\Tasks\TasksSurfaceService;
use App\Services\Web\VirtualChat;
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
 * w2-n5-deeds-02 — паритет бота: хаб «📋 Дела», три списка квестов и «🎉 События» рисуются из моделей
 * ядра (`TasksSurfaceService` / `QuestListService` / `EventsModelService`) тем же текстом и теми же
 * кнопками, что до переноса. Ожидания сняты со старых handler'ов: тот же тест зелёный и на коде до
 * изменения (снимок «до»), и после.
 *
 * Отдельный процесс: соседние тесты определяют `PHPUNIT_TESTSUITE`, и Longman под ним отвечает фейком.
 * Схема — прогоном миграций.
 *
 * @internal
 */
final class DeedsBotParityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
        '2024-04-26-121416_CreateQuestsTable',
        '2024-04-26-192334_CreateQuestStepsTable',
        '2026-05-21-210000_V11AddQuestPrerequisite',
        '2026-05-21-230000_V12AddQuestObjectiveColumns',
        '2026-06-04-100000_W11AddQuestBranchColumns',
        '2026-06-14-100000_AddFactionIdToQuests',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2024-04-04-090501_CreateActiveEventsTable',
        '2026-05-05-150000_AddEffectLogToActiveEvents',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'tasks', 'character_tasks', 'quests', 'quest_steps',
        'factions', 'character_factions', 'events', 'active_events', 'game_settings',
    ];

    private const TG = 771000005;

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
            // `events` — вручную: миграция 2024-04-04 CreateEventsTable не проходит на MySQL 8 (`img_path TEXT`
            // с default), прод несёт таблицу со времён старого сервера. Колонки — те, что читает экран.
            $this->conn->query(
                'CREATE TABLE events (event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_english VARCHAR(255) NULL,'
                . " description TEXT NULL, biome_ids TEXT NULL, event_type ENUM('local','global') NOT NULL, effect_type ENUM('damage','heal','buff','debuff','none') NOT NULL)"
            );
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach (['navigation.tasks_hub.enabled', 'quests.extended_enabled', 'quests.branching_enabled', 'quests.chains_enabled'] as $flag) {
            $this->setFlag($flag, true);
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
    public function testHubAndQuestListsAreUnchanged(): void
    {
        $back = ['📜 Квесты и задания', 'questAndTask', '◀️ Я', 'character'];

        $hub = $this->edited(TasksHubAction::class, 'tasksHub');
        $this->assertSame(
            "📋 *Дела* — что идёт сейчас и что делать дальше.\n\n"
            . "⏳ *Идёт сейчас (1):*\n1) *Рубка леса* — осталось `2 ч 0 мин`\n\n"
            . "_Задача завершится сама — награда придёт отдельным сообщением._\n\n"
            . "🔀 *Развилка цепочки ждёт выбор!* Открой «Квесты» — решение необратимо.\n\n"
            . "📜 *Квесты:* активных *1*, доступно *1* _(🔒 ещё 1 по цепочке)_\n",
            $hub['text']
        );
        $this->assertSame(json_encode(['inline_keyboard' => [
            // Одиночные ряды склеивает нормализатор MediaSender — снимок «до» именно такой.
            [['text' => '⛔️ 1', 'callback_data' => 'finishAllTasks_40'], ['text' => '📜 Квесты (1)', 'callback_data' => 'questAndTask']],
            [['text' => '🔄 Обновить', 'callback_data' => 'tasksHub'], ['text' => '🧭 Идти', 'callback_data' => 'move']],
        ]]), $hub['reply_markup']);

        $active = $this->edited(ActiveQuests::class, 'activeQuests');
        $this->assertSame("*🚀 Активные квесты:*\n\n🔹 *Старый путь* || Награда: *200* (_опыт_)\n", $active['text']);
        $this->assertSame($this->keyboard([$back]), $active['reply_markup']);

        $completed = $this->edited(CompletedQuests::class, 'completedQuests');
        $this->assertSame("*🏅 Завершенные квесты:*\n\n🔹 *Первые шаги* || Награда: *100* (_золото_)\n", $completed['text']);
        $this->assertSame($this->keyboard([$back]), $completed['reply_markup']);

        $available = $this->edited(AvailableQuests::class, 'availableQuests');
        $this->assertSame(
            "*📜 Доступные квесты:*\n\n"
            . "*🔀 Развилка цепочки!* Выбери путь — решение необратимо:\n\n_После «Первые шаги»:_\n• 🤝 Путь А\n• 🎒 Путь Б\n\n"
            . "🔹 *Запас дров* || Награда: *300* (_золото_)\n"
            . "\n*🔒 Откроются позже (цепочка):*\n🔒 *Тайник* — после квеста «Неведомое»\n"
            . "\nВыбери квест и отправляйся к приключениям!",
            $available['text']
        );
        // Одиночный ряд квеста нормализатор MediaSender доклеивает к ряду веток — снимок «до» именно такой.
        $this->assertSame($this->keyboard([
            ['🤝 Путь А', 'questBranch_7', '🎒 Путь Б', 'questBranch_8', 'Запас дров', 'questStartCollectWood'],
            $back,
        ]), $available['reply_markup']);
    }

    /**
     * Персонаж веб-моста (виртуальный id) находит свои списки: персонаж ищется по отправителю (`from.id`),
     * а не по `chat_id`. Чат в тесте — обычный id без строки `telegram_users`: отправки в виртуальный чат
     * гасит `VirtualChatGuardMiddleware`, а старый поиск по `chat_id` на таком чате персонажа не находил.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListsFindTheCharacterOfAVirtualBridgeChat(): void
    {
        $virtual = VirtualChat::idForAccount(5);
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (9, ?, 'Веб')", [$virtual]);
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (3, 9, 'Веб', 1, 0, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
        );
        $this->conn->query("INSERT INTO quest_steps (quest_id, character_id, step_order, description, is_completed) VALUES (3, 3, 1, 'x', 1)");

        $completed = $this->edited(CompletedQuests::class, 'completedQuests', $virtual, 881000001);
        $this->assertSame("*🏅 Завершенные квесты:*\n\n🔹 *Первые шаги* || Награда: *100* (_золото_)\n", $completed['text']);
        $active = $this->edited(ActiveQuests::class, 'activeQuests', $virtual, 881000001);
        $this->assertSame('На данный момент у вас нет активных квестов.', $active['text']);
    }

    /** Модель хаба для веба: задачи с `ends_at`, та же сводка, что у бот-экрана, без Markdown. */
    public function testHubModelCarriesEndsAtAndTheSameSummary(): void
    {
        $model = (new TasksSurfaceService())->model(1);

        $this->assertCount(1, $model['tasks']);
        $this->assertSame('Рубка леса', $model['tasks'][0]['name']);
        $this->assertSame(40, $model['tasks'][0]['id']);
        $this->assertNotNull($model['tasks'][0]['ends_at']);
        $this->assertSame(1, $model['summary']['active']);
        $this->assertSame(1, $model['summary']['available']);
        $this->assertSame(1, $model['summary']['locked']);
        $this->assertSame(1, $model['summary']['branches']);
        $this->assertFalse($model['summary']['daily']['enabled']);
        $this->assertSame([], $model['daily']);
        $this->assertNull($model['polar_star']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEventsScreenIsUnchanged(): void
    {
        $sent = $this->press(EventAction::class, 'events', self::TG);
        $msg  = $this->only($sent, 'sendMessage');

        $this->assertSame(
            "🌟 *Сейчас в мире происходят события:* 🌟\n\n"
            . "№1 *Кислотный дождь*\n📜 _Жжёт кожу._\n🌍 *Где:* _Выборочно в указанных биомах_\n🌱 *Биомы:* Лес\n"
            . "🚀 *Эффект:* _Урон_\n⏳ *Закончится через:* _1 дн. 02 чс. 05 мин._\n🎯 _Тебя уже коснулось._\n\n"
            . "━━━━━━━━━━━━\n📜 *Последние прошедшие события:*\n\n"
            . "▫️ *Туман*\n🕘 Начало: _20 сентября, 17:50_\n🏁 Конец: _20 сентября, 18:13_\n⏱ Длилось: _23 мин._\n🟢 _Тебя не коснулось._\n\n"
            . "▫️ *Неизвестное событие*\n🕘 Начало: _19 сентября, 10:00_\n🏁 Конец: _19 сентября, 11:30_\n⏱ Длилось: _1 ч 30 мин._\n🟢 _Тебя не коснулось._\n\n",
            $msg['text'] ?? null
        );
        $this->assertSame(json_encode([
            'inline_keyboard' => [
                [['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'], ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']],
                [['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'], ['text' => '🛒 Магазин', 'callback_data' => 'shop'], ['text' => '🎉 События', 'callback_data' => 'events']],
            ],
        ]), $msg['reply_markup'] ?? null);
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $now = time();
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, ?)', [self::TG, 'Тест']);
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
        );
        $this->conn->query("INSERT INTO tasks (id, name, name_rus) VALUES (3, 'ChopWood', 'Рубка леса')");
        $this->conn->query(
            "INSERT INTO character_tasks (id, character_id, task_id, start_time, end_time, status, created_at, updated_at) VALUES (40, 1, 3, ?, ?, 'in_work', NOW(), NOW())",
            [date('Y-m-d H:i:s', $now - 60), date('Y-m-d H:i:s', $now + 7200 + 30)]
        );

        $this->conn->query('DELETE FROM quests');
        $this->conn->query(
            'INSERT INTO quests (id, title_ru, title_en, description, status, min_level, reward, reward_type, objective_type, objective_target, objective_qty, prerequisite_quest, branch_group, branch_label) VALUES'
            . " (1, 'Запас дров', 'CollectWood', 'Принеси дерево.', 'active', 5, 300, 'gold', 'collect_resource', 'wood', 10, NULL, NULL, NULL),"
            . " (2, 'Старый путь', 'OldPath', 'Иди.', 'active', 1, 200, 'experience', NULL, NULL, NULL, NULL, NULL, NULL),"
            . " (3, 'Первые шаги', 'FirstSteps', 'Начни.', 'active', 1, 100, 'gold', NULL, NULL, NULL, NULL, NULL, NULL),"
            . " (4, 'Тайник', 'Stash', 'Найди.', 'active', 1, 50, 'gold', NULL, NULL, NULL, 'Nowhere', NULL, NULL),"
            . " (6, 'Неведомое', 'Nowhere', 'Нет.', 'inactive', 1, 0, 'gold', NULL, NULL, NULL, NULL, NULL, NULL),"
            . " (7, 'Ветка А', 'BranchA', 'А.', 'active', 1, 1500, 'gold', 'char_level', NULL, 3, 'FirstSteps', 'g1', '🤝 Путь А'),"
            . " (8, 'Ветка Б', 'BranchB', 'Б.', 'active', 1, 1500, 'gold', 'char_level', NULL, 3, 'FirstSteps', 'g1', '🎒 Путь Б')"
        );
        $this->conn->query(
            "INSERT INTO quest_steps (quest_id, character_id, step_order, description, is_completed) VALUES (2, 1, 1, 'x', 0), (3, 1, 1, 'x', 1)"
        );

        $this->conn->query(
            'INSERT INTO events (event_id, name, name_english, description, biome_ids, event_type, effect_type) VALUES'
            . " (1, 'Кислотный дождь', 'AcidRain', 'Жжёт кожу.', '[1]', 'local', 'damage'),"
            . " (2, 'Туман', 'Fog', 'Ничего не видно.', NULL, 'global', 'none')"
        );
        $this->conn->query(
            'INSERT INTO active_events (event_id, start_time, end_time, status, effect_log) VALUES'
            . " (1, ?, ?, 'active', '{\"1\": 5}'),"
            . " (2, '2026-09-20 17:50:00', '2026-09-20 18:13:00', 'completed', NULL),"
            . " (99, '2026-09-19 10:00:00', '2026-09-19 11:30:00', 'completed', NULL)",
            [date('Y-m-d H:i:s', $now - 600), date('Y-m-d H:i:s', $now + 86400 + 7200 + 300 + 30)]
        );
    }

    /**
     * @param list<list<string>> $rows по парам «текст, callback_data»
     */
    private function keyboard(array $rows): string|false
    {
        $out = [];
        foreach ($rows as $row) {
            $buttons = [];
            foreach (array_chunk($row, 2) as [$text, $data]) {
                $buttons[] = ['text' => $text, 'callback_data' => $data];
            }
            $out[] = $buttons;
        }

        return json_encode(['inline_keyboard' => $out]);
    }

    /** @return array{text: mixed, reply_markup: mixed} */
    private function edited(string $actionClass, string $data, int $fromId = self::TG, ?int $chatId = null): array
    {
        $edit = $this->only($this->press($actionClass, $data, $fromId, $chatId), 'editMessageText');

        return ['text' => $edit['text'] ?? null, 'reply_markup' => $edit['reply_markup'] ?? null];
    }

    /** @return list<array<string, mixed>> */
    private function press(string $actionClass, string $data, int $fromId, ?int $chatId = null): array
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
            'from'    => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => $chatId ?? $fromId, 'type' => 'private'], 'text' => 'x'],
            'chat_instance' => 'ci', 'data' => $data,
        ]);
        $action = new $actionClass($cbq);
        $this->assertTrue(method_exists($action, 'handle'));
        $action->handle();

        return $sent;
    }

    /**
     * @param list<array<string, mixed>> $sent
     * @return array<string, mixed>
     */
    private function only(array $sent, string $method): array
    {
        $calls = array_values(array_filter($sent, static fn (array $c): bool => $c['method'] === $method));
        $this->assertCount(1, $calls, $method . ' in ' . json_encode(array_column($sent, 'method')));

        return $calls[0];
    }

    private function setFlag(string $key, bool $on): void
    {
        $this->conn->query('DELETE FROM game_settings WHERE setting_key = ?', [$key]);
        $now = date('Y-m-d H:i:s');
        $this->conn->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'bool', 'value_bool' => $on ? 1 : 0,
            'default_value_text' => '0', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ]);
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
