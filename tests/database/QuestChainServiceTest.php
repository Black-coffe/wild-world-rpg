<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Quest\QuestChainService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V11 (ADR-036) — QuestChainService: prerequisite-логика цепочек + killswitch.
 * GameSettings из game_settings (как FoodBuffServiceTest).
 *
 * w2-n5-deeds-01: выбор ветки проверяет «сиблинг не выбран» и вставляет под блокировкой строки
 * персонажа — таблица `characters` нужна для `SELECT … FOR UPDATE`.
 *
 * @internal
 */
final class QuestChainServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var (callable(mixed): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL,
                value_string TEXT NULL,
                hard_min VARCHAR(32) NULL,
                hard_max VARCHAR(32) NULL
            )
        ');
        $db->table('game_settings')->insert([
            'setting_key' => 'quests.chains_enabled', 'category' => 'world', 'value_type' => 'bool', 'value_bool' => 1,
        ]);
        // W11 (ADR-067): killswitch branching, default OFF dormant.
        $db->table('game_settings')->insert([
            'setting_key' => 'quests.branching_enabled', 'category' => 'world', 'value_type' => 'bool', 'value_bool' => 0,
        ]);

        // V12: quests + quest_steps для advanceChain. W11: + branch_group/branch_label.
        $db->query('DROP TABLE IF EXISTS quest_steps');
        $db->query('DROP TABLE IF EXISTS quests');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, level INT DEFAULT 1) ENGINE=InnoDB');
        $db->query('INSERT INTO characters (id, level) VALUES (777, 10)');
        $db->query('
            CREATE TABLE quests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title_ru VARCHAR(255) NULL, title_en VARCHAR(255) NULL,
                description TEXT NULL, status VARCHAR(32) NULL, min_level INT NULL,
                reward INT NULL, reward_type VARCHAR(64) NULL,
                prerequisite_quest VARCHAR(255) NULL,
                objective_type VARCHAR(32) NULL, objective_target VARCHAR(100) NULL, objective_qty INT NULL,
                branch_group VARCHAR(64) NULL, branch_label VARCHAR(100) NULL
            )
        ');
        $db->query('
            CREATE TABLE quest_steps (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quest_id INT NOT NULL, character_id INT NOT NULL, step_order INT NOT NULL,
                description TEXT NULL, is_completed TINYINT DEFAULT 0,
                created_at DATETIME NULL, updated_at DATETIME NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            Events::removeListener('DBQuery', $this->listener);
        }
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('DROP TABLE IF EXISTS game_settings');
        $db->query('DROP TABLE IF EXISTS quest_steps');
        $db->query('DROP TABLE IF EXISTS quests');
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

    public function testChainsEnabledDefault(): void
    {
        $this->assertTrue((new QuestChainService())->chainsEnabled());
    }

    public function testPrerequisiteMet(): void
    {
        $svc       = new QuestChainService();
        $completed = ['StrategicCaptureBunker', 'Explore30Cells'];
        // нет предусловия → доступен.
        $this->assertTrue($svc->prerequisiteMet(null, $completed));
        $this->assertTrue($svc->prerequisiteMet('', $completed));
        // предусловие выполнено.
        $this->assertTrue($svc->prerequisiteMet('Explore30Cells', $completed));
        // предусловие НЕ выполнено.
        $this->assertFalse($svc->prerequisiteMet('BunkerStage2', $completed));
        // пустой список завершённых.
        $this->assertFalse($svc->prerequisiteMet('Explore30Cells', []));
    }

    public function testPrerequisiteOf(): void
    {
        $svc = new QuestChainService();
        $this->assertSame('A', $svc->prerequisiteOf(['prerequisite_quest' => 'A']));
        $this->assertNull($svc->prerequisiteOf(['prerequisite_quest' => '']));
        $this->assertNull($svc->prerequisiteOf(['prerequisite_quest' => null]));
        $this->assertNull($svc->prerequisiteOf([]));
    }

    public function testKillswitchDisablesGate(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'quests.chains_enabled')->update(['value_bool' => 0]);
        $this->cleanCache();
        $svc = new QuestChainService();
        $this->assertFalse($svc->chainsEnabled());
        // гейт выключен → даже невыполненное предусловие = доступен.
        $this->assertTrue($svc->prerequisiteMet('BunkerStage2', []));
    }

    // ── V12 (ADR-037): advanceChain ──────────────────────────────────────

    public function testAdvanceChainAssignsNextStage(): void
    {
        $db = Database::connect('tests');
        $db->table('quests')->insert(['title_en' => 'ChainA', 'title_ru' => 'Этап А', 'status' => 'active']);
        $db->table('quests')->insert(['title_en' => 'ChainB', 'title_ru' => 'Этап Б', 'status' => 'active', 'prerequisite_quest' => 'ChainA', 'objective_type' => 'char_level', 'objective_qty' => 5]);
        $bId = (int) $db->insertID();

        $svc = new QuestChainService();
        $advanced = $svc->advanceChain(999, 'ChainA');

        $this->assertSame(['ChainB'], $advanced);
        $step = $db->table('quest_steps')->where('character_id', 999)->where('quest_id', $bId)->get()->getRowArray();
        $this->assertIsArray($step);
        $this->assertSame(0, (int) $step['is_completed']);
    }

    public function testAdvanceChainIdempotent(): void
    {
        $db = Database::connect('tests');
        $db->table('quests')->insert(['title_en' => 'ChainA', 'title_ru' => 'Этап А', 'status' => 'active']);
        $db->table('quests')->insert(['title_en' => 'ChainB', 'title_ru' => 'Этап Б', 'status' => 'active', 'prerequisite_quest' => 'ChainA']);

        $svc = new QuestChainService();
        $svc->advanceChain(999, 'ChainA');
        $second = $svc->advanceChain(999, 'ChainA'); // повтор не дублирует шаг

        $this->assertSame([], $second);
        $count = $db->table('quest_steps')->where('character_id', 999)->countAllResults();
        $this->assertSame(1, $count);
    }

    public function testAdvanceChainNoNextStage(): void
    {
        $db = Database::connect('tests');
        $db->table('quests')->insert(['title_en' => 'Lonely', 'title_ru' => 'Одиночка', 'status' => 'active']);
        $this->assertSame([], (new QuestChainService())->advanceChain(999, 'Lonely'));
    }

    // ── W11 (ADR-067): branching engine ──────────────────────────────────

    /** Включить branching killswitch (с очисткой 60s-кэша GameSettings). */
    private function enableBranching(): void
    {
        Database::connect('tests')->table('game_settings')
            ->where('setting_key', 'quests.branching_enabled')->update(['value_bool' => 1]);
        $this->cleanCache();
    }

    /** Засеять развилку: branch-point ForkPoint + 2 ветки (branch_group=g1). @return array{0:int,1:int} ids веток */
    private function seedFork(): array
    {
        $db = Database::connect('tests');
        $db->table('quests')->insert(['title_en' => 'ForkPoint', 'title_ru' => 'Развилка', 'status' => 'active']);
        $db->table('quests')->insert(['title_en' => 'BranchA', 'title_ru' => 'Ветка А', 'status' => 'active', 'reward' => 1500, 'prerequisite_quest' => 'ForkPoint', 'objective_type' => 'char_level', 'objective_qty' => 3, 'branch_group' => 'g1', 'branch_label' => '🤝 Путь А']);
        $aId = (int) $db->insertID();
        $db->table('quests')->insert(['title_en' => 'BranchB', 'title_ru' => 'Ветка Б', 'status' => 'active', 'reward' => 1500, 'prerequisite_quest' => 'ForkPoint', 'objective_type' => 'char_level', 'objective_qty' => 3, 'branch_group' => 'g1', 'branch_label' => '🎒 Путь Б']);
        $bId = (int) $db->insertID();
        return [$aId, $bId];
    }

    /** Отметить ForkPoint завершённым персонажем (quest_steps is_completed=1). */
    private function completeForkPoint(int $charId): void
    {
        $db   = Database::connect('tests');
        $fork = $db->table('quests')->where('title_en', 'ForkPoint')->get()->getRowArray();
        $db->table('quest_steps')->insert([
            'quest_id' => (int) $fork['id'], 'character_id' => $charId, 'step_order' => 1,
            'description' => 'Развилка', 'is_completed' => 1,
        ]);
    }

    public function testBranchingDisabledByDefault(): void
    {
        $this->assertFalse((new QuestChainService())->branchingEnabled());
    }

    public function testBranchingEnabledAfterFlip(): void
    {
        $this->enableBranching();
        $this->assertTrue((new QuestChainService())->branchingEnabled());
    }

    public function testAdvanceChainSkipsBranchQuests(): void
    {
        // Завершение branch-point НЕ авто-назначает ветки развилки (идут через выбор).
        $this->seedFork();
        $advanced = (new QuestChainService())->advanceChain(777, 'ForkPoint');
        $this->assertSame([], $advanced);
        $count = Database::connect('tests')->table('quest_steps')->where('character_id', 777)->countAllResults();
        $this->assertSame(0, $count);
    }

    public function testPendingBranchOptionsEmptyWhenDisabled(): void
    {
        $this->seedFork();
        // branching OFF → dormant.
        $this->assertSame([], (new QuestChainService())->pendingBranchOptions(777, 'ForkPoint'));
    }

    public function testPendingBranchOptionsWhenEnabled(): void
    {
        $this->seedFork();
        $this->enableBranching();
        $opts = (new QuestChainService())->pendingBranchOptions(777, 'ForkPoint');
        $this->assertCount(2, $opts);
        $labels = array_column($opts, 'label');
        $this->assertContains('🤝 Путь А', $labels);
        $this->assertContains('🎒 Путь Б', $labels);
    }

    public function testPendingBranchOptionsEmptyAfterChoice(): void
    {
        [$aId] = $this->seedFork();
        $this->enableBranching();
        // персонаж уже выбрал ветку А → больше не pending.
        Database::connect('tests')->table('quest_steps')->insert([
            'quest_id' => $aId, 'character_id' => 777, 'step_order' => 1, 'description' => 'А', 'is_completed' => 0,
        ]);
        $this->assertSame([], (new QuestChainService())->pendingBranchOptions(777, 'ForkPoint'));
    }

    public function testChooseBranchHappyPath(): void
    {
        [$aId] = $this->seedFork();
        $this->enableBranching();
        $this->completeForkPoint(777);

        $res = (new QuestChainService())->chooseBranch(777, $aId);
        $this->assertTrue($res['ok']);
        $this->assertSame(1500, $res['reward']);
        $step = Database::connect('tests')->table('quest_steps')
            ->where('character_id', 777)->where('quest_id', $aId)->get()->getRowArray();
        $this->assertIsArray($step);
        $this->assertSame(0, (int) $step['is_completed']);
    }

    public function testChooseBranchRejectsWhenDisabled(): void
    {
        [$aId] = $this->seedFork();
        $this->completeForkPoint(777); // branching НЕ включён
        $res = (new QuestChainService())->chooseBranch(777, $aId);
        $this->assertFalse($res['ok']);
        $this->assertSame('disabled', $res['reason']);
    }

    public function testChooseBranchRejectsWhenPrereqNotMet(): void
    {
        [$aId] = $this->seedFork();
        $this->enableBranching();
        // ForkPoint НЕ завершён персонажем.
        $res = (new QuestChainService())->chooseBranch(777, $aId);
        $this->assertFalse($res['ok']);
        $this->assertSame('prereq_not_met', $res['reason']);
    }

    public function testChooseBranchRejectsSiblingAlreadyChosen(): void
    {
        [$aId, $bId] = $this->seedFork();
        $this->enableBranching();
        $this->completeForkPoint(777);

        $svc = new QuestChainService();
        $first = $svc->chooseBranch(777, $aId);
        $this->assertTrue($first['ok']);
        // вторая ветка той же группы → отказ (выбор необратим).
        $second = $svc->chooseBranch(777, $bId);
        $this->assertFalse($second['ok']);
        $this->assertSame('already_chosen', $second['reason']);
    }

    public function testPendingBranchesForCharacter(): void
    {
        $this->seedFork();
        $this->enableBranching();
        $this->completeForkPoint(777);

        $pending = (new QuestChainService())->pendingBranchesForCharacter(777);
        $this->assertCount(1, $pending);
        $this->assertSame('Развилка', $pending[0]['branch_point_ru']);
        $this->assertCount(2, $pending[0]['options']);
    }

    public function testChoosingTheSameBranchTwiceWritesOneRow(): void
    {
        [$aId] = $this->seedFork();
        $this->enableBranching();
        $this->completeForkPoint(777);

        $svc    = new QuestChainService();
        $first  = $svc->chooseBranch(777, $aId);
        $second = $svc->chooseBranch(777, $aId);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('already_chosen', $second['reason']);
        $this->assertSame(1, Database::connect('tests')->table('quest_steps')->where('character_id', 777)->where('quest_id', $aId)->countAllResults());
    }

    /**
     * «Параллельный выбор» — детерминированно: после блокировки строки персонажа слушатель `DBQuery`
     * ловит проверку «сиблинг выбран?», и второе соединение пытается взять ту же блокировку, как это
     * сделал бы выбор другой ветки из бота или веба. Блокировка держится — второй ждёт, а его выбор после
     * фиксации первого получает already_chosen.
     */
    public function testParallelChoiceOfTheOtherBranchWaitsForTheCharacterLock(): void
    {
        [$aId, $bId] = $this->seedFork();
        $this->enableBranching();
        $this->completeForkPoint(777);

        $blocked = null;
        $this->onQueryAfterLock('FROM `quest_steps`', function () use (&$blocked): void {
            $blocked = $this->characterLockIsHeld(777);
        });

        $first = (new QuestChainService())->chooseBranch(777, $aId);
        $other = (new QuestChainService())->chooseBranch(777, $bId);

        $this->assertTrue($first['ok']);
        $this->assertTrue($blocked, 'во время проверки строка персонажа заблокирована — второй выбор ждёт');
        $this->assertSame('already_chosen', $other['reason']);
        $this->assertSame(0, Database::connect('tests')->table('quest_steps')->where('character_id', 777)->where('quest_id', $bId)->countAllResults());
    }

    public function testBranchRefusalTextsAreTheBotTexts(): void
    {
        $this->assertSame('🔀 Ты уже выбрал путь на этой развилке — назад дороги нет.', QuestChainService::branchRefusalText('already_chosen'));
        $this->assertSame('🔀 Развилки квестов сейчас недоступны.', QuestChainService::branchRefusalText('disabled'));
        $this->assertSame('🔀 Эта развилка ещё не открыта.', QuestChainService::branchRefusalText('prereq_not_met'));
        $this->assertSame('🔀 Эта ветка больше недоступна.', QuestChainService::branchRefusalText('inactive'));
        $this->assertSame('🔀 Не удалось выбрать путь. Попробуй из «Доступных квестов».', QuestChainService::branchRefusalText('not_found'));
    }

    /** Слушатель срабатывает на первом запросе с $fragment после `FOR UPDATE` проверяемого кода. */
    private function onQueryAfterLock(string $fragment, callable $action): void
    {
        $locked         = false;
        $fired          = false;
        $this->listener = static function ($query) use ($fragment, $action, &$locked, &$fired): void {
            $sql = (string) $query->getQuery();
            if (! $locked && str_contains($sql, 'FOR UPDATE')) {
                $locked = true;

                return;
            }
            if ($locked && ! $fired && str_contains($sql, $fragment)) {
                $fired = true;
                $action();
            }
        };
        Events::on('DBQuery', $this->listener);
    }

    /** Второе соединение пытается взять блокировку строки персонажа, как параллельный запрос. */
    private function characterLockIsHeld(int $characterId): bool
    {
        $other = Database::connect('tests', false);
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        $other->transBegin();
        try {
            $res  = $other->query('SELECT id FROM characters WHERE id = ? FOR UPDATE', [$characterId]);
            $held = $res === false && (int) ($other->error()['code'] ?? 0) === 1205;
        } catch (\Throwable $e) {
            $held = str_contains($e->getMessage(), 'Lock wait timeout');
        } finally {
            $other->transRollback();
        }

        return $held;
    }
}
