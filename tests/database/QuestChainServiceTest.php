<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Quest\QuestChainService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V11 (ADR-036) — QuestChainService: prerequisite-логика цепочек + killswitch.
 * GameSettings из game_settings (как FoodBuffServiceTest).
 *
 * @internal
 */
final class QuestChainServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

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
        $db = Database::connect('tests');
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
}
