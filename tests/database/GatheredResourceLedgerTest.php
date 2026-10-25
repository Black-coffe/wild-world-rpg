<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\ReseedOnbStepGatherObjective;
use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\PolarStarService;
use App\Services\Player\Gather\GatherResultPersister;
use App\Services\Quest\GatheredResourceLedger;
use App\TaskHandlers\Quests\QuestObjectiveHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W-REG F6 — шаг «Дары пустоши» считает ДОБЫТУЮ воду, а не воду в рюкзаке.
 *
 * Паёк (10 воды в character_resources) цель не закрывает и «🎯 Сейчас» = 0/5; отметки добычи
 * пишет GatherResultPersister только при активной цели gather_resource; ресид шага идемпотентен.
 *
 * @internal
 */
final class GatheredResourceLedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const WATER_ID = 7;
    private const CHAR     = 1;
    private const VETERAN  = 2;

    private const TABLES = ['quests', 'quest_steps', 'action_log', 'resources', 'character_resources', 'telegram_users'];

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2026-10-25-120000_ReseedOnbStepGatherObjective.php';
        $db = Database::connect('tests');

        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query("CREATE TABLE quests (id INT AUTO_INCREMENT PRIMARY KEY, title_en VARCHAR(255) NULL, title_ru VARCHAR(255) NULL,
                    description TEXT NULL, status VARCHAR(20) DEFAULT 'active', reward INT DEFAULT 0, faction_id INT NULL,
                    objective_type VARCHAR(32) NULL, objective_target VARCHAR(64) NULL, objective_qty INT NULL)");
        $db->query('CREATE TABLE quest_steps (id INT AUTO_INCREMENT PRIMARY KEY, quest_id INT, character_id INT,
                    is_completed TINYINT DEFAULT 0, created_at DATETIME NULL)');
        $db->query("CREATE TABLE action_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, chat_id BIGINT NULL,
                    action_name VARCHAR(255) NOT NULL, action_status ENUM('Pending','Completed','Skipped','REJECTED') NOT NULL,
                    description TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)");
        $db->query('CREATE TABLE resources (id INT PRIMARY KEY, name VARCHAR(100), name_en VARCHAR(100))');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT,
                    id_telegram_users INT NULL, quantity INT DEFAULT 0)');
        $db->query('CREATE TABLE telegram_users (id INT PRIMARY KEY, telegram_id BIGINT NULL)');

        $db->table('resources')->insertBatch([
            ['id' => self::WATER_ID, 'name' => 'Вода', 'name_en' => 'Water'],
            ['id' => 8, 'name' => 'Дерево', 'name_en' => 'Wood'],
        ]);
        $db->table('telegram_users')->insert(['id' => 25, 'telegram_id' => 555]);

        // Квест-шаг в том виде, в каком его оставит ресид-миграция.
        $db->table('quests')->insert(['id' => 101, 'title_en' => OnboardingChainCatalog::STEP_GATHER, 'title_ru' => 'Дары пустоши']);
        (new ReseedOnbStepGatherObjective(Database::forge('tests')))->up();

        // Новичок с пайком: 10 воды в рюкзаке, шаг активен.
        $db->table('character_resources')->insert(['id_characters' => self::CHAR, 'id_resources' => self::WATER_ID, 'quantity' => 10]);
        $db->table('quest_steps')->insert(['quest_id' => 101, 'character_id' => self::CHAR, 'is_completed' => 0]);
    }

    /** Урезанная схема не должна утечь в тесты, которые строят таблицы миграциями (FK на telegram_users). */
    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    public function testStarterKitWaterDoesNotCompleteStepAndProgressIsZero(): void
    {
        $this->assertFalse($this->objectiveMet(self::CHAR));
        $this->assertSame(0, $this->polarProgress(self::CHAR));
    }

    public function testGatheringFiveWaterInTwoTripsCompletesStep(): void
    {
        $persister = new LedgerProbePersister();
        $persister->mark(['id' => self::CHAR, 'telegram_user_id' => 25], [['resource_id' => self::WATER_ID, 'amount' => 3, 'type' => 'raw']]);
        $this->assertFalse($this->objectiveMet(self::CHAR));
        $this->assertSame(3, $this->polarProgress(self::CHAR));

        $persister->mark(['id' => self::CHAR, 'telegram_user_id' => 25], [
            ['resource_id' => self::WATER_ID, 'amount' => 2, 'type' => 'raw'],
            ['resource_id' => 8, 'amount' => 4, 'type' => 'raw'], // дерево в счёт воды не идёт
        ]);
        $this->assertTrue($this->objectiveMet(self::CHAR));
        $this->assertSame(5, $this->polarProgress(self::CHAR));

        $row = Database::connect('tests')->table('action_log')->where('description', self::WATER_ID . ':3')->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame(GatheredResourceLedger::EVENT, $row['action_name']);
        $this->assertSame('555', (string) $row['chat_id']);
        // Смоук на testbot: insertBatch через builder оставлял created_at = NULL, а активность
        // (PlayerDetectionService, RelocateAbandonedCharacters) читается по action_log.created_at.
        $this->assertNotNull($row['created_at']);
    }

    public function testNoMarkWithoutActiveGatherObjective(): void
    {
        $db = Database::connect('tests');
        (new LedgerProbePersister())->mark(['id' => self::VETERAN, 'telegram_user_id' => 25], [['resource_id' => self::WATER_ID, 'amount' => 9, 'type' => 'raw']]);
        $this->assertSame(0, $db->table('action_log')->countAllResults());

        // Шаг уже завершён — тоже не пишем.
        $db->table('quest_steps')->where('character_id', self::CHAR)->update(['is_completed' => 1]);
        (new LedgerProbePersister())->mark(['id' => self::CHAR, 'telegram_user_id' => 25], [['resource_id' => self::WATER_ID, 'amount' => 9, 'type' => 'raw']]);
        $this->assertSame(0, $db->table('action_log')->countAllResults());
    }

    public function testReseedIsIdempotentAndDownRestoresCollectResource(): void
    {
        $db        = Database::connect('tests');
        $migration = new ReseedOnbStepGatherObjective(Database::forge('tests'));
        $migration->up();

        $row  = $db->table('quests')->where('title_en', OnboardingChainCatalog::STEP_GATHER)->get()->getRowArray();
        $step = OnboardingChainCatalog::find(OnboardingChainCatalog::STEP_GATHER);
        $this->assertIsArray($row);
        $this->assertIsArray($step);
        $this->assertSame('gather_resource', $row['objective_type']);
        $this->assertSame('Water', $row['objective_target']);
        $this->assertSame(5, (int) $row['objective_qty']);
        $this->assertSame($step['description'], $row['description']);
        $this->assertStringContainsString('Добудь 5 единиц воды', $row['description']);
        $this->assertSame('добудь 5 единиц воды', $step['goal']);

        $migration->down();
        $row = $db->table('quests')->where('title_en', OnboardingChainCatalog::STEP_GATHER)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('collect_resource', $row['objective_type']);
    }

    private function objectiveMet(int $charId): bool
    {
        $step    = OnboardingChainCatalog::find(OnboardingChainCatalog::STEP_GATHER);
        $this->assertIsArray($step);
        $handler = new QuestObjectiveHandler();
        $method  = new \ReflectionMethod($handler, 'objectiveMet');

        return (bool) $method->invoke(
            $handler,
            ['objective_type' => $step['objective_type'], 'objective_target' => $step['objective_target'], 'objective_qty' => $step['objective_qty']],
            ['id' => $charId, 'level' => 1],
            Database::connect('tests'),
        );
    }

    private function polarProgress(int $charId): int
    {
        $step = OnboardingChainCatalog::find(OnboardingChainCatalog::STEP_GATHER);
        $this->assertIsArray($step);
        $svc    = new PolarStarService();
        $method = new \ReflectionMethod($svc, 'progressCount');

        return (int) $method->invoke($svc, $step['objective_type'], $step['objective_target'], $charId);
    }
}

/** Доступ к отметке добычи без всей цепочки persist() (подать, рюкзак, статы). */
final class LedgerProbePersister extends GatherResultPersister
{
    /**
     * @param array<string, mixed>                                            $character
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $found
     */
    public function mark(array $character, array $found): void
    {
        $this->recordGathered($character, $found);
    }
}
