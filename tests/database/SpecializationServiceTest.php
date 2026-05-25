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
        // Без per-branch anchor'ов и без global → дефолт 0.90 на любом уровне.
        $this->assertEqualsWithDelta(0.90, $svc->getCraftTimeMultiplierFor('weaponsmith', ['zone_name' => 'оружие'], 10), 1e-9);
        $this->assertEqualsWithDelta(0.90, $svc->getCraftTimeMultiplierFor('engineer', ['zone_name' => 'защита'], 10), 1e-9);
    }

    public function testNonMatchingPathsAreNoOp(): void
    {
        $svc = new SpecializationService();
        // ветка ≠ зона.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('weaponsmith', ['zone_name' => 'медицина'], 10));
        // нет ветки у персонажа.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor(null, ['zone_name' => 'оружие'], 10));
        // невалидная ветка.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('archer', ['zone_name' => 'оружие'], 10));
        // зона рецепта не маппится ни на одну ветку.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('engineer', ['zone_name' => 'земледелие'], 10));
    }

    public function testKillswitchForcesNoOp(): void
    {
        $this->seedBool('specialization.enabled', 0);
        $svc = new SpecializationService();
        // Даже при матче — 1.0, когда слой выключен.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('weaponsmith', ['zone_name' => 'оружие'], 25));
    }

    public function testFallbackToV16GlobalWithoutAnchors(): void
    {
        // V17: без per-branch anchor'ов — падаем на V16 global (0 регрессии).
        $this->seedFloat('specialization.craft_time_multiplier', 0.88);
        $svc = new SpecializationService();
        // На любом уровне — плоский global, т.к. anchor'ов нет.
        $this->assertEqualsWithDelta(0.88, $svc->craftTimeMultiplierForLevel('medic', 5), 1e-9);
        $this->assertEqualsWithDelta(0.88, $svc->craftTimeMultiplierForLevel('medic', 25), 1e-9);
        $this->assertEqualsWithDelta(0.88, $svc->getCraftTimeMultiplierFor('medic', ['zone_name' => 'медицина'], 25), 1e-9);
    }

    // ── V17 (ADR-048): scaling по уровню (интерполяция L5↔L25) ──────────────

    public function testCraftTimeScalesByLevelBetweenAnchors(): void
    {
        $this->seedFloat('specialization.weaponsmith.l5.craft_time_multiplier', 0.90);
        $this->seedFloat('specialization.weaponsmith.l25.craft_time_multiplier', 0.80);
        $svc = new SpecializationService();

        $this->assertEqualsWithDelta(0.90, $svc->craftTimeMultiplierForLevel('weaponsmith', 5), 1e-9, 'L5 = нижний anchor');
        $this->assertEqualsWithDelta(0.80, $svc->craftTimeMultiplierForLevel('weaponsmith', 25), 1e-9, 'L25 = верхний anchor');
        // Середина L15: 0.90 + (0.80-0.90)*(15-5)/20 = 0.85.
        $this->assertEqualsWithDelta(0.85, $svc->craftTimeMultiplierForLevel('weaponsmith', 15), 1e-9, 'L15 = интерполяция');
        // Вне диапазона — клампится к anchor'ам.
        $this->assertEqualsWithDelta(0.90, $svc->craftTimeMultiplierForLevel('weaponsmith', 3), 1e-9, '<L5 → нижний');
        $this->assertEqualsWithDelta(0.80, $svc->craftTimeMultiplierForLevel('weaponsmith', 40), 1e-9, '>L25 → верхний');
    }

    public function testPerBranchCurvesDiffer(): void
    {
        $this->seedFloat('specialization.weaponsmith.l25.craft_time_multiplier', 0.80);
        $this->seedFloat('specialization.engineer.l25.craft_time_multiplier', 0.85);
        // l5 не сидим → fallback на global (нет → 0.90).
        $svc = new SpecializationService();
        $this->assertEqualsWithDelta(0.80, $svc->craftTimeMultiplierForLevel('weaponsmith', 25), 1e-9);
        $this->assertEqualsWithDelta(0.85, $svc->craftTimeMultiplierForLevel('engineer', 25), 1e-9);
        // Узкая ветка (weaponsmith) сильнее широкой (engineer) в эндгейме.
        $this->assertLessThan(
            $svc->craftTimeMultiplierForLevel('engineer', 25),
            $svc->craftTimeMultiplierForLevel('weaponsmith', 25),
            'weaponsmith должен быть быстрее (меньше множитель) engineer в эндгейме'
        );
    }

    public function testGetCraftTimeMultiplierForUsesLevel(): void
    {
        $this->seedFloat('specialization.weaponsmith.l5.craft_time_multiplier', 0.90);
        $this->seedFloat('specialization.weaponsmith.l25.craft_time_multiplier', 0.80);
        $svc    = new SpecializationService();
        $recipe = ['zone_name' => 'оружие'];
        // Низкий уровень → вход, высокий → эндгейм.
        $this->assertEqualsWithDelta(0.90, $svc->getCraftTimeMultiplierFor('weaponsmith', $recipe, 5), 1e-9);
        $this->assertEqualsWithDelta(0.80, $svc->getCraftTimeMultiplierFor('weaponsmith', $recipe, 25), 1e-9);
        // Не матч → 1.0 независимо от уровня.
        $this->assertSame(1.0, $svc->getCraftTimeMultiplierFor('weaponsmith', ['zone_name' => 'медицина'], 25));
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
