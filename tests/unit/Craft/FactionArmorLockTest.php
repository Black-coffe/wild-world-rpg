<?php

namespace Tests\Unit\Craft;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * V14 (ADR-046) — anti-drift guard для 4 faction-unique armor.
 * Сиблинг FactionWeaponLockTest (S25/ADR-029).
 *
 * Гарантирует целостность gate-конфигурации (true faction-exclusive):
 * каждая броня требует (а) ProfessionalWorkbench, (б) свой StrategicCapture
 * квест, (в) свою фракцию, и выдаётся как outfit. Иначе gate в
 * GenericCraftActionStart молча не сработает (рецепт станет доступен всем).
 *
 * @internal
 */
final class FactionArmorLockTest extends CIUnitTestCase
{
    /**
     * 1:1 канон: recipeKey => [quest title_en, faction_id].
     *
     * @return array<string, array{0:string,1:int}>
     */
    private function expected(): array
    {
        return [
            'BunkerPlateArmor'    => ['StrategicCaptureBunker', 1],     // Военные
            'TechnoShieldSuit'    => ['StrategicCaptureTechnopark', 3], // Инженеры
            'GhostCityCloak'      => ['StrategicCaptureGhostCity', 2],  // Партизаны
            'FarmsteadGuardArmor' => ['StrategicCaptureIslandFarm', 4], // Фермеры
        ];
    }

    public function testAllFourFactionArmorsExist(): void
    {
        $cfg = new CraftRecipes();
        foreach (array_keys($this->expected()) as $key) {
            $this->assertNotNull($cfg->get($key), "Рецепт faction armor '{$key}' отсутствует в CraftRecipes");
        }
    }

    public function testEachIsQuestAndFactionGated(): void
    {
        $cfg = new CraftRecipes();
        foreach ($this->expected() as $key => [$questEn, $factionId]) {
            $r = $cfg->get($key);
            $this->assertIsArray($r);
            $this->assertSame($questEn, $r['required_quest'] ?? null, "{$key}: required_quest mismatch");
            $this->assertSame($factionId, $r['required_faction'] ?? null, "{$key}: required_faction mismatch");
        }
    }

    public function testEachRequiresProfessionalWorkbenchAndOutputsOutfit(): void
    {
        $cfg = new CraftRecipes();
        foreach (array_keys($this->expected()) as $key) {
            $r = $cfg->get($key);
            $this->assertIsArray($r);
            $this->assertSame('outfit', $r['output_type'] ?? null, "{$key}: output_type должен быть 'outfit'");
            $gate = $r['required_crafted_items'] ?? [];
            $this->assertArrayHasKey('ProfessionalWorkbench', is_array($gate) ? $gate : [], "{$key}: нет ProfessionalWorkbench gate");
            $this->assertSame($key, $r['outfit_name_en'] ?? null, "{$key}: outfit_name_en должен совпадать с recipe key");
        }
    }

    public function testEachDeclaresDurationSettingKey(): void
    {
        $cfg = new CraftRecipes();
        foreach (array_keys($this->expected()) as $key) {
            $r = $cfg->get($key);
            $this->assertIsArray($r);
            $settingKey = $r['duration_override_setting_key'] ?? null;
            $this->assertIsString($settingKey, "{$key}: нет duration_override_setting_key");
            $this->assertStringStartsWith('tier3.faction_armor.', $settingKey, "{$key}: duration setting key namespace mismatch");
        }
    }

    public function testFactionMappingIsBijective(): void
    {
        // Каждая из 4 фракций (1-4) покрыта ровно одной faction armor.
        $cfg      = new CraftRecipes();
        $factions = [];
        foreach (array_keys($this->expected()) as $key) {
            $r = $cfg->get($key);
            $factions[] = $r['required_faction'] ?? null;
        }
        sort($factions);
        $this->assertSame([1, 2, 3, 4], $factions, '4 faction armor должны покрывать ровно фракции 1-4 (1:1)');
    }

    public function testArmorAndWeaponShareSameFactionGates(): void
    {
        // Симметрия с оружием: броня и оружие одной фракции гейтятся одним квестом.
        $cfg = new CraftRecipes();
        $weaponByFaction = [
            1 => 'BunkerRifle',
            3 => 'TechnoBeamShotgun',
            2 => 'GhostCityKnife',
            4 => 'FarmersHarvestScythe',
        ];
        foreach ($this->expected() as $armorKey => [$questEn, $factionId]) {
            $weapon = $cfg->get($weaponByFaction[$factionId]);
            $this->assertIsArray($weapon, "Сиблинг-оружие фракции {$factionId} отсутствует");
            $this->assertSame(
                $weapon['required_quest'] ?? null,
                $questEn,
                "{$armorKey}: квест-гейт должен совпадать с оружием той же фракции"
            );
        }
    }
}
