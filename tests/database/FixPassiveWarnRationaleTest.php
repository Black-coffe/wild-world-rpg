<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\FixPassiveWarnRationale;
use App\Database\Migrations\SeedPassiveFloorSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-200 (sudden-death-1125, review round 4 minor): после S5 порог добычи 15, порог предупреждения 30 —
 * rationale `health.passive_warn_threshold` не должен утверждать, что они совпадают.
 *
 * @internal
 */
final class FixPassiveWarnRationaleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const KEY = 'health.passive_warn_threshold';

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2026-12-26-100000_SeedPassiveFloorSettings.php';
        require_once APPPATH . 'Database/Migrations/2026-12-30-100000_FixPassiveWarnRationale.php';
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

    public function testRationaleNoLongerClaimsItEqualsTheGatherThreshold(): void
    {
        $this->assertStringContainsString('Совпадает с порогом добычи', $this->rationale(), 'precondition');

        $m = new FixPassiveWarnRationale(Database::forge('tests'));
        $m->up();
        $m->up();

        $after = $this->rationale();
        $this->assertStringNotContainsString('Совпадает с порогом добычи', $after);
        $this->assertStringContainsString('ниже 30', $after);

        $m->down();
        $this->assertStringContainsString('Совпадает с порогом добычи', $this->rationale());
    }

    private function rationale(): string
    {
        $row = Database::connect('tests')->table('game_settings')->where('setting_key', self::KEY)->get()->getRowArray();

        return (string) ($row['rationale_text'] ?? '');
    }
}
