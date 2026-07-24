<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\PolarStarService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139) — «полярная звезда».
 *
 * Рендер строки цели — pure-логика поверх каталога; killswitch / выбор активного шага /
 * прогресс — через test-double, подменяющий GameSettings/DB-seam'ы (без БД на CI, memory
 * feedback_taskhandler_telegram_init_in_tests). DB-интеграция (activeStepTitle/progressCount
 * против реальной схемы) — Tier-3 cold-smoke.
 *
 * @internal
 */
final class PolarStarServiceTest extends CIUnitTestCase
{
    // ── Каталог: новое поле goal ──────────────────────────────────────────────

    public function testEveryStepHasNonEmptyGoal(): void
    {
        foreach (OnboardingChainCatalog::steps() as $step) {
            $this->assertArrayHasKey('goal', $step, $step['title_en']);
            $this->assertNotSame('', trim($step['goal']), $step['title_en']);
        }
    }

    /** Цель не должна нести непарных markdown-токенов (legacy Markdown → 400/silent no-send). */
    public function testEveryGoalMarkdownBalanced(): void
    {
        foreach (OnboardingChainCatalog::steps() as $step) {
            $this->assertSame(0, substr_count($step['goal'], '*') % 2, "goal {$step['title_en']} непарные *");
            $this->assertSame(0, substr_count($step['goal'], '_') % 2, "goal {$step['title_en']} непарные _");
        }
    }

    // ── Killswitch / пустые состояния ─────────────────────────────────────────

    public function testKillswitchOffReturnsNull(): void
    {
        $svc = new FakePolarStarService();
        $svc->on     = false;
        $svc->active = OnboardingChainCatalog::STEP_MOVE;
        $this->assertNull($svc->line(1));
    }

    public function testNoActiveStepReturnsNull(): void
    {
        $svc = new FakePolarStarService();
        $svc->active = null;
        $this->assertNull($svc->line(1));
    }

    public function testZeroCharIdReturnsNull(): void
    {
        $svc = new FakePolarStarService();
        $svc->active = OnboardingChainCatalog::STEP_MOVE;
        $this->assertNull($svc->line(0));
    }

    public function testUnknownActiveStepReturnsNull(): void
    {
        $svc = new FakePolarStarService();
        $svc->active = 'OnbStepBogus'; // не в каталоге
        $this->assertNull($svc->line(1));
    }

    // ── Countable-типы: дробь X/Y ─────────────────────────────────────────────

    public function testMoveStepRendersCountableFraction(): void
    {
        $svc = new FakePolarStarService();
        $svc->active   = OnboardingChainCatalog::STEP_MOVE;
        $svc->progress = ['explore_cells' => 1];
        $this->assertSame('🎯 *Сейчас:* выйди на разведку — пройди 3 новые клетки (1/3)', $svc->line(1));
    }

    public function testCountableProgressCappedAtTarget(): void
    {
        $svc = new FakePolarStarService();
        $svc->active   = OnboardingChainCatalog::STEP_MOVE;
        $svc->progress = ['explore_cells' => 10]; // больше цели → отображаем 3/3, не 10/3
        $this->assertSame('🎯 *Сейчас:* выйди на разведку — пройди 3 новые клетки (3/3)', $svc->line(1));
    }

    public function testGatherStepRendersWaterFraction(): void
    {
        $svc = new FakePolarStarService();
        $svc->active   = OnboardingChainCatalog::STEP_GATHER;
        $svc->progress = ['collect_resource' => 3];
        $this->assertSame('🎯 *Сейчас:* собери 5 единиц воды (3/5)', $svc->line(1));
    }

    public function testLevelMilestoneRendersLevelFraction(): void
    {
        $svc = new FakePolarStarService();
        $svc->active   = OnboardingChainCatalog::STEP_LEVEL5;
        $svc->progress = ['level_milestone' => 2];
        $this->assertSame('🎯 *Сейчас:* дорасти до 5 уровня (опыт капает за всё) (2/5)', $svc->line(1));
    }

    public function testNegativeProgressFlooredToZero(): void
    {
        $svc = new FakePolarStarService();
        $svc->active   = OnboardingChainCatalog::STEP_GATHER;
        $svc->progress = ['collect_resource' => -5];
        $this->assertSame('🎯 *Сейчас:* собери 5 единиц воды (0/5)', $svc->line(1));
    }

    // ── One-shot типы: без дроби ──────────────────────────────────────────────

    public function testSingleShotStepHasNoFraction(): void
    {
        $svc = new FakePolarStarService();
        $svc->active   = OnboardingChainCatalog::STEP_CRAFT;
        $svc->progress = ['craft_any' => 0];
        $line = $svc->line(1);
        // Метка кнопки берётся из того же источника, что и клавиатура (инцидент 2026-07-24:
        // захардкоженное в тесте название закрепляло бы устаревшую подпись как «правильную»).
        $craft = \App\Services\Telegram\BotMenuService::menuLabel('craft');
        $this->assertSame("🎯 *Сейчас:* скрафти любой предмет (кнопка «{$craft}»)", $line);
        $this->assertStringNotContainsString('/', (string) $line); // нет дроби X/Y
    }

    public function testClaimBaseSingleShotNoFraction(): void
    {
        $svc = new FakePolarStarService();
        $svc->active = OnboardingChainCatalog::STEP_CLAIM_BASE;
        $base        = \App\Services\Telegram\BotMenuService::menuLabel('base');
        $this->assertSame("🎯 *Сейчас:* разбей лагерь (кнопка «{$base}» → «🏕 Разбить лагерь»)", $svc->line(1));
    }

    // ── Markdown-безопасность готовой строки на КАЖДОМ шаге ────────────────────

    public function testRenderedLineMarkdownBalancedForEveryStep(): void
    {
        foreach (OnboardingChainCatalog::steps() as $step) {
            $svc = new FakePolarStarService();
            $svc->active = $step['title_en'];
            $line = (string) $svc->line(1);
            $this->assertNotSame('', $line, $step['title_en']);
            $this->assertSame(0, substr_count($line, '*') % 2, "line {$step['title_en']} непарные *");
            $this->assertSame(0, substr_count($line, '_') % 2, "line {$step['title_en']} непарные _");
            $this->assertStringStartsWith('🎯 *Сейчас:* ', $line);
        }
    }
}

/**
 * Test-double: подменяет killswitch + DB-seam'ы детерминированными значениями.
 *
 * @internal
 */
final class FakePolarStarService extends PolarStarService
{
    public bool $on = true;
    public ?string $active = null;
    /** @var array<string,int> */
    public array $progress = [];

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }

    protected function activeStepTitle(int $charId): ?string
    {
        return $this->active;
    }

    protected function progressCount(string $type, ?string $target, int $charId): int
    {
        return $this->progress[$type] ?? 0;
    }
}
