<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram\Commands\Actions;

use App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * E2 (ROADMAP-100) — UX края мира для step-движения. Чистая статическая
 * availableDirections() считает, по каким из 8 направлений из клетки есть ход
 * в пределах сетки 0..999. Покрывает угол/кромку/центр (анти-регресс границ).
 *
 * @internal
 */
final class MoveCharacterEdgeOfWorldTest extends CIUnitTestCase
{
    public function testCenterHasAllEightDirections(): void
    {
        $dirs = MoveCharacterToDirectionAction::availableDirections(500, 500);
        $this->assertCount(8, $dirs);
        $this->assertContains('north', $dirs);
        $this->assertContains('southeast', $dirs);
    }

    public function testNorthWestCornerCellOne(): void
    {
        // (0,0) = cell 1 (исторический осадок). Из угла можно только на юг/восток/юго-восток.
        $dirs = MoveCharacterToDirectionAction::availableDirections(0, 0);
        $this->assertSame(['south', 'east', 'southeast'], $dirs);
    }

    public function testSouthEastCorner(): void
    {
        // (999,999) — противоположный угол: только север/запад/северо-запад.
        $dirs = MoveCharacterToDirectionAction::availableDirections(999, 999);
        $this->assertSame(['north', 'west', 'northwest'], $dirs);
    }

    public function testSouthernEdgeWhereNewbiesSpawn(): void
    {
        // Y=999 — южная кромка, куда спавнят новичков (Y>=900). «Юг» и южные
        // диагонали недоступны → именно тут раньше был немой отказ.
        $dirs = MoveCharacterToDirectionAction::availableDirections(500, 999);
        $this->assertNotContains('south', $dirs);
        $this->assertNotContains('southwest', $dirs);
        $this->assertNotContains('southeast', $dirs);
        $this->assertContains('north', $dirs);
        $this->assertContains('east', $dirs);
        $this->assertContains('west', $dirs);
    }

    public function testNorthernEdge(): void
    {
        // Y=0 — северная кромка: «север» и северные диагонали недоступны.
        $dirs = MoveCharacterToDirectionAction::availableDirections(500, 0);
        $this->assertNotContains('north', $dirs);
        $this->assertNotContains('northwest', $dirs);
        $this->assertNotContains('northeast', $dirs);
        $this->assertContains('south', $dirs);
    }

    public function testWesternEdge(): void
    {
        $dirs = MoveCharacterToDirectionAction::availableDirections(0, 500);
        $this->assertNotContains('west', $dirs);
        $this->assertNotContains('northwest', $dirs);
        $this->assertNotContains('southwest', $dirs);
        $this->assertContains('east', $dirs);
    }
}
