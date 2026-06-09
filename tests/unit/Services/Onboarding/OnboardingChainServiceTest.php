<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\OnboardingChainService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-103 Часть B Слой 2 — обучающая квест-цепочка «Первые шаги выжившего».
 *
 * Каталог — pure-данные. Оркестрацию (killswitch / level / идемпотентность назначения,
 * event-флаг открытия базы) — через test-double, подменяющий DB/GameSettings-seam'ы
 * (memory feedback_taskhandler_telegram_init_in_tests: без БД на CI).
 *
 * @internal
 */
final class OnboardingChainServiceTest extends CIUnitTestCase
{
    // ── Каталог ───────────────────────────────────────────────────────────────

    public function testCatalogHasEightOrderedSteps(): void
    {
        $steps = OnboardingChainCatalog::steps();
        $this->assertCount(8, $steps);
        $this->assertSame(OnboardingChainCatalog::STEP_MOVE, $steps[0]['title_en']);
        $this->assertSame(OnboardingChainCatalog::STEP_BUILD, $steps[5]['title_en']);
        $this->assertSame(OnboardingChainCatalog::STEP_SELL, $steps[6]['title_en']);
        $this->assertSame(OnboardingChainCatalog::STEP_LEVEL5, $steps[7]['title_en']);
        $this->assertSame(OnboardingChainCatalog::ROOT, $steps[0]['title_en']);
    }

    public function testEveryStepHasNonEmptyTextsAndType(): void
    {
        foreach (OnboardingChainCatalog::steps() as $step) {
            $this->assertNotSame('', trim($step['title_ru']));
            $this->assertNotSame('', trim($step['description']));
            $this->assertNotSame('', trim($step['done_text']));
            $this->assertNotSame('', trim($step['objective_type']));
            $this->assertGreaterThanOrEqual(1, $step['objective_qty']);
        }
    }

    /** Все title_en несут общий префикс — по нему handler опознаёт онбординг-шаги. */
    public function testAllStepsCarryChainPrefix(): void
    {
        foreach (OnboardingChainCatalog::steps() as $step) {
            $this->assertTrue(OnboardingChainCatalog::isChainQuest($step['title_en']), $step['title_en']);
        }
        $this->assertFalse(OnboardingChainCatalog::isChainQuest('GrowthMilestone5'));
    }

    /** Цепочка несёт критичные онбординг-типы (анти-дрифт состава этапов). */
    public function testChainUsesOnboardingObjectiveTypes(): void
    {
        $types = array_column(OnboardingChainCatalog::steps(), 'objective_type');
        foreach (['explore_cells', 'collect_resource', 'craft_any', 'claim_base', 'open_base_screen', 'any_building', 'sell_any', 'level_milestone'] as $t) {
            $this->assertContains($t, $types, "Цепочка не содержит шаг типа {$t}.");
        }
    }

    public function testCompletionReturnsGuidanceForEachStep(): void
    {
        foreach (OnboardingChainCatalog::steps() as $step) {
            $c = OnboardingChainCatalog::completion($step['title_en']);
            $this->assertNotNull($c);
            $this->assertSame($step['done_text'], $c['text']);
            $this->assertNotSame('', trim($c['reply_markup']));
        }
        $this->assertNull(OnboardingChainCatalog::completion('NotAnOnbStep'));
    }

    /** done_text шага «открыл базу» учит открывать базу кнопкой (урок инцидента m8k2b). */
    public function testClaimBaseGuidanceTeachesOpeningViaButton(): void
    {
        $c = OnboardingChainCatalog::completion(OnboardingChainCatalog::STEP_CLAIM_BASE);
        $this->assertNotNull($c);
        $this->assertStringContainsString('База', $c['text']);
    }

    // ── ensureChainAssigned ───────────────────────────────────────────────────

    public function testAssignsRootStepOnHappyPath(): void
    {
        $svc = new FakeChainService();
        $this->assertTrue($svc->ensureChainAssigned(['id' => 1, 'level' => 1]));
        $this->assertCount(1, $svc->inserted);
        $this->assertSame(['quest_id' => 77, 'char_id' => 1], $svc->inserted[0]);
    }

