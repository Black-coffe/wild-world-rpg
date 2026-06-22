<?php

namespace Tests\Unit\Services\World;

use App\Services\World\NodeLevelCurve;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * WB4 (ADR-137 «Узлы») — гео-кривая узлов. Pure (GameSettings деградируют к дефолтам §2
 * без БД/ключей → детерминированно). Север = низкий Y, юг = высокий Y.
 *
 * @internal
 */
final class NodeLevelCurveTest extends CIUnitTestCase
{
    private NodeLevelCurve $curve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->curve = new NodeLevelCurve();
    }

    // ---- baseLevelForY (гео-старт-уровень) ----

    public function testSouthEdgeIsLevelOne(): void
    {
        $this->assertSame(1, $this->curve->baseLevelForY(999)); // крайний юг = новичковый L1
    }

    public function testNorthEdgeIsNorthBase(): void
    {
        $this->assertSame(50, $this->curve->baseLevelForY(0)); // крайний север = north_base default
    }

    public function testMonotonicNorthHarderThanSouth(): void
    {
        $north  = $this->curve->baseLevelForY(0);
        $center = $this->curve->baseLevelForY(500);
        $south  = $this->curve->baseLevelForY(999);
        $this->assertGreaterThan($center, $north);
        $this->assertGreaterThan($south, $center);
    }

    public function testOutOfRangeYClamped(): void
    {
        $this->assertSame(1, $this->curve->baseLevelForY(5000));   // > max Y → юг → 1
        $this->assertSame(50, $this->curve->baseLevelForY(-100));  // < 0 → север → 50
    }

    // ---- healthForLevel (кусочно §2) ----

    public function testHealthSegment1(): void
    {
        $this->assertSame(108, $this->curve->healthForLevel(1));   // 100 + 1*8
        $this->assertSame(500, $this->curve->healthForLevel(50));  // 100 + 50*8 (граница)
    }

    public function testHealthSegment2(): void
    {
        $this->assertSame(540, $this->curve->healthForLevel(51));   // 500 + 1*40
        $this->assertSame(4500, $this->curve->healthForLevel(150)); // 500 + 100*40
        $this->assertSame(5700, $this->curve->healthForLevel(180)); // 500 + 130*40 (потолок v1)
    }

    public function testHealthMonotonic(): void
    {
        $prev = 0;
        foreach ([1, 10, 50, 51, 100, 150, 180] as $lvl) {
            $hp = $this->curve->healthForLevel($lvl);
            $this->assertGreaterThan($prev, $hp);
            $prev = $hp;
        }
    }

    // ---- yBand ----

    public function testYBand(): void
    {
        $this->assertSame(0, $this->curve->yBand(999)); // юг
        $this->assertSame(2, $this->curve->yBand(500)); // центр
        $this->assertSame(4, $this->curve->yBand(0));   // глубокий север
    }

    // ---- escalatedLevel (WB5 гео-эскалация с per-точка cap) ----

    public function testEscalationFromZeroKillsIsBase(): void
    {
        // Свежая/сброшенная точка (kill_count=0) поднимается ровно к base_level.
        $this->assertSame(1, $this->curve->escalatedLevel(1, 0));   // юг
        $this->assertSame(50, $this->curve->escalatedLevel(50, 0)); // север
    }

    public function testEscalationStepDefaultOnePerKill(): void
    {
        // level_step default 1 → current_level − base_level = число расчисток (лор-точно).
        $this->assertSame(2, $this->curve->escalatedLevel(1, 1));
        $this->assertSame(6, $this->curve->escalatedLevel(1, 5));
        $this->assertSame(60, $this->curve->escalatedLevel(50, 10));
    }

    public function testPerPointCapHoldsSouthForNewbies(): void
    {
        // reincarnation_cap_per_band default 40 → южная L1-точка максимум L41, сколько ни бей.
        $this->assertSame(41, $this->curve->escalatedLevel(1, 40));
        $this->assertSame(41, $this->curve->escalatedLevel(1, 1000)); // не дорастает до неубиваемой
    }

    public function testGlobalMaxLevelCaps(): void
    {
        // base 150 + cap 40 = 190, но глобальный max_level default 180 связывает раньше.
        $this->assertSame(180, $this->curve->escalatedLevel(150, 1000));
    }

    public function testEscalationNeverBelowOne(): void
    {
        $this->assertSame(1, $this->curve->escalatedLevel(0, 0));
        $this->assertSame(1, $this->curve->escalatedLevel(-5, -5));
    }
}
