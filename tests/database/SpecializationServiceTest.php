<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\SpecializationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * V16 (ADR-047) — SpecializationService: zone→branch маппинг, craft-time множитель
 * (match/no-op), killswitch, tuned-значения, политика respec (кулдаун).
 *
 * GameSettings читаются из game_settings (паттерн FarmingServiceTest): дефолты — без
 * строки (get → default), tuned — с seeded-строкой.
 *
 * @internal
 */
final class SpecializationServiceTest extends CIUnitTestCase
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
        // Дефолты не сидим — get() вернёт переданный default (enabled=true, mult=0.90).
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS game_settings');
        $this->cleanCache();
        parent::tearDown();
    }

    private function seedBool(string $key, int $bool): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'craft', 'value_type' => 'bool', 'value_bool' => $bool,
        ]);
        $this->cleanCache();
    }

    private function seedFloat(string $key, float $float): void
    {
        Database::connect('tests')->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'craft', 'value_type' => 'float', 'value_float' => $float,
        ]);
        $this->cleanCache();
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

    // ── zone → branch ─────────────────────────────────────────────────────

    public function testZoneToBranchMapping(): void
    {
        $svc = new SpecializationService();
        $this->assertSame('weaponsmith', $svc->branchForZone('оружие'));
        $this->assertSame('medic', $svc->branchForZone('медицина'));
        $this->assertSame('engineer', $svc->branchForZone('производство'));
        $this->assertSame('engineer', $svc->branchForZone('защита'));
        $this->assertSame('engineer', $svc->branchForZone('инструменты'));
        // Зоны вне специализаций — null (бонуса нет).
        $this->assertNull($svc->branchForZone('земледелие'));
        $this->assertNull($svc->branchForZone('биом'));
        $this->assertNull($svc->branchForZone(''));
    }

    public function testBranchForRecipe(): void
    {
        $svc = new SpecializationService();
        $this->assertSame('medic', $svc->branchForRecipe(['zone_name' => 'медицина']));
        $this->assertSame('weaponsmith', $svc->branchForRecipe(['zone_name' => 'оружие']));
        $this->assertNull($svc->branchForRecipe([]));
        $this->assertNull($svc->branchForRecipe(['zone_name' => 'земледелие']));
    }

    public function testBranchesAndLabels(): void
    {
        $svc = new SpecializationService();
        $this->assertCount(3, $svc->branches());
        $this->assertTrue($svc->isValidBranch('engineer'));
        $this->assertFalse($svc->isValidBranch('archer'));
        $this->assertStringContainsString('Оружейник', $svc->labelFor('weaponsmith'));
        $this->assertSame('не выбрана', $svc->labelFor(null));
        $this->assertSame('не выбрана', $svc->labelFor('nonsense'));
    }

    // ── craft-time множитель ──────────────────────────────────────────────

    public function testMatchingBranchGetsDefaultMultiplier(): void
    {
        $svc = new SpecializationService();
        // Дефолт 0.90 (без seeded-строки).
        $this->assertEqualsWithDelta(0.90, $svc->getCraftTimeMultiplierFor('weaponsmith', ['zone_name' => 'оружие']), 1e-9);
        $this->assertEqualsWithDelta(0.90, $svc->getCraftTimeMultiplierFor('engineer', ['zone_name' => 'защита']), 1e-9);
    }

    public function testNonMatchingPathsAreNoOp(): void
    {
        $svc = new SpecializationService();
        // ветка ≠ зона.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('weaponsmith', ['zone_name' => 'медицина']));
        // нет ветки у персонажа.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor(null, ['zone_name' => 'оружие']));
        // невалидная ветка.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('archer', ['zone_name' => 'оружие']));
        // зона рецепта не маппится ни на одну ветку.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('engineer', ['zone_name' => 'земледелие']));
    }

    public function testKillswitchForcesNoOp(): void
    {
        $this->seedBool('specialization.enabled', 0);
        $svc = new SpecializationService();
        // Даже при матче — 1.0, когда слой выключен.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('weaponsmith', ['zone_name' => 'оружие']));
    }

    public function testTunedMultiplierApplied(): void
    {
        $this->seedFloat('specialization.craft_time_multiplier', 0.80);
        $svc = new SpecializationService();
        $this->assertEqualsWithDelta(0.80, $svc->getCraftTimeMultiplierFor('medic', ['zone_name' => 'медицина']), 1e-9);
    }

    // ── respec-политика ───────────────────────────────────────────────────

    public function testRespecCooldown(): void
    {
        $svc = new SpecializationService();
        // Дефолт кулдауна 7 дней.
        $this->assertSame(7, $svc->respecCooldownDays());
        // Первый выбор (null changed_at) — можно всегда.
        $this->assertTrue($svc->canChangeNow(null));
        // Только что сменил — нельзя.
        $this->assertFalse($svc->canChangeNow(date('Y-m-d H:i:s')));
        // Сменил 8 дней назад — можно.
        $this->assertTrue($svc->canChangeNow(date('Y-m-d H:i:s', time() - 8 * 86400)));
        // nextChangeTs: для свежей смены вернёт будущий ts, для null — null.
        $this->assertNull($svc->nextChangeTs(null));
        $this->assertIsInt($svc->nextChangeTs(date('Y-m-d H:i:s')));
    }

    public function testPolicyDefaults(): void
    {
        $svc = new SpecializationService();
        $this->assertSame(5, $svc->minLevel());
        $this->assertSame(5000, $svc->respecCostGold());
        $this->assertEqualsWithDelta(0.90, $svc->craftTimeMultiplier(), 1e-9);
    }
}
