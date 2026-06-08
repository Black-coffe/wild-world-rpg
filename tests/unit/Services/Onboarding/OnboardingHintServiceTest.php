<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\OnboardingHintCatalog;
use App\Services\Onboarding\OnboardingHintService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-103 Часть B Слой 1 — контекстные one-shot подсказки.
 *
 * Каталог проверяем как pure-данные. Оркестрацию гейтов (killswitch / opt-out /
 * one-shot / level / база) — через test-double, подменяющий DB/Telegram-seam'ы
 * (memory feedback_taskhandler_telegram_init_in_tests: не дёргать Request на CI).
 *
 * @internal
 */
final class OnboardingHintServiceTest extends CIUnitTestCase
{
    // ── Каталог ───────────────────────────────────────────────────────────────

    public function testCatalogHasFirstBaseHint(): void
    {
        $hint = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_BASE);
        $this->assertNotNull($hint);
        $this->assertNotSame('', trim($hint['text']));
    }

    /** Анти-дрифт: подсказка несёт ключевые факты (media-off самодостаточность). */
    public function testFirstBaseHintTeachesCoreFacts(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_BASE)['text'] ?? '';
        foreach (['База', 'Строить', 'магазин'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Подсказка не упоминает «{$needle}».");
        }
    }

    public function testCatalogReturnsNullForUnknownKey(): void
    {
        $this->assertNull(OnboardingHintCatalog::get('no_such_hint'));
    }

    public function testEveryCatalogEntryHasNonEmptyText(): void
    {
        foreach (OnboardingHintCatalog::all() as $key => $hint) {
            $this->assertArrayHasKey('text', $hint, "У подсказки '{$key}' нет text.");
            $this->assertNotSame('', trim($hint['text']), "У подсказки '{$key}' пустой text.");
        }
    }

    // ── Оркестрация гейтов ────────────────────────────────────────────────────

    public function testFirstBaseTipHappyPathSendsOnce(): void
    {
        $svc = new FakeHintService();
        $newbie = ['id' => 1, 'level' => 1, 'daily_tips_enabled' => 1];

        $this->assertTrue($svc->maybeSendFirstBaseTip($newbie, 100));
        $this->assertCount(1, $svc->sent);
        $this->assertSame([OnboardingHintCatalog::FIRST_BASE], $svc->recorded);

        // Повторно — уже показано → no-op.
        $this->assertFalse($svc->maybeSendFirstBaseTip($newbie, 100));
        $this->assertCount(1, $svc->sent);
    }

    public function testKillswitchOffSuppresses(): void
    {
        $svc = new FakeHintService();
        $svc->killswitch = false;
        $this->assertFalse($svc->maybeSendFirstBaseTip(['id' => 1, 'level' => 1, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testOptOutSuppresses(): void
    {
        $svc = new FakeHintService();
        $this->assertFalse($svc->maybeSendFirstBaseTip(['id' => 1, 'level' => 1, 'daily_tips_enabled' => 0], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testLevelGateSuppresses(): void
    {
        $svc = new FakeHintService();
        $svc->maxLvl = 6;
        $this->assertFalse($svc->maybeSendFirstBaseTip(['id' => 1, 'level' => 7, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testExistingBaseSuppresses(): void
    {
        $svc = new FakeHintService();
        $svc->baseExists = true;
        $this->assertFalse($svc->maybeSendFirstBaseTip(['id' => 1, 'level' => 1, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }
}

/**
 * Test-double: подменяет DB/Telegram-seam'ы детерминированными значениями.
 *
 * @internal
 */
final class FakeHintService extends OnboardingHintService
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    /** @var list<string> */
    public array $recorded = [];
    public bool $killswitch = true;
    public int $maxLvl = 6;
    public bool $baseExists = false;
    /** @var list<string> */
    public array $shownKeys = [];

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->killswitch;
    }

    protected function gsInt(string $key, int $default): int
    {
        return $this->maxLvl;
    }

    public function alreadyShown(int $charId, string $hintKey): bool
    {
        return in_array($hintKey, $this->shownKeys, true);
    }

    protected function hasActiveBase(int $charId): bool
    {
        return $this->baseExists;
    }

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }

    protected function recordShown(int $charId, int $chatId, string $hintKey): void
    {
        $this->recorded[]  = $hintKey;
        $this->shownKeys[] = $hintKey;
    }
}
