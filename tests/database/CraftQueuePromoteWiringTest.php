<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\CancelMarchAction;
use App\Controllers\Telegram\Commands\Actions\FinishTaskAction;
use App\Controllers\Worker;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebNativeScreenService;
use App\Services\Web\WebScreenStore;
use App\TaskHandlers\BaseTaskHandler;
use App\TaskHandlers\Contracts\TaskHandlerInterface;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CraftQueue;
use Config\CraftRecipes;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;

/** Завершение 🔒-задачи (добыча): обработчик ничего не делает, как отработавшая добыча. */
final class PromoteWiringGatherProbe extends BaseTaskHandler
{
    public function handle(array $task = []): void
    {
    }
}

/** Обработчик упал: Worker метит задачу `interrupted`. */
final class PromoteWiringThrowingProbe extends BaseTaskHandler
{
    public function handle(array $task = []): void
    {
        throw new \RuntimeException('probe handler failed');
    }
}

/** Обработчик без базы крон-отправителя (только контракт). */
final class PromoteWiringPlainProbe implements TaskHandlerInterface
{
    public function handle(array $task = []): void
    {
    }
}

/** Настоящий Worker, обработчик задачи — заданный пробник. */
final class PromoteWiringWorker extends Worker
{
    public function __construct(private string $probe)
    {
        parent::__construct();
    }

    protected function getHandlerClassName($taskName, ?string $explicitHandlerKey = null)
    {
        return $this->probe;
    }

    /** Один тик по задаче `$charTaskId`: тот же `handleTask()`, что зовёт `processTasks()` (без lock-файла). */
    public function runTask(int $charTaskId): void
    {
        $task = $this->characterTaskModel->find($charTaskId);
        $details = is_array($task) ? $this->taskModel->find($task['task_id']) : null;
        if (is_array($task) && is_array($details)) {
            $this->handleTask($task, $details);
        }
    }
}

/**
 * craft-queue-promote-1125 S2 — продвижение очереди крафта подключено ко всем выходам задачи из работы:
 * Worker (завершение любой задачи, упавший обработчик, обработчик без базы), прерывание «⛔️ Прервать»
 * (`FinishTaskAction`), остановка Похода в боте (`CancelMarchAction`) и в вебе
 * (`WebNativeScreenService::march()`). Игрок получает то же «▶️ Очередь активирована!», что и прежнее
 * продвижение из `GenericCraftCompletionHandler`, одним текстом от любого вызывающего.
 *
 * Отправки идут настоящим путём `App\Services\Telegram\Request` и ловятся захватом `/play`
 * ({@see WebDelivery::beginCapture()}) на чат игрока — в Telegram ничего не уходит.
 *
 * @internal
 */
