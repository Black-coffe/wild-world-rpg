<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Craft\CraftQueueService;
use App\Services\Tasks\ActiveTasksService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * craft-queue-promote-1125 S1 — {@see CraftQueueService::promoteEligible()}: очередь крафта персонажа
 * получает освободившийся 🔒-слот, когда из работы выходит любая его задача, а не только крафт того же
 * рецепта. Гонка F4 (s1-atomicity-1124, Follow-up 7): крафт R завершился, слот до продвижения заняла
 * чужая 🔒-задача → `Refused`, строка R осталась `queued`; прежде её продвигало только следующее
 * завершение R, которого уже нет.
 *
 * Схема — миграциями, включая слот S1 (`is_exclusive`, `uq_ct_exclusive_slot`), как {@see CraftQueueCoreTest}.
 *
 * @internal
 */
final class CraftQueuePromoteEligibleTest extends CIUnitTestCase
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
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'tasks', 'character_tasks', 'claimed_cells',
        'buildings', 'character_buildings', 'game_settings', 'factions', 'character_factions', 'faction_projects',
        'character_debuffs',
    ];

    private const CHAR  = 1;
    private const OTHER = 2;

    private BaseConnection $conn;

    /** @var (callable(mixed): void)|null */
    private $listener = null;

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
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            Events::removeListener('DBQuery', $this->listener);
            $this->listener = null;
        }
        $this->dropTables();
        parent::tearDown();
    }

    /**
     * (a) Гонка F4: 🔒-крафт R в очереди, R в работе нет, слот держит 🔒-добыча. Пока добыча идёт —
     * ничего не стартует; добыча вышла из работы → R стартует, без нового завершения R.
     */
    public function testQueuedExclusiveCraftStartsWhenAnotherExclusiveTaskLeavesWork(): void
    {
        $gather   = $this->row(self::CHAR, 'gatherBlocking', 'in_work');
        $queuedId = $this->row(self::CHAR, 'craftBasicMedKit', 'queued', 'BasicMedKit');

        $this->assertSame([], (new CraftQueueService())->promoteEligible(self::CHAR), 'слот занят — старта нет');
        $this->assertSame('queued', $this->statusOf($queuedId));

        $this->conn->query("UPDATE character_tasks SET status = 'completed' WHERE id = ?", [$gather]);
        $promoted = (new CraftQueueService())->promoteEligible(self::CHAR);

        $this->assertSame([$queuedId], array_column($promoted, 'charTaskId'), 'освободившийся слот получил ожидающий 🔒-крафт');
        $this->assertSame('in_work', $this->statusOf($queuedId));
        $this->assertSame('BasicMedKit', $promoted[0]['recipe']);
        $this->assertNotNull($this->conn->query('SELECT end_time FROM character_tasks WHERE id = ?', [$queuedId])->getRow('end_time'));
    }

    /** (b) Рецепт ещё в работе — его очередь ждёт своего завершения, строку не трогаем. */
    public function testQueueOfARecipeStillInWorkIsNotTouched(): void
    {
        $this->row(self::CHAR, 'craftBandage', 'in_work', 'Bandage');
        $queuedId = $this->row(self::CHAR, 'craftBandage', 'queued', 'Bandage');
        $before   = $this->rows();

        $this->assertSame([], (new CraftQueueService())->promoteEligible(self::CHAR));
        $this->assertSame('queued', $this->statusOf($queuedId));
        $this->assertSame($before, $this->rows());
    }

    /** (c) Два 🔒-рецепта в очереди при пустом слоте — стартует ровно один (старший), второй ждёт. */
    public function testOnlyOneOfTwoEligibleExclusiveRecipesStarts(): void
    {
        $first  = $this->row(self::CHAR, 'craftBasicMedKit', 'queued', 'BasicMedKit');
        $second = $this->row(self::CHAR, 'craftAntivenom', 'queued', 'Antivenom');

        $promoted = (new CraftQueueService())->promoteEligible(self::CHAR);

        $this->assertSame([$first], array_column($promoted, 'charTaskId'));
        $this->assertSame(['in_work', 'queued'], [$this->statusOf($first), $this->statusOf($second)]);
        $this->assertSame(1, $this->exclusiveInWork(self::CHAR));
    }

    /** (d) Параллельный (⏳) крафт в очереди стартует рядом с 🔒-задачей в работе. */
    public function testQueuedParallelCraftStartsNextToAnExclusiveTask(): void
    {
        $this->row(self::CHAR, 'gatherBlocking', 'in_work');
        $bandage = $this->row(self::CHAR, 'craftBandage', 'queued', 'Bandage');
        $medKit  = $this->row(self::CHAR, 'craftBasicMedKit', 'queued', 'BasicMedKit');

        $promoted = (new CraftQueueService())->promoteEligible(self::CHAR);

        $this->assertSame([$bandage], array_column($promoted, 'charTaskId'));
        $this->assertSame(['in_work', 'queued'], [$this->statusOf($bandage), $this->statusOf($medKit)]);
    }

    /** Очередь другого персонажа не трогается. */
    public function testOtherCharactersQueueIsNotTouched(): void
    {
        $other = $this->row(self::OTHER, 'craftBasicMedKit', 'queued', 'BasicMedKit');

        $this->assertSame([], (new CraftQueueService())->promoteEligible(self::CHAR));
        $this->assertSame('queued', $this->statusOf($other));
    }

    /**
     * (e) Два продвижения разом: пока это продвижение выбирает рецепты по снимку, второе (другое
     * соединение) уже ставит второй 🔒-рецепт в работу. Слот не пускает вторую 🔒-строку: в работе
     * ровно одна, проигравшая строка осталась `queued` — видна и отменяется.
     */
    public function testTwoConcurrentPromotersNeverStartTwoExclusiveRows(): void
    {
        $first  = $this->row(self::CHAR, 'craftBasicMedKit', 'queued', 'BasicMedKit');
        $second = $this->row(self::CHAR, 'craftAntivenom', 'queued', 'Antivenom');

        $fired          = false;
        $this->listener = function ($query) use (&$fired, $second): void {
            if ($fired || ! $query instanceof Query) {
                return;
            }
            $sql = $query->getQuery();
            if (str_contains($sql, 'character_tasks') && str_contains($sql, 'queued') && ! str_contains($sql, 'UPDATE')) {
                $fired = true;
                $out   = (new ActiveTasksService(Database::connect(null, false)))->moveToInWork($second, 'queued', [
                    'start_time' => date('Y-m-d H:i:s'),
                    'end_time'   => date('Y-m-d H:i:s', time() + 600),
                ]);
                $this->assertSame(WriteOutcome::Applied, $out, 'второе продвижение заняло слот');
            }
        };
        Events::on('DBQuery', $this->listener);

        $promoted = (new CraftQueueService())->promoteEligible(self::CHAR);

        $this->assertTrue($fired, 'чтение очереди не встретилось — вклинивания не было');
        $this->assertSame([], $promoted, 'слот уже занят вторым продвижением');
        $this->assertSame(1, $this->exclusiveInWork(self::CHAR));
        $this->assertSame(['queued', 'in_work'], [$this->statusOf($first), $this->statusOf($second)]);
    }

    /**
     * Ревью, раунд 1: два продвижения разом по ⏳-рецепту с двумя строками в очереди. Это продвижение
     * выбрало рецепт по снимку и прочитало голову `queued`, а второе (другое соединение) уже запустило
     * её. Голова — `Missing`, но рецепт теперь в работе: вторая строка ждёт своего завершения, FIFO
     * рецепта не идёт в две строки разом.
     */
    public function testConcurrentPromoterDoesNotStartASecondRowOfARunningParallelRecipe(): void
    {
        $head = $this->row(self::CHAR, 'craftBandage', 'queued', 'Bandage');
        $tail = $this->row(self::CHAR, 'craftBandage', 'queued', 'Bandage');

        $fired          = false;
        $this->listener = function ($query) use (&$fired, $head): void {
            if ($fired || ! $query instanceof Query) {
                return;
            }
            $sql = $query->getQuery();
            if (str_contains($sql, 'character_tasks') && str_contains($sql, 'queued') && str_contains($sql, '`id` >')
                && ! str_contains($sql, 'UPDATE') && ! str_contains($sql, 'NOT EXISTS')) {
                $fired = true;
                $out   = (new ActiveTasksService(Database::connect(null, false)))->moveToInWork($head, 'queued', [
                    'start_time' => date('Y-m-d H:i:s'),
                    'end_time'   => date('Y-m-d H:i:s', time() + 600),
                ]);
                $this->assertSame(WriteOutcome::Applied, $out, 'второе продвижение запустило голову');
            }
        };
        Events::on('DBQuery', $this->listener);

        $promoted = (new CraftQueueService())->promoteEligible(self::CHAR);

        $this->assertTrue($fired, 'чтение головы очереди не встретилось — вклинивания не было');
        $this->assertSame([], $promoted, 'голову уже запустило второе продвижение');
        $this->assertSame(['in_work', 'queued'], [$this->statusOf($head), $this->statusOf($tail)]);
    }

    /** Строка задачи с флагом S1, как её пишет `insertExclusiveTaskRow()`. */
    private function row(int $characterId, string $task, string $status, ?string $recipe = null): int
    {
        $taskId    = $this->taskId($task);
        $exclusive = $this->conn->query('SELECT parallel_execution_allowed FROM tasks WHERE id = ?', [$taskId])->getRow('parallel_execution_allowed');
        $this->conn->query(
            'INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings, is_exclusive, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $characterId,
                $characterId === self::CHAR ? 7 : 8,
                $taskId,
                date('Y-m-d H:i:s', time() - 30),
                $status === 'in_work' ? date('Y-m-d H:i:s', time() + 3570) : null,
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

    private function exclusiveInWork(int $characterId): int
    {
        return (int) $this->conn->query(
            "SELECT COUNT(*) AS n FROM character_tasks WHERE character_id = ? AND status = 'in_work' AND is_exclusive = 1",
            [$characterId]
        )->getRow('n');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->conn->query('SELECT id, task_id, status, start_time, end_time FROM character_tasks ORDER BY id')->getResultArray();
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
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 555003, 'Тест'), (8, 555004, 'Другой')");
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 5, 1.5, 90, 10, 0.5, 0.01, 0.01, 150, 6, 1), (2, 8, 'Другой', 5, 1.5, 90, 10, 0.5, 0.01, 0.01, 150, 6, 1)"
        );
        foreach ([
            ['craftBandage', 'Изготовление повязки', 1, 'craft'],
            ['craftBasicMedKit', 'Изготовление аптечки', 0, 'craft'],
            ['craftAntivenom', 'Изготовление противоядия', 0, 'craft'],
            ['gatherBlocking', 'Добыча (блокирующая)', 0, 'gather'],
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
