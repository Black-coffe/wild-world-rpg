<?php

namespace Tests\Database;

use App\Services\Settlement\RuinLootService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-101 Фаза 4 — RuinLootService: повторяемый лут охраняемых руин.
 *
 * Покрывает: parseLootConfig (pure); maxForResource × множитель; killswitch
 * settlements.ruins.enabled; успешный лут (начисление в character_resources + запись кулдауна);
 * блокировка повторного лута кулдауном; remainingCooldownSeconds.
 *
 * @internal
 */
final class RuinLootServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private int $ruinId = 0;

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

        $db->table('resources')->insert(['name' => 'Сера', 'name_en' => 'Sulfur']);
        $db->table('resources')->insert(['name' => 'Нефть', 'name_en' => 'Oil']);

        $db->table('settlements')->insert([
            'code' => 'ruin_test', 'type' => 'ruins',
            'loot_config' => json_encode(['resources' => ['Sulfur' => 4, 'Oil' => 2]]),
        ]);
        $this->ruinId = (int) $db->insertID();

        $this->setSetting('settlements.ruins.enabled', 'bool', 'value_bool', 1);
        $this->setSetting('settlements.ruins.loot_cooldown_hours', 'int', 'value_int', 12);
        $this->setSetting('settlements.ruins.loot_amount_mult', 'float', 'value_float', 1.0);
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
        $data = ['setting_key' => $key, 'category' => 'world', 'value_type' => $type, 'hard_min' => '0', 'hard_max' => '999999', $col => $val];
        if (! empty($row)) {
            $db->table('game_settings')->where('id', $row['id'])->update([$col => $val, 'value_type' => $type]);
        } else {
            $db->table('game_settings')->insert($data);
        }
        $this->cleanCache();
    }

    private function ruinRow(): array
    {
        return Database::connect('tests')->table('settlements')->where('id', $this->ruinId)->get()->getRowArray();
    }

    public function testParseLootConfigValid(): void
    {
        $out = RuinLootService::parseLootConfig(json_encode(['resources' => ['Sulfur' => 4, 'Oil' => 2]]));
        $this->assertSame(['Sulfur' => 4, 'Oil' => 2], $out);
    }

    public function testParseLootConfigRejectsGarbage(): void
    {
        $this->assertSame([], RuinLootService::parseLootConfig(null));
        $this->assertSame([], RuinLootService::parseLootConfig(''));
        $this->assertSame([], RuinLootService::parseLootConfig('not json'));
        $this->assertSame([], RuinLootService::parseLootConfig(json_encode(['nope' => 1])));
        // отрицательные/нулевые/нечисловые max отбрасываются
        $this->assertSame(['Oil' => 3], RuinLootService::parseLootConfig(json_encode(['resources' => ['Oil' => 3, 'Bad' => 0, 'Neg' => -5, 'Str' => 'x']])));
    }

    public function testMaxForResourceAppliesMultiplier(): void
    {
        $this->setSetting('settlements.ruins.loot_amount_mult', 'float', 'value_float', 2.0);
        $svc = new RuinLootService();
        $this->assertSame(20, $svc->maxForResource(10));
        $this->assertSame(1, $svc->maxForResource(0)); // пол ≥1
    }

    public function testDisabledKillswitchBlocksLoot(): void
    {
        $this->setSetting('settlements.ruins.enabled', 'bool', 'value_bool', 0);
        $svc = new RuinLootService();
        $res = $svc->loot(777, $this->ruinRow());
        $this->assertFalse($res['ok']);
        $this->assertSame('disabled', $res['reason']);
    }

    public function testSuccessfulLootGrantsAndSetsCooldown(): void
    {
        $svc = new RuinLootService();
        $res = $svc->loot(777, $this->ruinRow());

        $this->assertTrue($res['ok']);
        $this->assertNotEmpty($res['awarded']);

        $db = Database::connect('tests');
        // ресурсы начислены
        $this->assertGreaterThan(0, $db->table('character_resources')->where('id_characters', 777)->countAllResults());
        // кулдаун записан
        $cd = $db->table('character_ruin_loot')->where('character_id', 777)->where('settlement_id', $this->ruinId)->get()->getRowArray();
        $this->assertNotEmpty($cd);
        $this->assertNotEmpty($cd['last_looted_at']);
    }

    public function testCooldownBlocksImmediateReloot(): void
    {
        $svc = new RuinLootService();
        $this->assertTrue($svc->loot(777, $this->ruinRow())['ok']);

        $again = (new RuinLootService())->loot(777, $this->ruinRow());
        $this->assertFalse($again['ok']);
        $this->assertSame('cooldown', $again['reason']);
        $this->assertGreaterThan(0, $again['remaining']);
    }

    public function testRemainingCooldownSeconds(): void
    {
        $db = Database::connect('tests');
        $db->table('character_ruin_loot')->insert([
            'character_id' => 55, 'settlement_id' => $this->ruinId,
            'last_looted_at' => date('Y-m-d H:i:s', 1_000_000),
        ]);
        $svc = new RuinLootService();
        // через 1 час после лута (cooldown 12ч) → осталось ~11ч
        $remaining = $svc->remainingCooldownSeconds(55, $this->ruinId, 1_000_000 + 3600);
        $this->assertSame(11 * 3600, $remaining);
        // спустя 12ч+ → 0
        $this->assertSame(0, $svc->remainingCooldownSeconds(55, $this->ruinId, 1_000_000 + 13 * 3600));
    }
}
