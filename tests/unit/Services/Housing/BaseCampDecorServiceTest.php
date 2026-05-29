<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Housing;

use App\Services\Housing\BaseCampDecorService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * W21 (ADR-076) + W22 (ADR-077) — Housing customisation preset validation.
 *
 * @internal
 */
final class BaseCampDecorServiceTest extends CIUnitTestCase
{
    public function testPresetNamesCount(): void
    {
        $this->assertCount(12, BaseCampDecorService::PRESET_NAMES);
    }

    public function testPresetFlagsCount(): void
    {
        $this->assertCount(16, BaseCampDecorService::PRESET_FLAGS);
    }

    public function testPresetNamesNoDuplicates(): void
    {
        $this->assertCount(12, array_unique(BaseCampDecorService::PRESET_NAMES));
    }

    public function testPresetFlagsNoDuplicates(): void
    {
        $this->assertCount(16, array_unique(BaseCampDecorService::PRESET_FLAGS));
    }

    public function testAllPresetNamesAreNonEmptyStrings(): void
    {
        foreach (BaseCampDecorService::PRESET_NAMES as $name) {
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }
    }

    public function testAllPresetFlagsAreNonEmptyStrings(): void
    {
        foreach (BaseCampDecorService::PRESET_FLAGS as $flag) {
            $this->assertIsString($flag);
            $this->assertNotEmpty($flag);
        }
    }

    public function testPresetNamesIndexedFromZero(): void
    {
        $this->assertArrayHasKey(0, BaseCampDecorService::PRESET_NAMES);
        $this->assertArrayHasKey(11, BaseCampDecorService::PRESET_NAMES);
        $this->assertArrayNotHasKey(12, BaseCampDecorService::PRESET_NAMES);
    }

    public function testPresetFlagsIndexedFromZero(): void
    {
        $this->assertArrayHasKey(0, BaseCampDecorService::PRESET_FLAGS);
        $this->assertArrayHasKey(15, BaseCampDecorService::PRESET_FLAGS);
        $this->assertArrayNotHasKey(16, BaseCampDecorService::PRESET_FLAGS);
    }

    // ── W22 (ADR-077) — interior items: очаг / обстановка / питомец ──

    public function testInteriorPresetCounts(): void
    {
        $this->assertCount(6, BaseCampDecorService::PRESET_HEARTHS);
        $this->assertCount(6, BaseCampDecorService::PRESET_FURNITURE);
        $this->assertCount(6, BaseCampDecorService::PRESET_PETS);
    }

    public function testInteriorPresetsNoDuplicates(): void
    {
        $this->assertCount(6, array_unique(BaseCampDecorService::PRESET_HEARTHS));
        $this->assertCount(6, array_unique(BaseCampDecorService::PRESET_FURNITURE));
        $this->assertCount(6, array_unique(BaseCampDecorService::PRESET_PETS));
    }

    public function testInteriorPresetsAreNonEmptyStrings(): void
    {
        $all = array_merge(
            BaseCampDecorService::PRESET_HEARTHS,
            BaseCampDecorService::PRESET_FURNITURE,
            BaseCampDecorService::PRESET_PETS,
        );
        foreach ($all as $label) {
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function testInteriorPresetsIndexedFromZero(): void
    {
        foreach ([
            BaseCampDecorService::PRESET_HEARTHS,
            BaseCampDecorService::PRESET_FURNITURE,
            BaseCampDecorService::PRESET_PETS,
        ] as $preset) {
            $this->assertArrayHasKey(0, $preset);
            $this->assertArrayHasKey(5, $preset);
            $this->assertArrayNotHasKey(6, $preset);
        }
    }
}
