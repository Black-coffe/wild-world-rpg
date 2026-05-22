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

    // ── V13 (ADR-037): strategic quest-chains замыкаются на signature-оружие ──

    /**
     * Канон «финал цепочки → faction weapon» (V12 Bunker/Technopark +
     * V13 GhostCity/IslandFarm). Финальный квест каждой цепочки имеет
     * objective_type=craft_item с objective_target = ключ этого рецепта.
     * Если оружие переименуют/удалят из CraftRecipes — цепочка молча не
     * завершится (QuestObjectiveHandler не найдёт предмет в crafted_items_log).
     *
     * @return array<string, string> финал quest title_en => weapon recipe key
     */
    private function chainFinals(): array
    {
        return [
            'BunkerDominance'        => 'BunkerRifle',
            'TechnoparkBreakthrough' => 'TechnoBeamShotgun',
            'GhostCityDominance'     => 'GhostCityKnife',
            'IslandFarmAbundance'    => 'FarmersHarvestScythe',
        ];
    }

    public function testChainFinalsTargetRealFactionWeapons(): void
    {
        $cfg = new CraftRecipes();
        foreach ($this->chainFinals() as $questEn => $weaponKey) {
            $r = $cfg->get($weaponKey);
            $this->assertIsArray($r, "Финал цепочки '{$questEn}' целится в несуществующий рецепт '{$weaponKey}'");
            $this->assertSame('weapon', $r['output_type'] ?? null, "{$weaponKey}: craft-цель финала должна быть weapon");
        }
    }

    public function testChainFinalsAreBijectiveWithFactionWeapons(): void
    {
        // 4 финала цепочек 1:1 покрывают ровно 4 faction weapons.
        $targets = array_values($this->chainFinals());
        $weapons = array_keys($this->expected());
        sort($targets);
        sort($weapons);
        $this->assertSame($weapons, $targets, '4 финала цепочек должны 1:1 совпадать с 4 faction weapons');
    }
}
