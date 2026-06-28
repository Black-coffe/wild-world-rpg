<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\NewbieGreeterService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S2 (ROADMAP-RETENTION-10, ADR-144) — «скриптовая первая встреча» (NewbieGreeterService).
 *
 * Лочит: чистый выбор встречающего (selectBestGreeter — квестгивер важнее, затем min level),
 * гейты оркестрации placeGreeterForNewChar (killswitch / one-shot / нет NPC / нет клетки /
 * invalid charId), reactive-координату цели и markdown-баланс нарратива. БД/Telegram —
 * через test-double. Tier-3 — живой /start чистого чара: 🧑 у спавна + кнопка «👤 Незнакомец».
 *
 * @internal
 */
final class NewbieGreeterServiceTest extends CIUnitTestCase
{
    // ── selectBestGreeter (чистая) ──────────────────────────────────────────────

    public function testSelectPrefersQuestgiverOverLowerLevel(): void
    {
        $rows = [
            ['id' => 10, 'level' => 5, 'offers_quest_title_en' => null],          // не квестгивер, ниже
            ['id' => 11, 'level' => 6, 'offers_quest_title_en' => 'CollectWood'], // квестгивер, выше
        ];
        // Квестгивер приоритетнее, несмотря на больший level.
        $this->assertSame(11, NewbieGreeterService::selectBestGreeter($rows));
    }

    public function testSelectLowestLevelAmongQuestgivers(): void
    {
        $rows = [
            ['id' => 20, 'level' => 12, 'offers_quest_title_en' => 'GrowthMilestone10'],
            ['id' => 21, 'level' => 6, 'offers_quest_title_en' => 'CollectIronstoneCache'],
        ];
        $this->assertSame(21, NewbieGreeterService::selectBestGreeter($rows));
    }

    public function testSelectFallsBackToNonQuestgiverWhenNoneOffer(): void
    {
        $rows = [
            ['id' => 30, 'level' => 8, 'offers_quest_title_en' => null],
            ['id' => 31, 'level' => 6, 'offers_quest_title_en' => null],
        ];
        $this->assertSame(31, NewbieGreeterService::selectBestGreeter($rows));
    }

    public function testSelectEmptyReturnsZero(): void
    {
        $this->assertSame(0, NewbieGreeterService::selectBestGreeter([]));
    }

    public function testSelectIgnoresMalformedRows(): void
    {
        $rows = [
            'not-an-array',
            ['level' => 6, 'offers_quest_title_en' => 'X'], // нет id → пропуск
            ['id' => 0, 'level' => 6, 'offers_quest_title_en' => 'X'], // id<=0 → пропуск
            ['id' => 42, 'level' => 9, 'offers_quest_title_en' => null],
        ];
        $this->assertSame(42, NewbieGreeterService::selectBestGreeter($rows));
    }

    // ── Гейты оркестрации ───────────────────────────────────────────────────────

    public function testKillswitchOffNoOp(): void
    {
        $svc = new FakeNewbieGreeterService();
        $svc->on = false;
        $this->assertNull($svc->placeGreeterForNewChar(491, self::spawn(), 100));
        $this->assertSame(0, $svc->inserts, 'OFF → ничего не вставлено (byte-identical)');
        $this->assertSame(0, $svc->markers);
    }

    public function testAlreadyPlacedNoOp(): void
    {
        $svc = new FakeNewbieGreeterService();
        $svc->on      = true;
        $svc->placed  = true; // one-shot уже отработал
        $this->assertNull($svc->placeGreeterForNewChar(491, self::spawn(), 100));
        $this->assertSame(0, $svc->inserts);
    }

    public function testNoNpcAvailableNoOp(): void
    {
        $svc = new FakeNewbieGreeterService();
        $svc->on    = true;
        $svc->npcId = 0; // пул пуст
        $this->assertNull($svc->placeGreeterForNewChar(491, self::spawn(), 100));
        $this->assertSame(0, $svc->inserts);
    }

    public function testInvalidCharIdNoOp(): void
    {
        $svc = new FakeNewbieGreeterService();
        $svc->on = true;
        $this->assertNull($svc->placeGreeterForNewChar(0, self::spawn(), 100));
        $this->assertNull($svc->placeGreeterForNewChar(-5, self::spawn(), 100));
        $this->assertSame(0, $svc->inserts);
    }

