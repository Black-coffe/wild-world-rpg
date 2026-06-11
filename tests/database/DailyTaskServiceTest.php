<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Quest\DailyTaskService;
use App\TaskHandlers\Quests\DailyTaskProgressHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-109 (E8) — DailyTaskService (counter/ensureAssigned/today) + DailyTaskProgressHandler.
 * Дата фиксирована через сабкласс (currentDate) → тесты time-stable.
 *
 * @internal
 */
final class DailyTaskServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    public const DATE = '2026-06-11';
    private const TABLES = [
        'character_daily_tasks', 'explored_cells', 'crafted_items_log', 'action_log',
        'battle_logs', 'characters', 'telegram_users', 'game_settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE character_daily_tasks (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, task_date DATE, slot TINYINT, task_key VARCHAR(64), objective_type VARCHAR(32), objective_qty INT DEFAULT 1, baseline INT DEFAULT 0, reward_gold INT DEFAULT 0, reward_resource_id INT NULL, reward_resource_qty INT DEFAULT 0, is_completed TINYINT DEFAULT 0, completed_at DATETIME NULL, created_at DATETIME NULL)');
        $db->query('CREATE TABLE explored_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, created_at DATETIME NULL)');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, crafted_item_id INT NULL, quantity INT DEFAULT 1)');
        $db->query('CREATE TABLE action_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, chat_id BIGINT NULL, action_name VARCHAR(255), action_status VARCHAR(20), description TEXT NULL, created_at DATETIME NULL)');
        $db->query('CREATE TABLE battle_logs (id INT AUTO_INCREMENT PRIMARY KEY, winner_id INT NULL, created_at DATETIME NULL)');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, telegram_user_id INT NULL, level INT DEFAULT 1, npc_kills INT DEFAULT 0, gold DECIMAL(14,2) DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE telegram_users (id INT PRIMARY KEY, telegram_id BIGINT NULL)');
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');

        $db->table('characters')->insert(['id' => 1, 'telegram_user_id' => 10, 'level' => 5, 'npc_kills' => 4, 'gold' => 1000]);
        $db->table('telegram_users')->insert(['id' => 10, 'telegram_id' => 999]);

        // Монотонные счётчики: explore=3, craft=2, sell=1, npc_kills=4, battle_win=2.
        $db->table('explored_cells')->insertBatch([['character_id' => 1], ['character_id' => 1], ['character_id' => 1]]);
        $db->table('crafted_items_log')->insertBatch([['character_id' => 1, 'quantity' => 1], ['character_id' => 1, 'quantity' => 1]]);
        $db->table('action_log')->insert(['character_id' => 1, 'action_name' => 'SELL_RESOURCE', 'action_status' => 'Completed']);
        $db->table('battle_logs')->insertBatch([['winner_id' => 1], ['winner_id' => 1]]);

        $this->seedBool('quests.daily.enabled', 1);
        $this->seedInt('quests.daily.count', 3);
        $this->seedFloat('quests.daily.reward_multiplier', 1.0);
        $this->seedInt('quests.daily.all_done_bonus_gold', 500);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    private function seedBool(string $k, int $v): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $k, 'value_type' => 'bool', 'value_bool' => $v]);
    }

    private function seedInt(string $k, int $v): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $k, 'value_type' => 'int', 'value_int' => $v]);
    }

    private function seedFloat(string $k, float $v): void
    {
        Database::connect('tests')->table('game_settings')->insert(['setting_key' => $k, 'value_type' => 'float', 'value_float' => $v]);
    }

    private function svc(): DailyTaskService
    {
        return new class extends DailyTaskService {
            public function currentDate(): string
            {
                return DailyTaskServiceTest::DATE;
            }
        };
    }

    public function testCounterPerType(): void
    {
        $s = $this->svc();
        $this->assertSame(3, $s->counter(1, 'd_explore'));
        $this->assertSame(2, $s->counter(1, 'd_craft'));
        $this->assertSame(1, $s->counter(1, 'd_sell'));
        $this->assertSame(4, $s->counter(1, 'd_npc_kill'));
        $this->assertSame(2, $s->counter(1, 'd_battle_win'));
        $this->assertSame(0, $s->counter(1, 'unknown'));
    }

    public function testEnsureAssignedGeneratesThreeWithBaseline(): void
    {
        $char = ['id' => 1, 'level' => 5];
        $this->assertTrue($this->svc()->ensureAssigned($char));

        $rows = Database::connect('tests')->table('character_daily_tasks')
            ->where('character_id', 1)->where('task_date', self::DATE)->orderBy('slot')->get()->getResultArray();
        $this->assertCount(3, $rows);

        // baseline снят на момент назначения (= текущему счётчику типа).
        $byType = array_column($rows, 'baseline', 'objective_type');
        $expected = ['d_explore' => 3, 'd_craft' => 2, 'd_sell' => 1, 'd_npc_kill' => 4, 'd_battle_win' => 2];
        foreach ($rows as $r) {
            $this->assertSame((int) $expected[$r['objective_type']], (int) $r['baseline']);
            $this->assertGreaterThan(0, (int) $r['reward_gold']);
        }
    }

    public function testEnsureAssignedIdempotentPerDay(): void
    {
        $char = ['id' => 1, 'level' => 5];
        $this->svc()->ensureAssigned($char);
        $this->svc()->ensureAssigned($char); // повтор — без дублей

        $n = Database::connect('tests')->table('character_daily_tasks')
            ->where('character_id', 1)->where('task_date', self::DATE)->countAllResults();
        $this->assertSame(3, $n);
    }

    public function testKillswitchOffNoAssign(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'quests.daily.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();

        $this->assertFalse($this->svc()->ensureAssigned(['id' => 1, 'level' => 5]));
        $this->assertSame(0, Database::connect('tests')->table('character_daily_tasks')->countAllResults());
    }

    public function testTodayProgressDeltaCapped(): void
    {
        // Задание explore qty=5, baseline=3 (текущий explore=3). Прогресс 0.
        Database::connect('tests')->table('character_daily_tasks')->insert([
            'character_id' => 1, 'task_date' => self::DATE, 'slot' => 1, 'task_key' => 'd_explore',
            'objective_type' => 'd_explore', 'objective_qty' => 5, 'baseline' => 3, 'reward_gold' => 80, 'is_completed' => 0,
        ]);
        $this->assertSame(0, $this->svc()->today(1)[0]['progress']);

        // +4 клетки → explore=7, delta=4, cap 5 → progress 4.
        Database::connect('tests')->table('explored_cells')->insertBatch([
            ['character_id' => 1], ['character_id' => 1], ['character_id' => 1], ['character_id' => 1],
        ]);
        $this->assertSame(4, $this->svc()->today(1)[0]['progress']);
    }

    private function makeHandler(DailyTaskService $svc): DailyTaskProgressHandler
    {
        return new class($svc) extends DailyTaskProgressHandler {
            /** @var list<string> */
            public array $sent = [];

            protected function sendNotice(int $charId, string $text): void
            {
                $this->sent[] = $text;
            }
        };
    }

    public function testHandlerCompletesTaskAndGrantsGoldAndAllDoneBonus(): void
    {
        $svc = $this->svc();
        // Один дейлик (count-сценарий): explore qty 5, baseline 3, reward 80.
        Database::connect('tests')->table('character_daily_tasks')->insert([
            'character_id' => 1, 'task_date' => self::DATE, 'slot' => 1, 'task_key' => 'd_explore',
            'objective_type' => 'd_explore', 'objective_qty' => 5, 'baseline' => 3, 'reward_gold' => 80, 'is_completed' => 0,
        ]);
        // Догоняем счётчик до delta≥5: +5 клеток → explore=8, delta=5.
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = ['character_id' => 1];
        }
        Database::connect('tests')->table('explored_cells')->insertBatch($batch);

        $h = $this->makeHandler($svc);
        $h->handle();

        $task = Database::connect('tests')->table('character_daily_tasks')->where('slot', 1)->get()->getRowArray();
        $this->assertSame(1, (int) $task['is_completed']);

        // Золото: +80 (задание) +500 (все задания дня, т.к. оно единственное) = 1580.
        $gold = (float) Database::connect('tests')->table('characters')->where('id', 1)->get()->getRowArray()['gold'];
        $this->assertEqualsWithDelta(1580.0, $gold, 0.001);

        // One-shot флаг бонуса записан.
        $flag = Database::connect('tests')->table('action_log')
            ->where('character_id', 1)->like('action_name', 'DailyAllDone_', 'after')->countAllResults();
        $this->assertSame(1, $flag);

        // Повторный прогон — без двойной награды (задание completed, флаг есть).
        $this->makeHandler($svc)->handle();
        $gold2 = (float) Database::connect('tests')->table('characters')->where('id', 1)->get()->getRowArray()['gold'];
        $this->assertEqualsWithDelta(1580.0, $gold2, 0.001);
    }

    public function testHandlerKillswitchOffNoOp(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'quests.daily.enabled')->update(['value_bool' => 0]);
        $this->cleanCache();
        Database::connect('tests')->table('character_daily_tasks')->insert([
            'character_id' => 1, 'task_date' => self::DATE, 'slot' => 1, 'task_key' => 'd_explore',
            'objective_type' => 'd_explore', 'objective_qty' => 1, 'baseline' => 0, 'reward_gold' => 80, 'is_completed' => 0,
        ]);

        $h = $this->makeHandler($this->svc());
        $h->handle();

        $task = Database::connect('tests')->table('character_daily_tasks')->where('slot', 1)->get()->getRowArray();
        $this->assertSame(0, (int) $task['is_completed']);
        $this->assertCount(0, $h->sent);
    }
}