    public function testAssignIsIdempotent(): void
    {
        $svc = new FakeChainService();
        $this->assertTrue($svc->ensureChainAssigned(['id' => 1, 'level' => 1]));
        // Второй раз — шаг уже есть → no-op.
        $this->assertFalse($svc->ensureChainAssigned(['id' => 1, 'level' => 1]));
        $this->assertCount(1, $svc->inserted);
    }

    public function testKillswitchOffSuppressesAssign(): void
    {
        $svc = new FakeChainService();
        $svc->enabled = false;
        $this->assertFalse($svc->ensureChainAssigned(['id' => 1, 'level' => 1]));
        $this->assertCount(0, $svc->inserted);
    }

    public function testLevelGateSuppressesAssign(): void
    {
        $svc = new FakeChainService();
        $svc->maxLvl = 6;
        $this->assertFalse($svc->ensureChainAssigned(['id' => 1, 'level' => 7]));
        $this->assertCount(0, $svc->inserted);
    }

    public function testMissingRootQuestSuppressesAssign(): void
    {
        $svc = new FakeChainService();
        $svc->rootId = 0; // контент не засижен
        $this->assertFalse($svc->ensureChainAssigned(['id' => 1, 'level' => 1]));
        $this->assertCount(0, $svc->inserted);
    }

    public function testZeroCharIdSuppressesAssign(): void
    {
        $svc = new FakeChainService();
        $this->assertFalse($svc->ensureChainAssigned(['id' => 0, 'level' => 1]));
        $this->assertCount(0, $svc->inserted);
    }

    // ── recordBaseOpened / baseOpened ─────────────────────────────────────────

    public function testRecordBaseOpenedHappyPathOnce(): void
    {
        $svc = new FakeChainService();
        $this->assertFalse($svc->baseOpened(1));
        $svc->recordBaseOpened(['id' => 1, 'level' => 2], 555);
        $this->assertTrue($svc->baseOpened(1));
        $this->assertCount(1, $svc->events);

        // Повторно — уже записано → no-op.
        $svc->recordBaseOpened(['id' => 1, 'level' => 2], 555);
        $this->assertCount(1, $svc->events);
    }

    public function testRecordBaseOpenedKillswitchOff(): void
    {
        $svc = new FakeChainService();
        $svc->enabled = false;
        $svc->recordBaseOpened(['id' => 1, 'level' => 1], 555);
        $this->assertCount(0, $svc->events);
        $this->assertFalse($svc->baseOpened(1));
    }

    public function testRecordBaseOpenedLevelGate(): void
    {
        $svc = new FakeChainService();
        $svc->maxLvl = 6;
        $svc->recordBaseOpened(['id' => 1, 'level' => 9], 555);
        $this->assertCount(0, $svc->events);
    }
}

/**
 * Test-double: подменяет GameSettings/DB-seam'ы детерминированными значениями.
 *
 * @internal
 */
final class FakeChainService extends OnboardingChainService
{
    public bool $enabled = true;
    public int $maxLvl = 6;
    public int $rootId = 77;
    /** @var list<array{quest_id:int,char_id:int}> */
    public array $inserted = [];
    /** @var list<int> */
    public array $events = [];

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->enabled;
    }

    protected function gsInt(string $key, int $default): int
    {
        return $this->maxLvl;
    }

    protected function rootQuestId(): int
    {
        return $this->rootId;
    }

    protected function hasStep(int $charId, int $questId): bool
    {
        foreach ($this->inserted as $i) {
            if ($i['char_id'] === $charId && $i['quest_id'] === $questId) {
                return true;
            }
        }
        return false;
    }

    protected function insertStep(int $charId, int $questId, string $titleRu): void
    {
        $this->inserted[] = ['quest_id' => $questId, 'char_id' => $charId];
    }

    protected function hasBaseOpenedEvent(int $charId): bool
    {
        return in_array($charId, $this->events, true);
    }

    protected function writeBaseOpenedEvent(int $charId, int $chatId): void
    {
        $this->events[] = $charId;
    }
}
