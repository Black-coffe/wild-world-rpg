<?php

namespace Tests\Database;

use App\Services\World\MarchMiniEventService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E17 Ф2 (ADR-117) — MarchMiniEventService: персональные мини-события в Походе для L25+.
 *
 * Покрывает тройной гейт (killswitch + min_level + chance), dormant byte-identical (chance 0 → roll
 * всегда false), каталог (pickKey/has/card) и investigate (начисление generic-ресурсов).
 *
 * @internal
 */
final class MarchMiniEventServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (['resources', 'character_resources', 'game_settings'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NULL, name_en VARCHAR(120) NULL)');
        $db->query('CREATE TABLE character_resources (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT NOT NULL, id_resources INT NOT NULL, quantity INT NOT NULL DEFAULT 0)');
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL)');
        foreach (['RareMetals', 'Oil', 'Ironstone', 'Stones', 'Wood', 'Sulfur'] as $en) {
            $db->table('resources')->insert(['name' => $en, 'name_en' => $en]);
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['resources', 'character_resources', 'game_settings'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            cache()->clean();
        }
    }

    private function set(string $key, string $type, string $col, int|float $val): void
    {
        $db  = Database::connect('tests');
        $row = $db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($row)) {
            $db->table('game_settings')->where('id', $row['id'])->update([$col => $val, 'value_type' => $type]);
        } else {
            $db->table('game_settings')->insert(['setting_key' => $key, 'value_type' => $type, $col => $val]);
        }
        $this->cleanCache();
    }

    private function enable(float $chance = 0.0, int $minLevel = 25): void
    {
        $this->set('world.march_events.enabled', 'bool', 'value_bool', 1);
        $this->set('world.march_events.min_level', 'int', 'value_int', $minLevel);
        $this->set('world.march_events.chance_per_cell', 'float', 'value_float', $chance);
    }

    public function testDormantNotEligible(): void
    {
        // killswitch не сидится → enabled false → eligible false на любом уровне.
        $svc = new MarchMiniEventService();
        $this->assertFalse($svc->enabled());
        $this->assertFalse($svc->eligible(40));
    }

    public function testEligibleRespectsKillswitchAndLevel(): void
    {
        $this->enable(0.0, 25);
        $svc = new MarchMiniEventService();
        $this->assertTrue($svc->enabled());
        $this->assertFalse($svc->eligible(24), 'ниже min_level → не eligible');
        $this->assertTrue($svc->eligible(25), 'на пороге → eligible');
        $this->assertTrue($svc->eligible(40));
    }

    public function testRollFalseWhenChanceZero(): void
    {
        // chance 0 → roll всегда false (dormant byte-identical, fixture-fence).
        $this->enable(0.0, 25);
        $svc = new MarchMiniEventService();
        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($svc->roll(), 'chance 0 → никогда не триггерит');
        }
    }

    public function testRollTrueWhenChanceMax(): void
    {
        $this->enable(1.0, 25);
        $svc = new MarchMiniEventService();
        $this->assertTrue($svc->roll(), 'chance 1.0 → всегда триггерит');
    }

    public function testPickKeyAndCardValid(): void
    {
        $svc = new MarchMiniEventService();
        for ($i = 0; $i < 20; $i++) {
            $key = $svc->pickKey();
            $this->assertTrue($svc->has($key));
            $card = $svc->card($key);
            $this->assertIsArray($card);
            $this->assertNotSame('', $card['title']);
            $this->assertNotSame('', $card['lore']);
        }
        $this->assertFalse($svc->has('no_such_event'));
        $this->assertNull($svc->card('no_such_event'));
    }

    public function testInvestigateGrantsGenericResources(): void
    {
        $db  = Database::connect('tests');
        $svc = new MarchMiniEventService();
        $res = $svc->investigate('abandoned_cache', 555);
        $this->assertTrue($res['ok']);
        $this->assertNotEmpty($res['awarded']);
        // начислено в character_resources
        $this->assertGreaterThan(0, $db->table('character_resources')->where('id_characters', 555)->countAllResults());
        // только generic — не золото, не поясное rarity-1
        foreach (array_keys($res['awarded']) as $en) {
            $this->assertNotContains($en, ['Gold', 'ForerunnerAsh', 'RiftCrystal']);
        }
    }

    public function testInvestigateUnknownKeyNoOp(): void
    {
        $svc = new MarchMiniEventService();
        $res = $svc->investigate('no_such_event', 555);
        $this->assertFalse($res['ok']);
        $this->assertSame([], $res['awarded']);
    }
}
