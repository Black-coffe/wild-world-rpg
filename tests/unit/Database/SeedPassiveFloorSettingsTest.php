<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\Migrations\SeedPassiveFloorSettings;
use App\Services\Player\PassiveHealthFloor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-200 / ADR-024 — три ключа спеки sudden-death-1125 посеяны с полной документацией и границами.
 * Пол пассивного урона жёстко выше порога рулетки смерти (0.99): админка не может вернуть «события убивают».
 * Дефолт кода (фолбэк без таблицы) совпадает с посеянным.
 *
 * @internal
 */
final class SeedPassiveFloorSettingsTest extends CIUnitTestCase
{
    private const MIGRATION = APPPATH . 'Database/Migrations/2026-12-26-100000_SeedPassiveFloorSettings.php';

    /** Порог рулетки смерти (`DeathRouletteHandler`: health <= 0.99). */
    private const ROULETTE_THRESHOLD = 0.99;

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        require_once self::MIGRATION;
        $byKey = [];
        foreach (SeedPassiveFloorSettings::ROWS as $row) {
            $byKey[(string) $row['setting_key']] = $row;
        }

        return $byKey;
    }

    public function testAllThreeKeysAreSeeded(): void
    {
        $keys = array_keys($this->rows());
        sort($keys);

        $this->assertSame(['health.event_gather_min', 'health.passive_floor', 'health.passive_warn_threshold'], $keys);
    }

    public function testEveryKeyCarriesDocumentationAndBounds(): void
    {
        $rows = $this->rows();
        $this->assertNotSame([], $rows);
        foreach ($rows as $key => $row) {
            foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text', 'category', 'default_value_text'] as $field) {
                $this->assertNotSame('', trim((string) ($row[$field] ?? '')), "{$key}: {$field}");
            }
            $this->assertSame('float', $row['value_type'] ?? null, "{$key}: float");
            foreach (['recommended_min', 'recommended_max', 'hard_min', 'hard_max'] as $field) {
                $this->assertTrue(is_numeric($row[$field] ?? null), "{$key}: {$field}");
            }
            $value = (float) $row['value_float'];
            $this->assertSame($value, (float) $row['default_value_text'], "{$key}: default = value");
            $this->assertLessThanOrEqual((float) $row['recommended_max'], $value, "{$key}: value in soft bounds");
            $this->assertGreaterThanOrEqual((float) $row['recommended_min'], $value, "{$key}: value in soft bounds");
            $this->assertGreaterThanOrEqual((float) $row['hard_min'], (float) $row['recommended_min'], "{$key}: soft inside hard");
            $this->assertLessThanOrEqual((float) $row['hard_max'], (float) $row['recommended_max'], "{$key}: soft inside hard");
        }
    }

    public function testFloorHardMinIsAboveTheRouletteThreshold(): void
    {
        $floor = $this->rows()['health.passive_floor'] ?? [];

        $this->assertGreaterThan(self::ROULETTE_THRESHOLD, (float) ($floor['hard_min'] ?? 0), 'floor cannot be set into the roulette');
        $this->assertSame(PassiveHealthFloor::DEFAULT, (float) ($floor['value_float'] ?? 0), 'code fallback = seeded default');
        $this->assertSame(PassiveHealthFloor::KEY, $floor['setting_key'] ?? null);
    }
}
