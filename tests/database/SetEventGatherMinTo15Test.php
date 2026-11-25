<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\GatherAction;
use App\Database\Migrations\SeedPassiveFloorSettings;
use App\Database\Migrations\SetEventGatherMinTo15;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-200 (sudden-death-1125 S5, ask 10) — порог добычи в биоме события `health.event_gather_min` после
 * preprod-смоука: 15 вместо 30 (Ураган покрывает все 9 биомов, с 30 раненый не добывал нигде). Миграция ставит
 * 15 в дефолт и в значение, если там ещё старый дефолт 30; значение, подкрученное в админке, не трогает.
 * Посеянное значение совпадает с фолбэком кода.
 *
 * @internal
 */
final class SetEventGatherMinTo15Test extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const KEY = 'health.event_gather_min';

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2026-12-26-100000_SeedPassiveFloorSettings.php';
        require_once APPPATH . 'Database/Migrations/2026-12-29-100000_SetEventGatherMinTo15.php';
        $db = Database::connect('tests');
        $t  = $db->prefixTable('game_settings');
        $db->query("DROP TABLE IF EXISTS {$t}");
        $db->query("CREATE TABLE {$t} (
            id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(128) NOT NULL UNIQUE, category VARCHAR(32) NULL,
            value_type VARCHAR(16) NULL, value_int INT NULL, value_float DOUBLE NULL, value_bool TINYINT NULL,
            value_string TEXT NULL, default_value_text VARCHAR(255) NULL, recommended_min VARCHAR(32) NULL,
            recommended_max VARCHAR(32) NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL,
            rationale_text TEXT NULL, effect_text TEXT NULL, above_effect_text TEXT NULL, below_effect_text TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
        (new SeedPassiveFloorSettings(Database::forge('tests')))->up();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable('game_settings'));
        parent::tearDown();
    }

    public function testOldDefaultBecomesFifteenAndMatchesTheCode(): void
    {
        $this->assertSame(30.0, $this->value(), 'precondition: the S1 seed wrote 30');

        $this->migration()->up();
        $this->migration()->up(); // повтор — no-op

        $row = $this->row();
        $this->assertSame(15.0, (float) $row['value_float']);
        $this->assertSame('15', $row['default_value_text']);
        $this->assertSame(GatherAction::EVENT_GATHER_MIN_DEFAULT, (float) $row['default_value_text'], 'code fallback = seeded default');
        $this->assertGreaterThanOrEqual((float) $row['recommended_min'], 15.0, 'inside soft bounds');
        $this->assertLessThanOrEqual((float) $row['recommended_max'], 15.0, 'inside soft bounds');
        $this->assertGreaterThanOrEqual((float) $row['hard_min'], (float) $row['recommended_min']);
        $this->assertLessThanOrEqual((float) $row['hard_max'], (float) $row['recommended_max']);
        foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text'] as $field) {
            $this->assertNotSame('', trim((string) $row[$field]), $field);
        }
        $this->assertStringContainsString('баз', (string) $row['effect_text'], 'effect names the own-base exception');

        $this->migration()->down();
        $this->assertSame(30.0, $this->value(), 'down restores the old default');
        $this->assertSame('30', $this->row()['default_value_text']);
    }

    public function testAdminTunedValueIsKept(): void
    {
        Database::connect('tests')->table('game_settings')->where('setting_key', self::KEY)->update(['value_float' => 22.0]);

        $this->migration()->up();

        $this->assertSame(22.0, $this->value(), 'a value the admin changed stays');
        $this->assertSame('15', $this->row()['default_value_text'], 'the default for Reset still moves to 15');

        $this->migration()->down();
        $this->assertSame(22.0, $this->value(), 'down does not touch a tuned value either');
    }

    private function migration(): SetEventGatherMinTo15
    {
        return new SetEventGatherMinTo15(Database::forge('tests'));
    }

    private function value(): float
    {
        return (float) $this->row()['value_float'];
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = Database::connect('tests')->table('game_settings')->where('setting_key', self::KEY)->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }
}