final class CraftQueuePromoteWiringTest extends CIUnitTestCase
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
        '2026-12-25-100000_S1CharacterTasksExclusiveSlot',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2026-05-25-170000_V20CreateFactionProjects',
        '2026-11-24-100000_CreateCharacterDebuffs',
        '2026-12-11-100001_CreateWebPlayTables',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'tasks', 'character_tasks', 'claimed_cells',
        'buildings', 'character_buildings', 'game_settings', 'factions', 'character_factions', 'faction_projects',
        'character_debuffs', 'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private const CHAR = 1;

    private const CHAT = 555003;

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
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->conn->resetDataCache();
        $this->mockCache();
        WebDelivery::reset();
        WebDelivery::useServices(new WebScreenStore($this->conn));
    }

    protected function tearDown(): void
    {
        TelegramDeliveryProbe::reset();
        WebDelivery::reset();
        $this->dropTables();
        parent::tearDown();
    }

    /**
     * Гонка F4 в Worker: 🔒-крафт застрял в очереди (его рецепта в работе нет), слот держала 🔒-добыча.
     * Worker завершил добычу → крафт в работе, игрок получил одно «Очередь активирована».
     */
    public function testWorkerCompletingAnExclusiveGatherStartsTheStrandedQueuedCraft(): void
    {
        $gather   = $this->row('gatherBlocking', 'in_work', null, -60);
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $sent = $this->captured(fn () => (new PromoteWiringWorker(PromoteWiringGatherProbe::class))->runTask($gather));

        $this->assertSame('completed', $this->statusOf($gather));
        $this->assertSame('in_work', $this->statusOf($queuedId), 'освободившийся слот получил ожидающий крафт');
        $this->assertSame([$this->expectedText($queuedId)], $sent, 'одно сообщение об активации очереди');
    }

    /** Упавший обработчик: задача `interrupted`, слот свободен — очередь всё равно продвигается. */
    public function testWorkerHandlerFailureStillPromotesTheQueue(): void
    {
        $gather   = $this->row('gatherBlocking', 'in_work', null, -60);
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $sent = $this->captured(fn () => (new PromoteWiringWorker(PromoteWiringThrowingProbe::class))->runTask($gather));

        $this->assertSame('interrupted', $this->statusOf($gather), 'обработчик упал — как раньше');
        $this->assertSame('in_work', $this->statusOf($queuedId));
        $this->assertSame([$this->expectedText($queuedId)], $sent);
    }

    /** Обработчик не наследует `BaseTaskHandler` — сообщение уходит через простой экземпляр базы. */
    public function testWorkerHandlerWithoutBaseStillPromotesAndNotifies(): void
    {
        $gather   = $this->row('gatherBlocking', 'in_work', null, -60);
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $sent = $this->captured(fn () => (new PromoteWiringWorker(PromoteWiringPlainProbe::class))->runTask($gather));

        $this->assertSame('in_work', $this->statusOf($queuedId));
        $this->assertSame([$this->expectedText($queuedId)], $sent);
    }

    /** Рецепт ещё в работе — Worker завершает чужую (⏳) задачу, очередь рецепта не трогается и молчит. */
    public function testWorkerLeavesTheQueueOfARunningRecipeAlone(): void
    {
        $gather = $this->row('gatherParallel', 'in_work', null, -60);
        $this->row('craftBasicMedKit', 'in_work', 'BasicMedKit');
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $sent = $this->captured(fn () => (new PromoteWiringWorker(PromoteWiringGatherProbe::class))->runTask($gather));

        $this->assertSame('queued', $this->statusOf($queuedId));
        $this->assertSame([], $sent);
    }

    /** «⛔️ Прервать» идущего крафта R запускает следующий R из очереди и сообщает об этом. */
    public function testFinishTaskInterruptingTheRunningCraftStartsTheNextQueuedOne(): void
    {
        $running  = $this->row('craftBasicMedKit', 'in_work', 'BasicMedKit');
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $sent = $this->captured(fn () => (new FinishTaskAction($this->callbackQuery('finishAllTasks_' . $running)))->handle());

        $this->assertSame('interrupted', $this->statusOf($running));
        $this->assertSame('in_work', $this->statusOf($queuedId), 'прерывание освободило рецепт и слот');
        $this->assertCount(2, $sent, 'итог прерывания и активация очереди');
        $this->assertStringContainsString('прервана', $sent[0]);
        $this->assertSame($this->expectedText($queuedId), $sent[1]);
    }

    /** Остановка Похода в боте освобождает 🔒-слот — ожидающий крафт стартует. */
    public function testCancelMarchStartsTheQueuedCraft(): void
    {
        $march    = $this->row('Marching', 'in_work');
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $sent = $this->captured(fn () => (new CancelMarchAction($this->callbackQuery('cancelMarch')))->handle());

        $this->assertSame('completed', $this->statusOf($march));
        $this->assertSame('in_work', $this->statusOf($queuedId));
        $this->assertContains($this->expectedText($queuedId), $sent);
    }

    /** Остановка Похода на `/play`: крафт стартует, сообщение ложится под экран событием. */
    public function testWebMarchStopStartsTheQueuedCraftAndShowsTheMessage(): void
    {
        $march    = $this->row('Marching', 'in_work');
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $out = (new WebNativeScreenService())->march(77, self::CHAR, WebNativeScreenService::OP_MARCH_STOP, '', 0, 'intent-stop-1');

        $this->assertSame('completed', $this->statusOf($march));
        $this->assertSame('in_work', $this->statusOf($queuedId));
        $this->assertSame([$this->expectedText($queuedId)], array_column($out['events'], 'text'));
    }

    /**
     * S2b — страховочный проход: застрявший 🔒-крафт без задачи, вышедшей из работы (идущий крафт
     * удалён руками), стартует следующим тиком Worker'а, игрок получает одно сообщение.
     */
    public function testWorkerTickStartsAStrandedQueuedCraftWithNoTaskLeavingWork(): void
    {
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $sent = $this->captured(fn () => (new PromoteWiringWorker(PromoteWiringGatherProbe::class))->processTasks());

        $this->assertSame('in_work', $this->statusOf($queuedId), 'тик подобрал застрявшую очередь');
        $this->assertSame([$this->expectedText($queuedId)], $sent, 'одно сообщение об активации очереди');
    }

    /** S2b — рецепт ещё в работе: проход его очередь не трогает и молчит. */
    public function testWorkerTickLeavesTheQueueOfARunningRecipeAlone(): void
    {
        $running  = $this->row('craftBasicMedKit', 'in_work', 'BasicMedKit');
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $writes = $this->writesDuring(fn () => $this->captured(fn () => (new PromoteWiringWorker(PromoteWiringGatherProbe::class))->processTasks()), $sent);

        $this->assertSame('in_work', $this->statusOf($running));
        $this->assertSame('queued', $this->statusOf($queuedId));
        $this->assertSame([], $sent);
        $this->assertSame([], $writes, 'проход не пишет в БД');
    }

    /**
     * S2b — застрявшего нет: пусто, или слот держит чужая 🔒-задача (крафт откажут — проход его и не
     * пробует). Ни записи, ни сообщения.
     */
    public function testWorkerTickDoesNothingWhenNothingIsStranded(): void
    {
        $writes = $this->writesDuring(fn () => $this->captured(fn () => (new PromoteWiringWorker(PromoteWiringGatherProbe::class))->processTasks()), $sent);
        $this->assertSame([], $sent);
        $this->assertSame([], $writes, 'пустая очередь — проход не пишет');

        $gather   = $this->row('gatherBlocking', 'in_work');
        $queuedId = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');

        $writes = $this->writesDuring(fn () => $this->captured(fn () => (new PromoteWiringWorker(PromoteWiringGatherProbe::class))->processTasks()), $sent);

        $this->assertSame('in_work', $this->statusOf($gather));
        $this->assertSame('queued', $this->statusOf($queuedId));
        $this->assertSame([], $sent);
        $this->assertSame([], $writes, '🔒-слот занят — проход не пробует запуск');
    }

    /** S2b — проход ограничен пределом на тик: остальные персонажи стартуют следующими тиками, старшие первыми. */
    public function testWorkerTickSweepIsBoundedPerTick(): void
    {
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (8, 555004, ?)', ['Второй']);
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (2, 8, 'Второй', 99, 1.5, 90, 10, 0.5, 0.5, 0.5, 150, 6, 1)"
        );
        $first  = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit');
        $second = $this->row('craftBasicMedKit', 'queued', 'BasicMedKit', 3570, 2, 8);

        /** @var CraftQueue $cfg */
        $cfg = config('CraftQueue');
        $was = $cfg->sweepCharactersPerTick;
        $cfg->sweepCharactersPerTick = 1;
        try {
            $worker = new PromoteWiringWorker(PromoteWiringGatherProbe::class);
            $this->captured(fn () => $worker->processTasks());
            $this->assertSame('in_work', $this->statusOf($first), 'старшая строка очереди — первой');
            $this->assertSame('queued', $this->statusOf($second), 'предел тика исчерпан');

            $this->captured(fn () => $worker->processTasks());
            $this->assertSame('in_work', $this->statusOf($second), 'следующий тик подобрал остальных');
        } finally {
            $cfg->sweepCharactersPerTick = $was;
        }
    }

    /**
     * Записи в БД (INSERT/UPDATE/DELETE) за время `$run`; `$result` — что `$run` вернул.
     *
     * @return list<string>
     */
    private function writesDuring(callable $run, mixed &$result = null): array
    {
        $writes   = [];
        $listener = static function (Query $q) use (&$writes): void {
            $sql = ltrim($q->getQuery());
            if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1 && !str_contains($sql, 'action_log')) {
                $writes[] = $sql;
            }
        };
        Events::on('DBQuery', $listener);
        try {
            $result = $run();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        return $writes;
    }

    /**
     * Прежний текст продвижения (`GenericCraftCompletionHandler::notifyQueuedActivated`), собранный
     * из рецепта и времени окончания, которое записал старт.
     */
    private function expectedText(int $charTaskId): string
    {
        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get('BasicMedKit');
        $this->assertIsArray($recipe);
        $end = (string) $this->conn->query('SELECT end_time FROM character_tasks WHERE id = ?', [$charTaskId])->getRow('end_time');

        return "▶️ *Очередь активирована!*\n\n"
            . "Запущен крафт: {$recipe['start_caption_name']} x1 шт.\n\n"
            . 'Завершится в *' . date('H:i', (int) strtotime($end)) . '*.';
    }

    /**
     * Тексты сообщений, отправленных в чат игрока за время `$run`.
     *
     * @return list<string>
     */
    private function captured(callable $run): array
    {
        WebDelivery::beginCapture(self::CHAT, self::CHAR);
        try {
            $run();
        } finally {
            $capture = WebDelivery::endCapture();
        }

        return array_values(array_map(static fn (array $m): string => (string) ($m['text'] ?? $m['caption'] ?? ''), $capture['sent']));
    }

    private function callbackQuery(string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'            => 'cbq_' . random_int(1, 999999999),
            'from'          => ['id' => self::CHAT, 'is_bot' => false, 'first_name' => 'Тест'],
            'message'       => [
                'message_id' => 1, 'date' => time(),
                'chat'       => ['id' => self::CHAT, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_' . self::CHAT,
            'data'          => $data,
        ]);
    }

    /** Строка задачи с флагом S1, как её пишет `insertExclusiveTaskRow()`; `$endIn` — секунды до конца. */
    private function row(string $task, string $status, ?string $recipe = null, int $endIn = 3570, int $char = self::CHAR, int $tgUser = 7): int
    {
        $taskId    = $this->taskId($task);
        $exclusive = $this->conn->query('SELECT parallel_execution_allowed FROM tasks WHERE id = ?', [$taskId])->getRow('parallel_execution_allowed');
        $this->conn->query(
            'INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings, is_exclusive, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $char,
                $tgUser,
                $taskId,
                date('Y-m-d H:i:s', time() - 3600),
                $status === 'in_work' ? date('Y-m-d H:i:s', time() + $endIn) : null,
                $status,
                $recipe === null ? null : json_encode(['recipe' => $recipe, 'quantity' => 1]),
                (string) $exclusive === '0' ? 1 : 0,
            ]
        );

        return (int) $this->conn->insertID();
    }

    private function statusOf(int $id): string
    {
        return (string) $this->conn->query('SELECT status FROM character_tasks WHERE id = ?', [$id])->getRow('status');
    }

    private function taskId(string $name): int
    {
        $id = $this->conn->query('SELECT id FROM tasks WHERE name = ?', [$name])->getRow('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'b1', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (6, 6, 5, 0, 1)');
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, ?)', [self::CHAT, 'Тест']);
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 99, 1.5, 90, 10, 0.5, 0.5, 0.5, 150, 6, 1)"
        );
        foreach ([
            ['craftBasicMedKit', 'Изготовление аптечки', 0, 'craft'],
            ['gatherBlocking', 'Добыча (блокирующая)', 0, 'gather'],
            ['gatherParallel', 'Добыча (параллельная)', 1, 'gather'],
            ['Marching', 'Поход', 0, 'explore'],
        ] as [$name, $rus, $parallel, $type]) {
            $this->conn->query(
                'INSERT INTO tasks (name, name_rus, min_duration, max_duration, type, parallel_execution_allowed) VALUES (?, ?, 10, 30, ?, ?)',
                [$name, $rus, $type, $parallel]
            );
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
