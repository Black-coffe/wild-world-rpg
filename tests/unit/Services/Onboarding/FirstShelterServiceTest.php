<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\FirstShelterService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S5 (ROADMAP-RETENTION-10, ADR-142) — «первое укрытие» (Навес/LeanTo).
 *
 * Лочит чистую логику гейта видимости кнопки (без БД): killswitch + окно уровня + one-shot
 * (0 построек). gsBool/gsInt и наличие построек — через test-double. Tier-3 — живой тап
 * «⛺ Навес» в списке строительства на чистом чаре + завершение постройки → OnbStepBuild.
 *
 * @internal
 */
final class FirstShelterServiceTest extends CIUnitTestCase
{
    public function testEnabledReadsFlag(): void
    {
        $on = new FakeFirstShelterService();
        $on->on = true;
        $this->assertTrue($on->enabled());

        $off = new FakeFirstShelterService();
        $off->on = false;
        $this->assertFalse($off->enabled());
    }

    public function testMaxLevelReadsSetting(): void
    {
        $svc = new FakeFirstShelterService();
        $svc->maxLevel = 6;
        $this->assertSame(6, $svc->maxLevel());
    }

    public function testOffersToNewbieWithNoBuildings(): void
    {
        $svc = new FakeFirstShelterService();
        $svc->on        = true;
        $svc->maxLevel  = 6;
        $svc->hasAny    = false;
        $this->assertTrue($svc->shouldOffer(491, 1));
        $this->assertTrue($svc->shouldOffer(491, 6)); // граница включительно
    }

    public function testGatedByKillswitch(): void
    {
        $svc = new FakeFirstShelterService();
        $svc->on     = false; // OFF → dormant
        $svc->hasAny = false;
        $this->assertFalse($svc->shouldOffer(491, 1), 'killswitch OFF → не предлагаем (byte-identical)');
    }

    public function testRejectsVeteranAboveMaxLevel(): void
    {
        $svc = new FakeFirstShelterService();
        $svc->on       = true;
        $svc->maxLevel = 6;
        $svc->hasAny   = false;
        $this->assertFalse($svc->shouldOffer(491, 7), 'level 7 > max 6 → ветеран, не предлагаем');
    }

    public function testRejectsNonPositiveLevel(): void
    {
        $svc = new FakeFirstShelterService();
        $svc->on       = true;
        $svc->maxLevel = 6;
        $svc->hasAny   = false;
        $this->assertFalse($svc->shouldOffer(491, 0));
    }

    public function testRejectsWhenAlreadyHasBuilding(): void
    {
        $svc = new FakeFirstShelterService();
        $svc->on       = true;
        $svc->maxLevel = 6;
        $svc->hasAny   = true; // уже есть постройка/стройка в работе → one-shot отработал
        $this->assertFalse($svc->shouldOffer(491, 1), 'есть постройка → Навес больше не первый (one-shot)');
    }

    public function testRejectsInvalidCharId(): void
    {
        $svc = new FakeFirstShelterService();
        $svc->on       = true;
        $svc->maxLevel = 6;
        $svc->hasAny   = false;
        $this->assertFalse($svc->shouldOffer(0, 1));
        $this->assertFalse($svc->shouldOffer(-5, 1));
    }

    public function testButtonEntryShape(): void
    {
        $svc   = new FakeFirstShelterService();
        $entry = $svc->buttonEntry();
        $this->assertSame('genericBuildInfo_LeanTo', $entry['callback_data']);
        $this->assertSame(0, $entry['tax'], 'налог 0 — новичка не нагружаем');
        $this->assertStringContainsString('Навес', $entry['name']);
    }
}

/**
 * Test-double: подменяет killswitch/max_level/наличие построек детерминированно (без БД).
 *
 * @internal
 */
final class FakeFirstShelterService extends FirstShelterService
{
    public bool $on       = true;
    public int $maxLevel  = 6;
    public bool $hasAny   = false;

    protected function gsBool(string $key, bool $default): bool
    {
        return $this->on;
    }

    protected function gsInt(string $key, int $default): int
    {
        return $this->maxLevel;
    }

    protected function hasAnyBuildingOrInflight(int $charId): bool
    {
        return $this->hasAny;
    }
}
