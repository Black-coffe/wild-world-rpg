<?php

namespace Tests\Unit\Services\PVE;

use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use App\Models\MapModel;
use App\Services\PVE\PvpDamageCalculator;
use App\Services\PVE\PvpEquipmentRepository;
use App\Services\PVE\PvpFormulaService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\GameBalance;

/**
 * F2.3b Step 2 — unit-тесты на pure-методы PvpDamageCalculator.
 * computeDamage / computeEquipmentDamage делают DB I/O через
 * PvpEquipmentRepository — их fixture-fence в integration test'е.
 *
 * @internal
 */
final class PvpDamageCalculatorTest extends CIUnitTestCase
{
    private PvpDamageCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new PvpDamageCalculator(null, null, new GameBalance());
    }

    // ---- computeArmorResistance ----

    public function testNoOutfitsZeroResistance(): void
    {
        $this->assertSame(0.0, $this->calc->computeArmorResistance([], 'Physical'));
    }

    public function testSingleOutfitPhysical(): void
    {
        $outfits = [['physical_resistance' => 30, 'fire_resistance' => 0, 'poison_resistance' => 0]];
        // 30/100 = 0.3
        $this->assertSame(0.3, $this->calc->computeArmorResistance($outfits, 'Physical'));
    }

    public function testMultipleOutfitsSummed(): void
    {
        $outfits = [
            ['physical_resistance' => 20, 'fire_resistance' => 0, 'poison_resistance' => 0],
            ['physical_resistance' => 15, 'fire_resistance' => 0, 'poison_resistance' => 0],
            ['physical_resistance' => 10, 'fire_resistance' => 0, 'poison_resistance' => 0],
        ];
        // (20+15+10)/100 = 0.45 (IEEE-754 → assertEqualsWithDelta)
        $this->assertEqualsWithDelta(0.45, $this->calc->computeArmorResistance($outfits, 'Physical'), 1e-9);
    }

    public function testCappedAt09(): void
    {
        // 200% physical → 2.0, clamp на 0.9.
        $outfits = [
            ['physical_resistance' => 100, 'fire_resistance' => 0, 'poison_resistance' => 0],
            ['physical_resistance' => 100, 'fire_resistance' => 0, 'poison_resistance' => 0],
        ];
        $this->assertSame(0.9, $this->calc->computeArmorResistance($outfits, 'Physical'));
    }

    public function testFireDamageReadsFireField(): void
    {
        $outfits = [['physical_resistance' => 50, 'fire_resistance' => 25, 'poison_resistance' => 0]];
        // physical=0.5, но мы считаем fire — 0.25
        $this->assertSame(0.25, $this->calc->computeArmorResistance($outfits, 'Fire'));
    }

    public function testPoisonDamageReadsPoisonField(): void
    {
        $outfits = [['physical_resistance' => 50, 'fire_resistance' => 30, 'poison_resistance' => 40]];
        $this->assertSame(0.4, $this->calc->computeArmorResistance($outfits, 'Poison'));
    }

    public function testUnknownDamageTypeFallsBackToPhysical(): void
    {
        $outfits = [['physical_resistance' => 30, 'fire_resistance' => 50, 'poison_resistance' => 70]];
        // 'magic' не известен → switch default → physical = 0.3
        $this->assertSame(0.3, $this->calc->computeArmorResistance($outfits, 'Magic'));
    }

    public function testCaseInsensitiveDamageType(): void
    {
        $outfits = [['physical_resistance' => 30, 'fire_resistance' => 50, 'poison_resistance' => 0]];
        $this->assertSame(0.5, $this->calc->computeArmorResistance($outfits, 'fire'));
        $this->assertSame(0.5, $this->calc->computeArmorResistance($outfits, 'FIRE'));
        $this->assertSame(0.5, $this->calc->computeArmorResistance($outfits, 'Fire'));
    }

    // ---- computeDistance ----

    public function testNullCellsReturnsOne(): void
    {
        $this->assertSame(1.0, $this->calc->computeDistance(null, null));
        $this->assertSame(1.0, $this->calc->computeDistance(['coordinate_x' => 0, 'coordinate_y' => 0], null));
        $this->assertSame(1.0, $this->calc->computeDistance(null, ['coordinate_x' => 0, 'coordinate_y' => 0]));
    }

    public function testSameCellZero(): void
    {
        $a = ['coordinate_x' => 5, 'coordinate_y' => 5];
        $this->assertSame(0.0, $this->calc->computeDistance($a, $a));
    }

    public function testHorizontalDistance(): void
    {
        $a = ['coordinate_x' => 0, 'coordinate_y' => 0];
        $b = ['coordinate_x' => 3, 'coordinate_y' => 0];
        $this->assertSame(3.0, $this->calc->computeDistance($a, $b));
    }

    public function testDiagonal3_4_5(): void
    {
        $a = ['coordinate_x' => 0, 'coordinate_y' => 0];
        $b = ['coordinate_x' => 3, 'coordinate_y' => 4];
        // sqrt(9+16) = 5
        $this->assertSame(5.0, $this->calc->computeDistance($a, $b));
    }

    public function testDiagonalNegativeOffsetsAbsolute(): void
    {
        // dx/dy через abs — направление не важно.
        $a = ['coordinate_x' => 5, 'coordinate_y' => 5];
        $b = ['coordinate_x' => 2, 'coordinate_y' => 1];
        // dx=3, dy=4 → 5
        $this->assertSame(5.0, $this->calc->computeDistance($a, $b));
    }

    // ---- computeBiomeCoefficient ----

    public function testBiomeDanger1IsBaseline(): void
    {
        // 1 + (1-1)*0.1 = 1.0
        $this->assertSame(1.0, $this->calc->computeBiomeCoefficient(1));
    }

    public function testBiomeDanger0ClampedToOne(): void
    {
        // max(1, 0)=1 → 1.0
        $this->assertSame(1.0, $this->calc->computeBiomeCoefficient(0));
    }

    public function testBiomeDangerNegativeClampedToOne(): void
    {
        $this->assertSame(1.0, $this->calc->computeBiomeCoefficient(-5));
    }

    public function testBiomeDanger5(): void
    {
        // 1 + (5-1)*0.1 = 1.4
        $this->assertEqualsWithDelta(1.4, $this->calc->computeBiomeCoefficient(5), 0.001);
    }

    public function testBiomeDanger10(): void
    {
        // 1 + 9*0.1 = 1.9
        $this->assertEqualsWithDelta(1.9, $this->calc->computeBiomeCoefficient(10), 0.001);
    }

    // ---- WB1 (ADR-137 «Узлы») — отключение one-shot в бою с узлом ----

    /**
     * Узел-босс не должен мгновенно убивать игрока (ядро механики = многоходовый
     * бой). Флаг $isBossEncounter гасит one-shot-эффект, НО mt_rand для oneShotRoll
     * вызывается в любом случае → порядок RNG для PvP-пути сохранён (fixture-fence).
     *
     * seed=2: 4-й mt_rand(0,100)=1 < oneShotChance(16 при levelDiff 160) → one-shot
     * срабатывает в PvP-режиме (regression-якорь) и подавляется в боссовом.
     */
    public function testBossEncounterSuppressesOneShotButKeepsRngOrder(): void
    {
        $calc = new PvpDamageCalculator(
            new PvpFormulaService(new GameBalance()),
            $this->makeFistsOnlyRepo(),
            new GameBalance()
        );

        $attacker = ['id' => 1, 'level' => 200, 'cell_number' => 1, 'strength' => 0, 'agility' => 0];
        $defender = ['id' => 2, 'level' => 40, 'cell_number' => 1, 'agility' => 0, 'health' => 500.0];
        $biome    = ['danger_level' => 1];

        // PvP-режим (isBossEncounter=false): one-shot срабатывает → урон = HP защитника.
        mt_srand(2);
        $pvp = $calc->computeDamage($attacker, $defender, $biome, false, false, false);
        $this->assertSame(500.0, $pvp['finalDamage'], 'PvP: one-shot обязан сработать (regression-якорь)');

        // Бой с узлом (isBossEncounter=true): тот же seed, но one-shot подавлен → чип-урон.
        mt_srand(2);
        $boss = $calc->computeDamage($attacker, $defender, $biome, false, false, true);
        $this->assertLessThan(500.0, $boss['finalDamage'], 'Узел: one-shot обязан быть подавлен');
        $this->assertGreaterThan(0.0, $boss['finalDamage']);

        // mt_rand для oneShotRoll сделан в обоих режимах → порядок RNG идентичен.
        $this->assertSame($pvp['oneShotRoll'], $boss['oneShotRoll'], 'порядок RNG сохранён (fixture-fence)');
    }

    /**
     * Репозиторий экипировки без БД: нет оружия (кулаки), нет брони, нет клеток карты.
     * PvpEquipmentRepository final, а Model::where() — магический __call (не мокается
     * PHPUnit'ом) → подменяем 3 используемые модели анонимными наследниками с no-op
     * конструктором (без подключения к БД) и переопределённой флюент-цепочкой.
     * Неиспользуемые в computeDamage модели остаются реальными (запросов не делают).
     */
    private function makeFistsOnlyRepo(): PvpEquipmentRepository
    {
        $cw = new class extends CharactersWeaponsModel {
            public function __construct() {}

            public function where($key = null, $value = null, ?bool $escape = null): static
            {
                return $this;
            }

            public function first()
            {
                return null;
            }
        };

        $co = new class extends CharactersOutfitsModel {
            public function __construct() {}

            public function where($key = null, $value = null, ?bool $escape = null): static
            {
                return $this;
            }

            public function findAll(?int $limit = null, int $offset = 0): array
            {
                return [];
            }
        };

        $map = new class extends MapModel {
            public function __construct() {}

            public function where($key = null, $value = null, ?bool $escape = null): static
            {
                return $this;
            }

            public function first()
            {
                return null;
            }
        };

        return new PvpEquipmentRepository($cw, null, $co, null, $map, null, null, null);
    }
}
