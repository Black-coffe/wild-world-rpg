<?php

namespace Tests\Unit\Craft;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * S25 (ADR-029) — anti-drift guard для 4 faction-unique weapons.
 *
 * Гарантирует целостность gate-конфигурации (true faction-exclusive):
 * каждое оружие требует (а) ProfessionalWorkbench, (б) свой StrategicCapture
 * квест, (в) свою фракцию, и выдаётся как weapon. Иначе gate в
 * GenericCraftActionStart молча не сработает (рецепт станет доступен всем).
 *
 * @internal
 */
final class FactionWeaponLockTest extends CIUnitTestCase
{
    /**
     * 1:1 канон: recipeKey => [quest title_en, faction_id].
     *
     * @return array<string, array{0:string,1:int}>
     */
    private function expected(): array
    {
        return [
            'BunkerRifle'          => ['StrategicCaptureBunker', 1],     // Военные
            'TechnoBeamShotgun'    => ['StrategicCaptureTechnopark', 3], // Инженеры
            'GhostCityKnife'       => ['StrategicCaptureGhostCity', 2],  // Партизаны
            'FarmersHarvestScythe' => ['StrategicCaptureIslandFarm', 4], // Фермеры
        ];
    }

    public function testAllFourFactionWeaponsExist(): void
    {
        $cfg = new CraftRecipes();
        foreach (array_keys($this->expected()) as $key) {
            $this->assertNotNull($cfg->get($key), "Рецепт faction weapon '{$key}' отсутствует в CraftRecipes");
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

    public function testEachRequiresProfessionalWorkbenchAndOutputsWeapon(): void
    {
        $cfg = new CraftRecipes();
        foreach (array_keys($this->expected()) as $key) {
            $r = $cfg->get($key);
            $this->assertIsArray($r);
            $this->assertSame('weapon', $r['output_type'] ?? null, "{$key}: output_type должен быть 'weapon'");
            $gate = $r['required_crafted_items'] ?? [];
            $this->assertArrayHasKey('ProfessionalWorkbench', is_array($gate) ? $gate : [], "{$key}: нет ProfessionalWorkbench gate");
            $this->assertSame($key, $r['weapon_name_en'] ?? null, "{$key}: weapon_name_en должен совпадать с recipe key");
        }
    }

    public function testFactionMappingIsBijective(): void
    {
        // Каждая из 4 фракций (1-4) покрыта ровно одним faction weapon.
        $cfg      = new CraftRecipes();
        $factions = [];
        foreach (array_keys($this->expected()) as $key) {
            $r = $cfg->get($key);
            $factions[] = $r['required_faction'] ?? null;
        }
        sort($factions);
        $this->assertSame([1, 2, 3, 4], $factions, '4 faction weapons должны покрывать ровно фракции 1-4 (1:1)');
    }
}
