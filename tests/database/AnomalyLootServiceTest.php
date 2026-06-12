<?php

namespace Tests\Database;

use App\Services\Settlement\RuinLootService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E16 Ф2 (ADR-116) — RuinLootService с keyPrefix='world.anomalies' (поясные аномалии).
 *
 * Покрывает обобщение Ф2: тот же сервис читает СВОЙ killswitch/кулдаун/множитель по префиксу,
 * делит таблицу кулдауна character_ruin_loot. Ключевой инвариант — НЕЗАВИСИМОСТЬ от руин-killswitch
 * (settlements.ruins.enabled=0 НЕ блокирует аномалию, и наоборот).
 *
 * @internal
 */
final class AnomalyLootServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private int $anomalyId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $db = Database::connect('tests');
        foreach (['settlements', 'character_ruin_loot', 'resources', 'character_resources', 'game_settings'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('
            CREATE TABLE settlements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(64) NULL, type VARCHAR(16) NULL, loot_config TEXT NULL
            )');
        $db->query('
            CREATE TABLE character_ruin_loot (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL, settlement_id INT NOT NULL,
                last_looted_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL
            )');
        $db->query('
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NULL, name_en VARCHAR(120) NULL
            )');
        $db->query('
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NOT NULL, id_resources INT NOT NULL, quantity INT NOT NULL DEFAULT 0
            )');
        $db->query('
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL, category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL, value_string TEXT NULL,
                hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL
            )');

        $db->table('resources')->insert(['name' => 'Пепел Предтеч', 'name_en' => 'ForerunnerAsh']);
        $db->table('resources')->insert(['name' => 'Редкие металлы', 'name_en' => 'RareMetals']);

        $db->table('settlements')->insert([
            'code' => 'anomaly_test', 'type' => 'anomaly',
            'loot_config' => json_encode(['resources' => ['ForerunnerAsh' => 2, 'RareMetals' => 6]]),
        ]);
        $this->anomalyId = (int) $db->insertID();

        // Анома-килсвич ON, руин-килсвич OFF — проверяем независимость.
        $this->setSetting('world.anomalies.enabled', 'bool', 'value_bool', 1);
        $this->setSetting('world.anomalies.loot_cooldown_hours', 'int', 'value_int', 12);
        $this->setSetting('world.anomalies.loot_amount_mult', 'float', 'value_float', 1.0);
        $this->setSetting('settlements.ruins.enabled', 'bool', 'value_bool', 0);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['settlements', 'character_ruin_loot', 'resources', 'character_resources', 'game_settings'] as $t) {
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

    private function setSetting(string $key, string $type, string $col, int|float $val): void
    {
        $db  = Database::connect('tests');
        $row = $db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($row)) {
            $db->table('game_settings')->where('id', $row['id'])->update([$col => $val, 'value_type' => $type]);
        } else {
            $db->table('game_settings')->insert(['setting_key' => $key, 'category' => 'world', 'value_type' => $type, 'hard_min' => '0', 'hard_max' => '999999', $col => $val]);
        }
        $this->cleanCache();
    }

    private function anomaly(): RuinLootService
    {
        return new RuinLootService(keyPrefix: 'world.anomalies');
    }

    private function anomalyRow(): array
    {
        return Database::connect('tests')->table('settlements')->where('id', $this->anomalyId)->get()->getRowArray();
    }

    public function testAnomalyEnabledReadsOwnPrefix(): void
    {
        $this->assertTrue($this->anomaly()->enabled());
        // Руин-сервис (дефолтный префикс) видит свой килсвич OFF — независимость.
        $this->assertFalse((new RuinLootService())->enabled());
    }

    public function testSuccessfulAnomalyLootDespiteRuinsKillswitchOff(): void
    {
        // settlements.ruins.enabled=0, но аномалия лутается через свой префикс.
        $res = $this->anomaly()->loot(888, $this->anomalyRow());

        $this->assertTrue($res['ok']);
        $this->assertNotEmpty($res['awarded']);

        $db = Database::connect('tests');
        $this->assertGreaterThan(0, $db->table('character_resources')->where('id_characters', 888)->countAllResults());
        $cd = $db->table('character_ruin_loot')->where('character_id', 888)->where('settlement_id', $this->anomalyId)->get()->getRowArray();
        $this->assertNotEmpty($cd);
        $this->assertNotEmpty($cd['last_looted_at']);
    }

    public function testAnomalyKillswitchOffBlocks(): void
    {
        $this->setSetting('world.anomalies.enabled', 'bool', 'value_bool', 0);
        $res = $this->anomaly()->loot(888, $this->anomalyRow());
        $this->assertFalse($res['ok']);
        $this->assertSame('disabled', $res['reason']);
    }

    public function testAnomalyCooldownBlocksReloot(): void
    {
        $this->assertTrue($this->anomaly()->loot(888, $this->anomalyRow())['ok']);
        $again = $this->anomaly()->loot(888, $this->anomalyRow());
        $this->assertFalse($again['ok']);
        $this->assertSame('cooldown', $again['reason']);
        $this->assertGreaterThan(0, $again['remaining']);
    }

    public function testAnomalyAmountMultiplier(): void
    {
        $this->setSetting('world.anomalies.loot_amount_mult', 'float', 'value_float', 2.0);
        $svc = $this->anomaly();
        $this->assertSame(20, $svc->maxForResource(10));
        $this->assertSame(1, $svc->maxForResource(0));
    }
}
