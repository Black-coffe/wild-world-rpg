<?php

namespace Tests\Unit\Display;

use App\Services\Display\OutfitDisplayHelper;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * V15 (ROADMAP-vNext Фаза 3 closer) — guard для хелпера показа брони.
 *
 * Покрывает оба фикса честного closer'а:
 *  - резолв картинки фракционной брони (V14) в professional/ (а не заглушка);
 *  - честный показ ненулевых сопротивлений + наличие PvP-сноски.
 *
 * @internal
 */
final class OutfitDisplayHelperTest extends CIUnitTestCase
{
    public function testFactionArmorImageResolvesToProfessionalFolder(): void
    {
        $map = [
            'BunkerPlateArmor'    => 'bunker_plate_armor.jpg',
            'TechnoShieldSuit'    => 'techno_shield_suit.jpg',
            'GhostCityCloak'      => 'ghost_city_cloak.jpg',
            'FarmsteadGuardArmor' => 'farmstead_guard_armor.jpg',
        ];
        foreach ($map as $nameEn => $file) {
            $rel = OutfitDisplayHelper::factionArmorImageRel($nameEn);
            $this->assertIsString($rel, "{$nameEn}: ожидался путь картинки");
            $this->assertStringContainsString('uploads/telegram/craft/professional/', $rel, "{$nameEn}: путь должен вести в professional/");
            $this->assertStringEndsWith($file, $rel, "{$nameEn}: имя файла картинки не совпало");
        }
    }

    public function testFactionArmorImageFilesExistOnDisk(): void
    {
        // Картинки отгружены в V14 image-tail (v0.51.243) — анти-дрейф.
        foreach (['BunkerPlateArmor', 'TechnoShieldSuit', 'GhostCityCloak', 'FarmsteadGuardArmor'] as $nameEn) {
            $rel = OutfitDisplayHelper::factionArmorImageRel($nameEn);
            $this->assertIsString($rel);
            $this->assertFileExists(FCPATH . $rel, "{$nameEn}: файл картинки отсутствует на диске");
        }
    }

    public function testNonFactionArmorReturnsNull(): void
    {
        $this->assertNull(OutfitDisplayHelper::factionArmorImageRel('RaggedShirt'));
        $this->assertNull(OutfitDisplayHelper::factionArmorImageRel(''));
        $this->assertNull(OutfitDisplayHelper::factionArmorImageRel('TitanPowerArmor'));
    }

    public function testResistanceLinesShowOnlyNonZeroWithSigns(): void
    {
        // Бункерная бронеплита: +20% физ., +5% огонь, −8% скорость, −5% скрытность; яд=0.
        $outfit = [
            'physical_resistance' => 0.20,
            'fire_resistance'     => 0.05,
            'poison_resistance'   => 0,
            'speed_modifier'      => -0.08,
            'stealth_modifier'    => -0.05,
        ];
        $lines = OutfitDisplayHelper::resistanceLines($outfit);

        $this->assertCount(4, $lines, 'нулевое сопротивление к ядам не должно показываться');
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('+20%', $joined);
        $this->assertStringContainsString('+5%', $joined);
        $this->assertStringContainsString('-8%', $joined);
        $this->assertStringContainsString('-5%', $joined);
        $this->assertStringNotContainsString('ядам', $joined, 'нулевое поле не должно попадать в вывод');
    }

    public function testResistanceLinesEmptyForPlainArmor(): void
    {
        $plain = [
            'physical_resistance' => 0,
            'fire_resistance'     => 0,
            'poison_resistance'   => 0,
            'speed_modifier'      => 0,
            'stealth_modifier'    => 0,
        ];
        $this->assertSame([], OutfitDisplayHelper::resistanceLines($plain));
        $this->assertFalse(OutfitDisplayHelper::hasSpecialProperties($plain));
        // Полностью отсутствующие ключи — тоже пусто (не падать).
        $this->assertSame([], OutfitDisplayHelper::resistanceLines([]));
    }

    public function testHasSpecialPropertiesTrueWhenAnyNonZero(): void
    {
        $this->assertTrue(OutfitDisplayHelper::hasSpecialProperties(['poison_resistance' => 0.25]));
    }

    public function testPvpNoteIsNonEmptyAndMentionsDuels(): void
    {
        $this->assertNotEmpty(OutfitDisplayHelper::PVP_NOTE);
        $this->assertStringContainsString('PvP', OutfitDisplayHelper::PVP_NOTE);
    }
}
