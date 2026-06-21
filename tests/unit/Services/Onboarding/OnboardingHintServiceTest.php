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

    /**
     * Анти-дрифт: подсказка несёт ключевые факты (media-off самодостаточность).
     * Расширено 2026-06-19: лагерь ставится рядом (далеко идти не надо) + про «Поход»
     * как дешёвую альтернативу шагам по одной клетке (прод-обращение новичка).
     */
    public function testFirstBaseHintTeachesCoreFacts(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_BASE)['text'] ?? '';
        foreach (['База', 'Строить', 'магазин', 'Далеко идти не надо', 'Поход'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Подсказка не упоминает «{$needle}».");
        }
    }

    public function testCatalogReturnsNullForUnknownKey(): void
    {
        $this->assertNull(OnboardingHintCatalog::get('no_such_hint'));
    }

    /**
     * E27 Часть 5 (ADR-126): хинт дейликов ведёт И на список дня, И на единый
     * экран «📜 Задания» — discoverability хаба в just-in-time момент.
     */
    public function testDailyTasksHintLinksToDailyListAndUnifiedScreen(): void
    {
        $hint = OnboardingHintCatalog::get(OnboardingHintCatalog::DAILY_TASKS);
        $this->assertNotNull($hint);

        $markupRaw = $hint['reply_markup'] ?? null;
        $this->assertIsString($markupRaw, 'У хинта дейликов нет кнопок.');
        $markup = json_decode($markupRaw, true);
        $this->assertIsArray($markup);

        $callbacks = [];
        array_walk_recursive($markup, static function ($value, $key) use (&$callbacks): void {
            if ($key === 'callback_data') {
                $callbacks[] = $value;
            }
        });

        $this->assertContains('dailyTasks', $callbacks, 'Нет кнопки на список заданий дня.');
        $this->assertContains('questAndTask', $callbacks, 'Нет кнопки на единый экран «Задания» — хаб не находим.');
    }

    /** Анти-дрифт: текст хинта дейликов упоминает единый экран (media-off самодостаточность). */
    public function testDailyTasksHintMentionsUnifiedScreen(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::DAILY_TASKS)['text'] ?? '';
        foreach (['задания дня', 'едином экране'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Хинт дейликов не упоминает «{$needle}».");
        }
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

    // ── E20 (ADR-120): хинт «Автоматизация» ──────────────────────────────────

    /** @return FakeHintService с окном automation-хинта 12-25 */
    private function automationSvc(): FakeHintService
    {
        $svc = new FakeHintService();
        $svc->gsIntMap = [
            'onboarding.automation_hint.min_level' => 12,
            'onboarding.automation_hint.max_level' => 25,
        ];

        return $svc;
    }

    public function testAutomationHintHappyPathSendsOnce(): void
    {
        $svc  = $this->automationSvc();
        $mid  = ['id' => 7, 'level' => 15, 'daily_tips_enabled' => 1];

        $this->assertTrue($svc->maybeSendAutomationHint($mid, 100));
        $this->assertCount(1, $svc->sent);
        $this->assertSame([OnboardingHintCatalog::AUTOMATION], $svc->recorded);

        // one-shot: повторно не шлём.
        $this->assertFalse($svc->maybeSendAutomationHint($mid, 100));
        $this->assertCount(1, $svc->sent);
    }

    public function testAutomationHintLevelWindowSuppresses(): void
    {
        $svc = $this->automationSvc();
        // Ниже окна — новичку рано.
        $this->assertFalse($svc->maybeSendAutomationHint(['id' => 7, 'level' => 8, 'daily_tips_enabled' => 1], 100));
        // Выше окна — ветерана не пингуем (анти soft-broadcast).
        $this->assertFalse($svc->maybeSendAutomationHint(['id' => 7, 'level' => 30, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testAutomationHintSuppressedWhenWorkshopOwned(): void
    {
        $svc = $this->automationSvc();
        $svc->workshopOwned = true;
        $this->assertFalse($svc->maybeSendAutomationHint(['id' => 7, 'level' => 15, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    /** Анти-дрифт: текст хинта учит пути и упоминает Ангар (media-off самодостаточность). */
    public function testAutomationHintTeachesCoreFacts(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::AUTOMATION)['text'] ?? '';
        foreach (['Мастерскую робототехники', 'Строить', 'Ангар'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Хинт не упоминает «{$needle}».");
        }
    }

    // ── Теплица (2026-06-18): хинт «выращивай свою еду» ──────────────────────

    /** Анти-дрифт: текст хинта учит пути к Теплице (media-off самодостаточность). */
    public function testGreenhouseHintTeachesCoreFacts(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::GREENHOUSE)['text'] ?? '';
        foreach (['Теплиц', 'Строить', 'еду'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Хинт теплицы не упоминает «{$needle}».");
        }
    }

    /** Хинт ведёт на экран построек (discoverability в just-in-time момент). */
    public function testGreenhouseHintLinksToBuild(): void
    {
        $markupRaw = OnboardingHintCatalog::get(OnboardingHintCatalog::GREENHOUSE)['reply_markup'] ?? null;
        $this->assertIsString($markupRaw, 'У хинта теплицы нет кнопок.');
        $markup = json_decode($markupRaw, true);
        $this->assertIsArray($markup);
        $callbacks = [];
        array_walk_recursive($markup, static function ($value, $key) use (&$callbacks): void {
            if ($key === 'callback_data') {
                $callbacks[] = $value;
            }
        });
        $this->assertContains('Build', $callbacks, 'Нет кнопки на экран построек.');
    }

    public function testGreenhouseTipHappyPathSendsOnce(): void
    {
        $svc    = new FakeHintService();
        $newbie = ['id' => 3, 'level' => 2, 'daily_tips_enabled' => 1];

        $this->assertTrue($svc->maybeSendGreenhouseTip($newbie, 100));
        $this->assertCount(1, $svc->sent);
        $this->assertSame([OnboardingHintCatalog::GREENHOUSE], $svc->recorded);

        // one-shot: повторно не шлём.
        $this->assertFalse($svc->maybeSendGreenhouseTip($newbie, 100));
        $this->assertCount(1, $svc->sent);
    }

    public function testGreenhouseTipLevelGateSuppresses(): void
    {
        $svc = new FakeHintService(); // maxLvl = 6
        $this->assertFalse($svc->maybeSendGreenhouseTip(['id' => 3, 'level' => 7, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testGreenhouseTipSuppressedWhenOwned(): void
    {
        $svc = new FakeHintService();
        $svc->greenhouseOwned = true;
        $this->assertFalse($svc->maybeSendGreenhouseTip(['id' => 3, 'level' => 2, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testGreenhouseTipRespectsOptOut(): void
    {
        $svc = new FakeHintService();
        $this->assertFalse($svc->maybeSendGreenhouseTip(['id' => 3, 'level' => 2, 'daily_tips_enabled' => 0], 100));
        $this->assertCount(0, $svc->sent);
    }

    // ── Первая постройка (2026-06-20, пере-срез A+B): горлышко OnbStepBuild ───────

    /** Анти-дрифт: текст учит пути к стройке + media-off самодостаточность. */
    public function testFirstBuildHintTeachesCoreFacts(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_BUILD)['text'] ?? '';
        foreach (['постройку', 'База', 'Строить'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Хинт первой постройки не упоминает «{$needle}».");
        }
    }

    /** Хинт ведёт на экран построек (discoverability в just-in-time момент). */
    public function testFirstBuildHintLinksToBuild(): void
    {
        $markupRaw = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_BUILD)['reply_markup'] ?? null;
        $this->assertIsString($markupRaw, 'У хинта первой постройки нет кнопок.');
        $markup = json_decode($markupRaw, true);
        $this->assertIsArray($markup);
        $callbacks = [];
        array_walk_recursive($markup, static function ($value, $key) use (&$callbacks): void {
            if ($key === 'callback_data') {
                $callbacks[] = $value;
            }
        });
        $this->assertContains('Build', $callbacks, 'Нет кнопки на экран построек.');
    }

    /** Happy path: новичок с базой, но без построек → шлём один раз. */
    public function testFirstBuildHintHappyPathSendsOnce(): void
    {
        $svc = new FakeHintService();
        $svc->baseExists = true; // база есть
        // hasBuildings = false по умолчанию — ни одной постройки
        $newbie = ['id' => 5, 'level' => 2, 'daily_tips_enabled' => 1];

        $this->assertTrue($svc->maybeSendFirstBuildHint($newbie, 100));
        $this->assertCount(1, $svc->sent);
        $this->assertSame([OnboardingHintCatalog::FIRST_BUILD], $svc->recorded);

        // one-shot: повторно не шлём.
        $this->assertFalse($svc->maybeSendFirstBuildHint($newbie, 100));
        $this->assertCount(1, $svc->sent);
    }

    /** Без базы — это сценарий FIRST_BASE, первая-постройка молчит. */
    public function testFirstBuildHintSuppressedWithoutBase(): void
    {
        $svc = new FakeHintService(); // baseExists = false
        $this->assertFalse($svc->maybeSendFirstBuildHint(['id' => 5, 'level' => 2, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    /** Уже есть постройка — горлышко пройдено, хинт молчит. */
    public function testFirstBuildHintSuppressedWhenHasBuilding(): void
    {
        $svc = new FakeHintService();
        $svc->baseExists   = true;
        $svc->hasBuildings = true;
        $this->assertFalse($svc->maybeSendFirstBuildHint(['id' => 5, 'level' => 2, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstBuildHintLevelGateSuppresses(): void
    {
        $svc = new FakeHintService(); // maxLvl = 6
        $svc->baseExists = true;
        $this->assertFalse($svc->maybeSendFirstBuildHint(['id' => 5, 'level' => 7, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstBuildHintKillswitchOffSuppresses(): void
    {
        $svc = new FakeHintService();
        $svc->baseExists = true;
        $svc->killswitch = false;
        $this->assertFalse($svc->maybeSendFirstBuildHint(['id' => 5, 'level' => 2, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstBuildHintRespectsOptOut(): void
    {
        $svc = new FakeHintService();
        $svc->baseExists = true;
        $this->assertFalse($svc->maybeSendFirstBuildHint(['id' => 5, 'level' => 2, 'daily_tips_enabled' => 0], 100));
        $this->assertCount(0, $svc->sent);
    }

    // ── Первый шаг (2026-06-20, пере-срез A+B): холодный старт OnbStepMove ────────

    /** Анти-дрифт: текст учит, где компас движения + media-off самодостаточность. */
    public function testFirstMoveHintTeachesCoreFacts(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_MOVE)['text'] ?? '';
        foreach (['Двигаться', 'Поход', 'шаг'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Хинт первого шага не упоминает «{$needle}».");
        }
    }

    /** Хинт ведёт на экран компаса движения (callback `move`). */
    public function testFirstMoveHintLinksToMove(): void
    {
        $markupRaw = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_MOVE)['reply_markup'] ?? null;
        $this->assertIsString($markupRaw, 'У хинта первого шага нет кнопок.');
        $markup = json_decode($markupRaw, true);
        $this->assertIsArray($markup);
        $callbacks = [];
        array_walk_recursive($markup, static function ($value, $key) use (&$callbacks): void {
            if ($key === 'callback_data') {
                $callbacks[] = $value;
            }
        });
        $this->assertContains('move', $callbacks, 'Нет кнопки на экран движения.');
    }

    /** Happy path: новичок открыл карту, ещё не двигался → шлём один раз. */
    public function testFirstMoveHintHappyPathSendsOnce(): void
    {
        $svc = new FakeHintService(); // barelyMoved = true по умолчанию
        $newbie = ['id' => 9, 'level' => 1, 'daily_tips_enabled' => 1];

        $this->assertTrue($svc->maybeSendFirstMoveHint($newbie, 100));
        $this->assertCount(1, $svc->sent);
        $this->assertSame([OnboardingHintCatalog::FIRST_MOVE], $svc->recorded);

        // one-shot: повторно не шлём.
        $this->assertFalse($svc->maybeSendFirstMoveHint($newbie, 100));
        $this->assertCount(1, $svc->sent);
    }

    /** Уже походил — знает «как», хинт молчит. */
    public function testFirstMoveHintSuppressedWhenAlreadyMoved(): void
    {
        $svc = new FakeHintService();
        $svc->barelyMoved = false;
        $this->assertFalse($svc->maybeSendFirstMoveHint(['id' => 9, 'level' => 1, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstMoveHintLevelGateSuppresses(): void
    {
        $svc = new FakeHintService(); // maxLvl = 6
        $this->assertFalse($svc->maybeSendFirstMoveHint(['id' => 9, 'level' => 7, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstMoveHintKillswitchOffSuppresses(): void
    {
        $svc = new FakeHintService();
        $svc->killswitch = false;
        $this->assertFalse($svc->maybeSendFirstMoveHint(['id' => 9, 'level' => 1, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstMoveHintRespectsOptOut(): void
    {
        $svc = new FakeHintService();
        $this->assertFalse($svc->maybeSendFirstMoveHint(['id' => 9, 'level' => 1, 'daily_tips_enabled' => 0], 100));
        $this->assertCount(0, $svc->sent);
    }

    // ── Первый крафт (2026-06-21, пере-срез A+B): горлышко OnbStepCraft ──────────

    /** Анти-дрифт: текст учит, с чего начать крафт + media-off самодостаточность. */
    public function testFirstCraftHintTeachesCoreFacts(): void
    {
        $text = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_CRAFT)['text'] ?? '';
        foreach (['предмет', 'Общий крафт', 'Повязка'] as $needle) {
            $this->assertStringContainsString($needle, $text, "Хинт первого крафта не упоминает «{$needle}».");
        }
    }

    /** Хинт ведёт в раздел «Общий крафт» (callback `generalCraft`) — без верстака. */
    public function testFirstCraftHintLinksToGeneralCraft(): void
    {
        $markupRaw = OnboardingHintCatalog::get(OnboardingHintCatalog::FIRST_CRAFT)['reply_markup'] ?? null;
        $this->assertIsString($markupRaw, 'У хинта первого крафта нет кнопок.');
        $markup = json_decode($markupRaw, true);
        $this->assertIsArray($markup);
        $callbacks = [];
        array_walk_recursive($markup, static function ($value, $key) use (&$callbacks): void {
            if ($key === 'callback_data') {
                $callbacks[] = $value;
            }
        });
        $this->assertContains('generalCraft', $callbacks, 'Нет кнопки на раздел общего крафта.');
    }

    /** Happy path: новичок открыл крафт-хаб, ещё ничего не крафтил → шлём один раз. */
    public function testFirstCraftHintHappyPathSendsOnce(): void
    {
        $svc = new FakeHintService(); // hasCrafted = false по умолчанию
        $newbie = ['id' => 11, 'level' => 1, 'daily_tips_enabled' => 1];

        $this->assertTrue($svc->maybeSendFirstCraftHint($newbie, 100));
        $this->assertCount(1, $svc->sent);
        $this->assertSame([OnboardingHintCatalog::FIRST_CRAFT], $svc->recorded);

        // one-shot: повторно не шлём.
        $this->assertFalse($svc->maybeSendFirstCraftHint($newbie, 100));
        $this->assertCount(1, $svc->sent);
    }

    /** Уже что-то скрафтил — навык освоен, хинт молчит. */
    public function testFirstCraftHintSuppressedWhenAlreadyCrafted(): void
    {
        $svc = new FakeHintService();
        $svc->hasCrafted = true;
        $this->assertFalse($svc->maybeSendFirstCraftHint(['id' => 11, 'level' => 1, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstCraftHintLevelGateSuppresses(): void
    {
        $svc = new FakeHintService(); // maxLvl = 6
        $this->assertFalse($svc->maybeSendFirstCraftHint(['id' => 11, 'level' => 7, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstCraftHintKillswitchOffSuppresses(): void
    {
        $svc = new FakeHintService();
        $svc->killswitch = false;
        $this->assertFalse($svc->maybeSendFirstCraftHint(['id' => 11, 'level' => 1, 'daily_tips_enabled' => 1], 100));
        $this->assertCount(0, $svc->sent);
    }

    public function testFirstCraftHintRespectsOptOut(): void
    {
        $svc = new FakeHintService();
        $this->assertFalse($svc->maybeSendFirstCraftHint(['id' => 11, 'level' => 1, 'daily_tips_enabled' => 0], 100));
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
    public bool $hasBuildings = false;
    public bool $barelyMoved = true;
    public bool $hasCrafted = false;
    public bool $workshopOwned = false;
    public bool $greenhouseOwned = false;
    /** @var array<string, int> per-key значения gsInt (E20: окно automation-хинта) */
    public array $gsIntMap = [];
    /** @var list<string> */
    public array $shownKeys = [];

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->killswitch;
    }

    protected function gsInt(string $key, int $default): int
    {
        return $this->gsIntMap[$key] ?? $this->maxLvl;
    }

    public function alreadyShown(int $charId, string $hintKey): bool
    {
        return in_array($hintKey, $this->shownKeys, true);
    }

    protected function hasActiveBase(int $charId): bool
    {
        return $this->baseExists;
    }

    protected function ownsRoboticsWorkshop(int $charId): bool
    {
        return $this->workshopOwned;
    }

    protected function ownsGreenhouse(int $charId): bool
    {
        return $this->greenhouseOwned;
    }

    protected function hasAnyBuilding(int $charId): bool
    {
        return $this->hasBuildings;
    }

    protected function hasBarelyMoved(int $charId): bool
    {
        return $this->barelyMoved;
    }

    protected function hasCraftedAnything(int $charId): bool
    {
        return $this->hasCrafted;
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