    public function testNoValidCellNoOp(): void
    {
        $svc = new FakeNewbieGreeterService();
        $svc->on       = true;
        $svc->landCell = null; // ни одно направление не дало сушу
        $this->assertNull($svc->placeGreeterForNewChar(491, self::spawn(), 100));
        $this->assertSame(0, $svc->inserts);
        $this->assertSame(0, $svc->markers);
    }

    public function testOccupiedCellSkipped(): void
    {
        $svc = new FakeNewbieGreeterService();
        $svc->on       = true;
        $svc->occupied = true; // все клетки заняты → не ставим второго
        $this->assertNull($svc->placeGreeterForNewChar(491, self::spawn(), 100));
        $this->assertSame(0, $svc->inserts);
    }

    public function testHappyPathPlacesAndMarks(): void
    {
        $svc = new FakeNewbieGreeterService();
        $svc->on       = true;
        $svc->npcId    = 5;
        $text          = $svc->placeGreeterForNewChar(491, self::spawn(), 100);
        $this->assertNotNull($text);
        $this->assertSame(1, $svc->inserts, 'ровно один спавн');
        $this->assertSame(1, $svc->markers, 'ровно один one-shot маркер');
        $this->assertSame(5, $svc->lastNpcId);
        // distance=1, первый кандидат = север (dx=0,dy=-1) от спавна (500,950) → (500,949).
        $this->assertSame(500, $svc->lastCell['coordinate_x']);
        $this->assertSame(949, $svc->lastCell['coordinate_y']);
    }

    public function testNarrativeMarkdownBalancedAndCues(): void
    {
        $svc  = new FakeNewbieGreeterService();
        $text = $svc->buildGreeterNarrative('к северу', '⬆️');
        $this->assertSame(0, substr_count($text, '*') % 2, 'парные *');
        $this->assertSame(0, substr_count($text, '_') % 2, 'парные _');
        $this->assertStringContainsString('Незнакомец', $text);
        $this->assertStringContainsString('🧑', $text);
        $this->assertStringContainsString('к северу', $text);
    }

    /** @return array<string, int> */
    private static function spawn(): array
    {
        return ['coordinate_x' => 500, 'coordinate_y' => 950];
    }
}

/**
 * Test-double: подменяет killswitch/distance/БД-seam'ы детерминированно (без БД/Telegram).
 * Считает вставки спавнов и one-shot маркеры, запоминает последнюю клетку/npcId.
 *
 * @internal
 */
final class FakeNewbieGreeterService extends NewbieGreeterService
{
    public bool $on        = true;
    public bool $placed    = false;
    public int $npcId      = 5;
    public bool $occupied  = false;
    /** @var array<string, int>|null */
    public ?array $landCell = ['cell_number' => 500949, 'coordinate_x' => 500, 'coordinate_y' => 949];
    public int $inserts    = 0;
    public int $markers    = 0;
    public int $lastNpcId  = 0;
    /** @var array<int|string, mixed> */
    public array $lastCell = [];

    protected function enabled(): bool
    {
        return $this->on;
    }

    protected function distance(): int
    {
        return 1;
    }

    protected function alreadyPlaced(int $charId): bool
    {
        return $this->placed;
    }

    protected function pickGreeterNpcId(): int
    {
        return $this->npcId;
    }

    protected function findLandCell(int $x, int $y): ?array
    {
        if ($this->landCell === null) {
            return null;
        }

        // Возвращаем «сушу» строго на запрошенных координатах (проверяем reactive-смещение).
        return ['cell_number' => $x * 1000 + $y, 'coordinate_x' => $x, 'coordinate_y' => $y];
    }

    protected function cellOccupied(int $cellNumber): bool
    {
        return $this->occupied;
    }

    protected function insertSpawn(int $npcId, array $cell): void
    {
        $this->inserts++;
        $this->lastNpcId = $npcId;
        $this->lastCell  = $cell;
    }

    protected function writeMarker(int $charId, int $chatId): void
    {
        $this->markers++;
    }
}
