<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\KindLabels;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WorldEvents;

/**
 * v0.32.1 — KindLabels test.
 *
 * Инвариант: КОЖЕН з 10 VALID_EFFECT_KINDS у WorldEvents должен human-readable
 * label у KindLabels. Иначе з'явиться UI з кнопкою "🚫 Без подій damage_health"
 * (raw enum-ID) — не gracefully.
 *
 * @internal
 */
final class KindLabelsTest extends CIUnitTestCase
{
    public function testAllKindsHaveLabel(): void
    {
        foreach (WorldEvents::VALID_EFFECT_KINDS as $kind) {
            $this->assertTrue(
                KindLabels::isKnown($kind),
                "Kind '{$kind}' отсутствій у KindLabels::LABELS_RU. Додай людиномовний переклад."
            );
        }
    }

    public function testRuFallbackForUnknownKind(): void
    {
        // Невідомий kind → fallback на сам kind (не падаємо)
        $this->assertSame('foo_bar_baz', KindLabels::ru('foo_bar_baz'));
    }

    public function testRuReturnsHumanLabel(): void
    {
        $this->assertSame('с уроном', KindLabels::ru('damage_health'));
        $this->assertSame('с лечением', KindLabels::ru('heal'));
        $this->assertSame('с золотом', KindLabels::ru('gold_grant'));
    }

    public function testAllReturnsAllLabels(): void
    {
        $all = KindLabels::all();
        $this->assertGreaterThanOrEqual(10, count($all));
        $this->assertArrayHasKey('damage_health', $all);
    }
}
