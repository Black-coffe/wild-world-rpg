<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\WinBeatService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139, спина-слайс 4) — win-beat «глава 1 закрыта».
 *
 * Killswitch — через test-double (override gsBool); шаги/прогресс — против реального
 * OnboardingChainCatalog (детерминированные данные, без БД на CI). Tier-3 — живой
 * завершённый шаг на testbot (полоса прогресса в Telegram).
 *
 * @internal
 */
final class WinBeatServiceTest extends CIUnitTestCase
{
    public function testEnabledReadsFlag(): void
    {
        $on  = new FakeWinBeatService();
        $on->on = true;
        $this->assertTrue($on->enabled());

        $off = new FakeWinBeatService();
        $off->on = false;
        $this->assertFalse($off->enabled());
    }

    /** OFF → compose возвращает null для ЛЮБОГО шага → caller шлёт легаси-текст byte-identical. */
    public function testDisabledReturnsNull(): void
    {
        $svc = new FakeWinBeatService();
        $svc->on = false;

        $this->assertNull($svc->compose(OnboardingChainCatalog::STEP_CRAFT, 'done', 150));
        $this->assertNull($svc->compose(OnboardingChainCatalog::STEP_LEVEL5, 'done', 500));
    }

    /** Не-онбординг title_en → null даже при ON (win-beat только для цепочки). */
    public function testNonChainStepReturnsNull(): void
    {
        $svc = new FakeWinBeatService();
        $svc->on = true;

        $this->assertNull($svc->compose('NotAnOnbStep', 'done', 100));
        $this->assertNull($svc->compose('', 'done', 100));
    }

    /** Промежуточный шаг (Craft = 3-й из 8): done_text + полоса прогресса + награда «за шаг». */
    public function testMidStepAppendsProgressBar(): void
    {
        $svc = new FakeWinBeatService();
        $svc->on = true;

        $out = $svc->compose(OnboardingChainCatalog::STEP_CRAFT, 'ТЕЛО_ПОДСКАЗКИ', 150);
        $this->assertNotNull($out);
        $this->assertStringContainsString('ТЕЛО_ПОДСКАЗКИ', $out);                 // сохранён done_text
        $this->assertStringContainsString('🟩🟩🟩⬜⬜⬜⬜⬜', $out);               // 3 заполнено из 8
        $this->assertStringContainsString('Глава 1 — 3 из 8', $out);
        $this->assertStringContainsString('🏆 +150 золота за шаг.', $out);
        $this->assertStringNotContainsString('ГЛАВА 1 ЗАКРЫТА', $out);            // не капстон
    }

    /** Промежуточный шаг без награды (reward=0) → строки награды нет. */
    public function testMidStepZeroRewardNoRewardLine(): void
    {
        $svc = new FakeWinBeatService();
        $svc->on = true;

        $out = $svc->compose(OnboardingChainCatalog::STEP_MOVE, 'x', 0);
        $this->assertNotNull($out);
        $this->assertStringContainsString('Глава 1 — 1 из 8', $out);
        $this->assertStringNotContainsString('🏆', $out);
    }

    /** Финальный шаг → капстон «глава 1 закрыта»: полная полоса, «8 из 8», награда без «за шаг». */
    public function testFinalStepCapstone(): void
    {
        $svc = new FakeWinBeatService();
        $svc->on = true;

        $out = $svc->compose(OnboardingChainCatalog::STEP_LEVEL5, 'СТАРЫЙ_ТЕКСТ', 500);
        $this->assertNotNull($out);
        $this->assertStringContainsString('🏁 *ГЛАВА 1 ЗАКРЫТА*', $out);
        $this->assertStringContainsString('🟩🟩🟩🟩🟩🟩🟩🟩', $out);            // 8/8 заполнено
        $this->assertStringContainsString('8 из 8', $out);
        $this->assertStringContainsString('🏆 +500 золота.', $out);
        $this->assertStringNotContainsString('за шаг', $out);                    // капстон ≠ «за шаг»
        $this->assertStringNotContainsString('СТАРЫЙ_ТЕКСТ', $out);              // капстон ЗАМЕНЯЕТ done_text
        $this->assertStringContainsString('Загляни в «Квесты»', $out);          // CTA-онбординг
    }

    /** Полоса прогресса всегда равна общему числу шагов цепочки (нет дрейфа при росте). */
    public function testProgressBarLengthMatchesTotal(): void
    {
        $svc   = new FakeWinBeatService();
        $svc->on = true;
        $total = OnboardingChainCatalog::total();

        $out = $svc->compose(OnboardingChainCatalog::STEP_GATHER, 'x', 10);
        $this->assertNotNull($out);
        $cells = substr_count($out, '🟩') + substr_count($out, '⬜');
        $this->assertSame($total, $cells);
    }

    /** Markdown-баланс (парные * и _) для КАЖДОГО шага — иначе Telegram-render ломается. */
    public function testMarkdownBalancedForEveryStep(): void
    {
        $svc = new FakeWinBeatService();
        $svc->on = true;

        foreach (OnboardingChainCatalog::steps() as $step) {
            $out = $svc->compose($step['title_en'], $step['done_text'], $step['reward']);
            $this->assertNotNull($out, "compose null для {$step['title_en']}");
            $this->assertSame(0, substr_count($out, '*') % 2, "Непарные * в {$step['title_en']}");
            $this->assertSame(0, substr_count($out, '_') % 2, "Непарные _ в {$step['title_en']}");
        }
    }
}

/**
 * Test-double: killswitch через переопределение единственного seam'а gsBool().
 *
 * @internal
 */
final class FakeWinBeatService extends WinBeatService
{
    public bool $on = true;

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }
}
