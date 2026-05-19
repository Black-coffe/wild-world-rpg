<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * S17 (v0.51.199) — recipe config tests для 5 T3 weapons (ADR-026 Фаза 4).
 *
 * Guarantees:
 *   - Все 5 рецептов существуют в Config\CraftRecipes.
 *   - Каждый имеет output_type='weapon' (для GenericCraftCompletionHandler dispatch
 *     на characters_weapons + updateStrengthAndAgility, F3.B9 pattern).
 *   - weapon_name_en точно совпадает с weapons.name_en в БД (иначе completion handler
 *     не найдёт row для INSERT в characters_weapons и task завершится тихо без выдачи).
 *   - required_crafted_items включает ProfessionalWorkbench (T3 gate).
 *   - duration_override_setting_key указывает на existing GameSettings key.
 *   - requires_base=true, output_type/weapon_slot/strength_bonus/agility_bonus заданы.
 *
 * @internal
 */
final class CraftRecipesT3WeaponsTest extends CIUnitTestCase
{
    private CraftRecipes $cfg;

    /**
     * 5 T3 weapons + ожидаемые core attributes для each.
     * weapon_name_en должен 1:1 совпадать с weapons.name_en в БД (audit на testbot 2026-05-19).
     *
     * @return array<string,array{weapon_name_en:string,required_level:int,weapon_slot:string,duration_setting:string}>
     */
    private function expectedT3Weapons(): array
    {
        return [
            'GaussPistol' => [
                'weapon_name_en'   => 'GaussPistol',
                'required_level'   => 16,
                'weapon_slot'      => 'hand',
                'duration_setting' => 'tier3.weapons.gauss_pistol.craft_duration_hours',
            ],
            'RailCarbineVikhr' => [
                'weapon_name_en'   => 'RailCarbineVikhr',
                'required_level'   => 18,
                'weapon_slot'      => 'twohand',
                'duration_setting' => 'tier3.weapons.rail_carbine_vikhr.craft_duration_hours',
            ],
            'IonDestabilizer' => [
                'weapon_name_en'   => 'IonDestabilizer',
                'required_level'   => 20,
                'weapon_slot'      => 'twohand',
                'duration_setting' => 'tier3.weapons.ion_destabilizer.craft_duration_hours',
            ],
            'FlamethrowerAid' => [
                'weapon_name_en'   => 'FlamethrowerAid',
                'required_level'   => 20,
                'weapon_slot'      => 'twohand',
                'duration_setting' => 'tier3.weapons.flamethrower_aid.craft_duration_hours',
            ],
            'ExoRailgunBehemoth' => [
                'weapon_name_en'   => 'ExoRailgunBehemoth',
                'required_level'   => 24,
                'weapon_slot'      => 'twohand',
                'duration_setting' => 'tier3.weapons.exo_railgun_behemoth.craft_duration_hours',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CraftRecipes();
    }

    public function testAllT3WeaponsExist(): void
    {
        foreach (array_keys($this->expectedT3Weapons()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertNotNull($recipe, "T3 weapon recipe '{$recipeKey}' отсутствует в Config\\CraftRecipes");
        }
    }

    public function testT3WeaponsHaveWeaponOutputType(): void
    {
        foreach (array_keys($this->expectedT3Weapons()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                'weapon',
                $recipe['output_type'] ?? null,
                "Recipe '{$recipeKey}' должен иметь output_type='weapon' для GenericCraftCompletionHandler dispatch"
            );
        }
    }

    public function testT3WeaponsHaveCorrectWeaponNameEn(): void
    {
        foreach ($this->expectedT3Weapons() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['weapon_name_en'],
                $recipe['weapon_name_en'] ?? null,
                "Recipe '{$recipeKey}'.weapon_name_en должен совпадать с weapons.name_en в БД"
            );
        }
    }

    public function testT3WeaponsRequireProfessionalWorkbench(): void
    {
        foreach (array_keys($this->expectedT3Weapons()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $gate   = $recipe['required_crafted_items'] ?? [];
            $this->assertIsArray($gate, "Recipe '{$recipeKey}' должен иметь required_crafted_items");
            $this->assertArrayHasKey(
                'ProfessionalWorkbench',
                $gate,
                "Recipe '{$recipeKey}'.required_crafted_items должен включать ProfessionalWorkbench (T3 gate)"
            );
            $this->assertSame(1, $gate['ProfessionalWorkbench'], "ProfessionalWorkbench gate qty=1");
        }
    }

    public function testT3WeaponsHaveDurationOverrideSettingKey(): void
    {
        foreach ($this->expectedT3Weapons() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['duration_setting'],
                $recipe['duration_override_setting_key'] ?? null,
                "Recipe '{$recipeKey}'.duration_override_setting_key должен указывать на тirtenant GameSettings key"
            );
        }
    }

    public function testT3WeaponsHaveCorrectRequiredLevelAndSlot(): void
    {
        foreach ($this->expectedT3Weapons() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['required_level'],
                $recipe['required_level'] ?? null,
                "Recipe '{$recipeKey}'.required_level mismatch"
            );
            $this->assertSame(
                $expected['weapon_slot'],
                $recipe['weapon_slot'] ?? null,
                "Recipe '{$recipeKey}'.weapon_slot mismatch"
            );
        }
    }

    public function testT3WeaponsHaveCoreRecipeFields(): void
    {
        $required = [
            'task_name', 'output_type', 'weapon_name_en', 'weapon_slot',
            'required_strength', 'required_level', 'gold_required',
            'resources', 'crafted_items', 'requires_base',
            'required_crafted_items', 'duration_override_setting_key',
            'image_in_progress', 'start_caption_name', 'info_callback',
            'item_name_eng', 'item_name_rus', 'icon_emoji', 'zone_emoji', 'zone_name',
            'strength_bonus', 'agility_bonus', 'image_completed', 'craft_again_callback',
        ];
        foreach (array_keys($this->expectedT3Weapons()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            foreach ($required as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $recipe,
                    "Recipe '{$recipeKey}' missing field: {$field}"
                );
            }
        }
    }

    public function testT3WeaponsRequireBase(): void
    {
        foreach (array_keys($this->expectedT3Weapons()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertTrue(
                (bool) ($recipe['requires_base'] ?? false),
                "Recipe '{$recipeKey}' должен иметь requires_base=true (T3 crafting привязан к базе)"
            );
        }
    }
}
