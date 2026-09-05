<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player\Progression;

use App\Services\Player\ProfileHubService;
use App\Services\Player\Progression\LevelUnlockService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Слайс «Видимая лестница L1→L10» — рендер строки «что откроется на уровне N».
 *
 * Счётчики берутся из контент-таблиц, поэтому проверяем ЛОГИКУ рендера на подменённых
 * значениях (test-double), а не факт наличия строк в тестовой БД — `wildworld_tests`
 * контент-таблиц не содержит (memory feedback_verify_render_on_db_with_real_world_data).
 * Реальные числа сверяются Tier-3 смоком на testbot.
 *
 * @internal
 */
final class LevelUnlockServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LevelUnlockService::resetCache();
    }

    public function testRendersProdLevelTwoWithDeclension(): void
    {
        $svc = new FakeLevelUnlockService();
        // Реальный расклад L2 с прода на 2026-07-26.
        $svc->counts = ['resource' => 18, 'weapon' => 2, 'quest' => 1];

        $this->assertSame(
            '🎁 *На 2-м уровне:* 18 новых ресурсов, 2 вида оружия, 1 задание',
            $svc->summaryFor(2)
        );
    }

    public function testDeclensionEdgeCases(): void
    {
        $svc = new FakeLevelUnlockService();

        $svc->counts = ['craft' => 1];
        $this->assertStringContainsString('1 рецепт', (string) $svc->summaryFor(3));

        LevelUnlockService::resetCache();
        $svc->counts = ['craft' => 3];
        $this->assertStringContainsString('3 рецепта', (string) $svc->summaryFor(3));

        LevelUnlockService::resetCache();
        $svc->counts = ['craft' => 8];
        $this->assertStringContainsString('8 рецептов', (string) $svc->summaryFor(3));

        // 11-14 — исключение русского счёта: «11 рецептов», не «11 рецепт».
        LevelUnlockService::resetCache();
        $svc->counts = ['craft' => 11];
        $this->assertStringContainsString('11 рецептов', (string) $svc->summaryFor(3));
    }

    public function testSystemGatesAppendedAfterContentCounts(): void
    {
        $svc         = new FakeLevelUnlockService();
        $svc->counts = ['craft' => 16];
        $svc->gates  = ['выбор специализации крафта'];

        $this->assertSame(
            '🎁 *На 5-м уровне:* 16 рецептов, выбор специализации крафта',
            $svc->summaryFor(5)
        );
    }

    /**
     * Инцидент 2026-09-05: веха обещала «выбор специализации крафта», не сказав, куда нажимать,
     * а кнопка живёт внутри хаба «⚙️ Развитие». Гейтим сам факт адреса и то, что метка хаба
     * берётся из единого источника (иначе следующая перекладка карточки снова разведёт текст
     * с интерфейсом молча).
     */
    public function testSpecializationGateCarriesItsUiPath(): void
    {
        $svc    = new FakeLevelUnlockService();
        $suffix = $svc->exposeGatePathSuffix('specialization.min_level');

        $this->assertStringContainsString(ProfileHubService::HUB_DEVELOPMENT_LABEL, $suffix);
        $this->assertStringContainsString('→', $suffix);
        $this->assertStringStartsWith(' (', $suffix);
    }

    public function testGatesWithoutKnownPathStayBare(): void
    {
        $svc = new FakeLevelUnlockService();

        $this->assertSame('', $svc->exposeGatePathSuffix('oracle.min_level'));
        $this->assertSame('', $svc->exposeGatePathSuffix('collections.min_level'));
    }

    public function testEmptyLevelSaysNothingInsteadOfInventingReward(): void
    {
        $svc = new FakeLevelUnlockService();
        $svc->counts = [];

        $this->assertNull($svc->summaryFor(9));
    }

    public function testZeroCountsAreSkipped(): void
    {
        $svc = new FakeLevelUnlockService();
        $svc->counts = ['resource' => 0, 'craft' => 2];

        $this->assertSame('🎁 *На 4-м уровне:* 2 рецепта', $svc->summaryFor(4));
    }

    public function testFirstLevelHasNoTarget(): void
    {
        $svc = new FakeLevelUnlockService();
        $svc->counts = ['resource' => 99];

        $this->assertNull($svc->summaryFor(1));
        $this->assertNull($svc->summaryFor(0));
    }

    public function testMarkdownBalanced(): void
    {
        $svc = new FakeLevelUnlockService();
        $svc->counts = ['resource' => 18, 'craft' => 8, 'weapon' => 1, 'outfit' => 1, 'building' => 2, 'quest' => 6];
        $line = (string) $svc->summaryFor(10);

        $this->assertSame(0, substr_count($line, '*') % 2);
        $this->assertSame(0, substr_count($line, '_') % 2);
    }

    public function testUnknownCategoryIsIgnored(): void
    {
        $svc = new FakeLevelUnlockService();
        $svc->counts = ['craft' => 2, 'мусор' => 5];

        $this->assertSame('🎁 *На 3-м уровне:* 2 рецепта', $svc->summaryFor(3));
    }

    public function testCacheReturnsSameLineAndResetClearsIt(): void
    {
        $svc         = new FakeLevelUnlockService();
        $svc->counts = ['craft' => 1];
        $first       = $svc->summaryFor(3);

        $svc->counts = ['craft' => 99]; // без сброса кеша строка не меняется
        $this->assertSame($first, $svc->summaryFor(3));

        LevelUnlockService::resetCache();
        $this->assertStringContainsString('99', (string) $svc->summaryFor(3));
    }
}

/**
 * Test-double: подменяет обе выборки (контент-таблицы и пороги GameSettings).
 *
 * @internal
 */
final class FakeLevelUnlockService extends LevelUnlockService
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $gates = [];

    protected function countsFor(int $level): array
    {
        return $this->counts;
    }

    protected function systemGatesFor(int $level): array
    {
        return $this->gates;
    }

    /** Пробрасываем protected-хелпер: адрес вехи в интерфейсе проверяется напрямую. */
    public function exposeGatePathSuffix(string $key): string
    {
        return $this->gatePathSuffix($key);
    }
}
