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
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
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
}
