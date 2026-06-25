<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\BuildLockService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S4 (ROADMAP-RETENTION-10, ADR-139, спина-слайс 2) — прогрессивное раскрытие построек.
 *
 * Killswitch — через test-double; уровни — против реального Config\Buildings (детерминированные
 * данные). Tier-3 — живой тап lock-кнопки в списке строительства.
 *
 * @internal
 */
final class BuildLockServiceTest extends CIUnitTestCase
{
    public function testEnabledReadsFlag(): void
    {
        $on = new FakeBuildLockService();
        $on->on = true;
        $this->assertTrue($on->enabled());

        $off = new FakeBuildLockService();
        $off->on = false;
        $this->assertFalse($off->enabled());
    }

    public function testRequiredLevelFromConfig(): void
    {
        $svc = new FakeBuildLockService();
        $this->assertSame(15, $svc->requiredLevel('Arsenal'));
        $this->assertSame(10, $svc->requiredLevel('RoboticsWorkshop'));
        $this->assertSame(5, $svc->requiredLevel('Gym'));
        $this->assertSame(1, $svc->requiredLevel('Workshop'));
        $this->assertSame(1, $svc->requiredLevel('NonexistentBuilding')); // неизвестный → 1
    }

    public function testIsLockedGatedByKillswitch(): void
    {
        $off = new FakeBuildLockService();
        $off->on = false;
        // Даже если уровень ниже нужного — при OFF не блокируем (byte-identical).
        $this->assertFalse($off->isLocked('Arsenal', 1));
    }

    public function testIsLockedAtBoundary(): void
    {
        $svc = new FakeBuildLockService();
        $svc->on = true;
        $this->assertTrue($svc->isLocked('Arsenal', 14));  // req 15 > 14 → locked
        $this->assertFalse($svc->isLocked('Arsenal', 15)); // req 15, level 15 → доступно
        $this->assertFalse($svc->isLocked('Arsenal', 20)); // выше → доступно
        $this->assertFalse($svc->isLocked('Workshop', 1)); // req 1 → никогда не locked
    }

    public function testLockLabelFormat(): void
    {
        $svc = new FakeBuildLockService();
        $this->assertSame('🔒 ⚔️ Арсенал (с lvl 15)', $svc->lockLabel('Arsenal', '⚔️ Арсенал'));
    }

    public function testLockAlertContentAndLength(): void
    {
        $svc = new FakeBuildLockService();
        $alert = $svc->lockAlert('Arsenal', 5);
        $this->assertStringContainsString('Арсенал', $alert);
        $this->assertStringContainsString('15', $alert);          // нужный уровень
        $this->assertStringContainsString('осталось 10', $alert); // 15 - 5
        $this->assertLessThanOrEqual(200, mb_strlen($alert), 'alert > 200 симв (Telegram-лимит)');
    }

    public function testLockAlertUnknownKey(): void
    {
        $svc = new FakeBuildLockService();
        $this->assertSame('Здание недоступно.', $svc->lockAlert('Nonexistent', 5));
    }

    /** Самая длинная пара (имя+тизер) тоже укладывается в 200 симв. */
    public function testLockAlertLongestStaysWithinLimit(): void
    {
        $svc = new FakeBuildLockService();
        foreach (['RoboticsWorkshop', 'TeleportationCenter', 'WatchTower', 'BarbedFence', 'WoodenWall', 'Laboratory'] as $key) {
            $this->assertLessThanOrEqual(200, mb_strlen($svc->lockAlert($key, 1)), "alert {$key} > 200");
        }
    }
}

/**
 * Test-double: подменяет killswitch-seam детерминированным значением.
 *
 * @internal
 */
final class FakeBuildLockService extends BuildLockService
{
    public bool $on = true;

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }
}
