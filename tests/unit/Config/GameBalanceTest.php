<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\GameBalance;

/**
 * F2.10 — unit-тесты на game balance config.
 *
 * Source of truth: AttackPlayerAction.php:43-79 (PvP) и canonical sources
 * в lore/combat/PvP.md. Любое изменение константы требует обновления
 * этого теста — это «assertion-fence» против случайного ребаланса.
 *
 * При мигрировании остальных handler'ов с hardcoded const на
 * config('GameBalance')->X — добавлять корреспондующий тест значения.
 *
 * @internal
 */
final class GameBalanceTest extends CIUnitTestCase
{
    private GameBalance $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new GameBalance();
    }

    // ---- PvP базовые ----

    public function testPvpRoundsPerDamageIncrease(): void
    {
        $this->assertSame(15, $this->cfg->pvpRoundsPerDamageIncrease);
    }

    public function testPvpDamageIncreasePerStep(): void
    {
        $this->assertSame(0.15, $this->cfg->pvpDamageIncreasePerStep);
    }

    public function testPvpMaxRounds(): void
    {
        $this->assertSame(150, $this->cfg->pvpMaxRounds);
    }

    // ---- Смерть ----

    public function testDeathExpLossPercent(): void
    {
        $this->assertSame(0.05, $this->cfg->deathExpLossPercent);
    }

    public function testDeathStatLossPercent(): void
    {
        $this->assertSame(0.005, $this->cfg->deathStatLossPercent);
    }

    // ---- Lucky Strike (см. e99cc00 — синхронизация 30%→40%) ----

    public function testLuckyStrikeMaxChanceMatchesCode(): void
    {
        // GAME_DESCRIPTION.md был 30, code AttackPlayerAction LUCKY_STRIKE_MAX_CHANCE = 40,
        // синхронизировано на 40 в commit e99cc00.
        $this->assertSame(40, $this->cfg->luckyStrikeMaxChance);
    }

    public function testLuckyStrikeDamageMult(): void
    {
        $this->assertSame(1.5, $this->cfg->luckyStrikeDamageMult);
    }

    // ---- Уворот ----

    public function testMaxDodgeChancePercent(): void
    {
        $this->assertSame(75, $this->cfg->maxDodgeChancePercent);
    }

    // ---- Tax Collection (F2.10 wire-in: TaxCollectionHandler reads this) ----

    public function testTaxCollectionHour(): void
    {
        // F2.10: TaxCollectionHandler использует это значение вместо hardcoded 3.
        $this->assertSame(3, $this->cfg->taxCollectionHour);
    }

    // ---- Регенерация ----

    public function testHealthRegenPerTick(): void
    {
        $this->assertSame(0.05, $this->cfg->healthRegenPerTick);
    }

    public function testTiredRegenPerTick(): void
    {
        $this->assertSame(0.1, $this->cfg->tiredRegenPerTick);
    }

    public function testRegenLevelCap(): void
    {
        $this->assertSame(20, $this->cfg->regenLevelCap);
    }

    // ---- Питание ----

    public function testFoodWaterMultipliers(): void
    {
        $this->assertSame(0.6, $this->cfg->foodMultiplier);
        $this->assertSame(0.7, $this->cfg->waterMultiplier);
        $this->assertSame(3, $this->cfg->mealsPerDay);
    }

    // ---- PlayerDetection ----

    public function testDetectionRadiusFormula(): void
    {
        // base + floor(level / divisor), capped на max
        $this->assertSame(2, $this->cfg->detectionRadiusBase);
        $this->assertSame(500, $this->cfg->detectionRadiusDivisor);
        $this->assertSame(3, $this->cfg->detectionRadiusMax);
    }

    // ---- Стартовый персонаж ----

    public function testStartingResources(): void
    {
        $this->assertSame(1000, $this->cfg->startingGold);
        $this->assertSame(100, $this->cfg->startingTradingKarma);
    }
}
