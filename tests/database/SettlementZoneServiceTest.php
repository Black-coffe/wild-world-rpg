<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\SettlementModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Settlement\SettlementZoneService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-101 Фаза 0 — SettlementZoneService: killswitch-гейт + правила зоны (anchor / radius).
 *
 * @internal
 */
final class SettlementZoneServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['game_settings', 'settlements'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, category VARCHAR(32) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE settlements (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(64), name_ru VARCHAR(120) NULL, type VARCHAR(16) NULL, anchor_cell INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, faction_id INT NULL, zone_policy VARCHAR(16) DEFAULT \'neutral\', enter_min_standing INT NULL, icon VARCHAR(16) NULL, description_ru TEXT NULL, enabled TINYINT DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
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

    private function setBool(string $key, int $val): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'bool', 'value_bool' => $val,
        ]);
    }

    private function setInt(string $key, int $val): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'int', 'value_int' => $val,
        ]);
    }

    private function seedSettlement(string $code, int $anchor, string $policy, int $enabled = 1, int $x = 0, int $y = 0): void
    {
        Database::connect('tests')->table('settlements')->insert([
            'code' => $code, 'name_ru' => $code, 'type' => 'outpost', 'anchor_cell' => $anchor,
            'coordinate_x' => $x, 'coordinate_y' => $y, 'zone_policy' => $policy, 'enabled' => $enabled,
        ]);
    }

    private function svc(): SettlementZoneService
    {
        return new SettlementZoneService(new GameSettingsService(), new SettlementModel());
    }

    public function testDormantLayerReturnsNullEvenWithSettlement(): void
    {
        // settlements.enabled НЕ задан (default false) → слой dormant.
        $this->seedSettlement('outpost_a', 12345, 'safe');
        $this->assertNull($this->svc()->policyAt(12345));
        $this->assertFalse($this->svc()->isSafeZone(12345));
    }

    public function testEnabledAnchorMatchReturnsPolicy(): void
    {
        $this->setBool('settlements.enabled', 1);
        $this->seedSettlement('outpost_a', 12345, 'safe');

        $p = $this->svc()->policyAt(12345);
        $this->assertIsArray($p);
        $this->assertSame('safe', $p['policy']);
        $this->assertTrue($this->svc()->isSafeZone(12345));
    }

    public function testEnabledButNoSettlementOnCellReturnsNull(): void
    {
        $this->setBool('settlements.enabled', 1);
        $this->seedSettlement('outpost_a', 12345, 'safe');
        $this->assertNull($this->svc()->policyAt(99999));
    }

    public function testDisabledSettlementIgnored(): void
    {
        $this->setBool('settlements.enabled', 1);
        $this->seedSettlement('outpost_off', 12345, 'safe', 0); // enabled=0
        $this->assertNull($this->svc()->policyAt(12345));
    }

    public function testHostileNotSafeZone(): void
    {
        $this->setBool('settlements.enabled', 1);
        $this->seedSettlement('den', 555, 'hostile');
        $p = $this->svc()->policyAt(555);
        $this->assertSame('hostile', $p['policy']);
        $this->assertFalse($this->svc()->isSafeZone(555));
    }

    public function testZoneRadiusChebyshev(): void
    {
        $this->setBool('settlements.enabled', 1);
        $this->setInt('settlements.zone_radius', 1);
        // поселение в (50,50); радиус 1 → клетки 49..51 × 49..51 — часть зоны.
        $this->seedSettlement('outpost_r', 5050, 'safe', 1, 50, 50);

        // соседняя клетка (51,51) с другим cell_number, но в радиусе 1 → safe.
        $p = $this->svc()->policyAt(5151, 51, 51);
        $this->assertIsArray($p);
        $this->assertSame('safe', $p['policy']);

        // клетка (53,50) — Чебышёв-дистанция 3 > радиус 1 → вне зоны.
        $this->assertNull($this->svc()->policyAt(5350, 53, 50));
    }
}
