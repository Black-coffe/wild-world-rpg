<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * S20 (v0.51.202) — recipe config tests для 3 T3 utility tools (ADR-026 reusable, Фаза 4 5/5).
 *
 * Guarantees:
 *   - Все 3 рецепта существуют.
 *   - output_type НЕ задан → default 'crafted_item' path (type='tool' → crafted_items_log).
 *   - item_name_eng 1:1 совпадает с crafted_items.name_eng — ВКЛЮЧАЯ ПРОБЕЛЫ
 *     ('Sapper Shovel', 'Golden Hoe'). Несовпадение = completion handler не найдёт
 *     row и task завершится тихо без выдачи инструмента.
 *   - required_crafted_items включает ProfessionalWorkbench (T3 gate).
 *   - duration_override_setting_key → existing GameSettings key (tier3.utility.*).
 *   - info_callback prefix craftPreviewT3Utility_ (CallbackRoutes).
 *   - zone_name='инструменты', requires_base=true.
 *
 * @internal
 */
final class CraftRecipesT3UtilityTest extends CIUnitTestCase
{
    private CraftRecipes $cfg;

    /**
     * @return array<string,array{item_name_eng:string,required_level:int,duration_setting:string}>
     */
    private function expectedT3Utility(): array
    {
        return [
            'DiamondPickaxe' => [
                'item_name_eng'    => 'DiamondPickaxe',
                'required_level'   => 20,
                'duration_setting' => 'tier3.utility.diamond_pickaxe.craft_duration_hours',
            ],
            'SapperShovel' => [
                'item_name_eng'    => 'Sapper Shovel', // ПРОБЕЛ — crafted_items.name_eng
                'required_level'   => 16,
                'duration_setting' => 'tier3.utility.sapper_shovel.craft_duration_hours',
            ],
            'GoldenHoe' => [
                'item_name_eng'    => 'Golden Hoe', // ПРОБЕЛ — crafted_items.name_eng
                'required_level'   => 16,
                'duration_setting' => 'tier3.utility.golden_hoe.craft_duration_hours',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CraftRecipes();
    }

    public function testAllT3UtilityExist(): void
    {
        foreach (array_keys($this->expectedT3Utility()) as $recipeKey) {
            $this->assertNotNull($this->cfg->get($recipeKey), "T3 utility recipe '{$recipeKey}' отсутствует");
        }
    }

    public function testT3UtilityUseDefaultCraftedItemOutput(): void
    {
        foreach (array_keys($this->expectedT3Utility()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertArrayNotHasKey(
                'output_type',
                $recipe,
                "Recipe '{$recipeKey}' НЕ должен задавать output_type (tool = default crafted_item path)"
            );
        }
    }

    public function testT3UtilityItemNameEngMatchesDbWithSpaces(): void
    {
        foreach ($this->expectedT3Utility() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['item_name_eng'],
                $recipe['item_name_eng'] ?? null,
                "Recipe '{$recipeKey}'.item_name_eng должен совпадать с crafted_items.name_eng (с пробелами!)"
            );
        }
    }

    public function testT3UtilityRequireProfessionalWorkbench(): void
    {
        foreach (array_keys($this->expectedT3Utility()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $gate   = $recipe['required_crafted_items'] ?? [];
            $this->assertIsArray($gate);
            $this->assertArrayHasKey('ProfessionalWorkbench', $gate, "Recipe '{$recipeKey}' gate ProfessionalWorkbench");
            $this->assertSame(1, $gate['ProfessionalWorkbench']);
        }
    }

    public function testT3UtilityHaveDurationOverrideSettingKey(): void
    {
        foreach ($this->expectedT3Utility() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['duration_setting'],
                $recipe['duration_override_setting_key'] ?? null,
                "Recipe '{$recipeKey}'.duration_override_setting_key mismatch"
            );
        }
    }

    public function testT3UtilityHaveCorrectRequiredLevel(): void
    {
        foreach ($this->expectedT3Utility() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame($expected['required_level'], $recipe['required_level'] ?? null);
        }
    }

    public function testT3UtilityInfoCallbackUsesPreviewPrefix(): void
    {
        foreach (array_keys($this->expectedT3Utility()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                'craftPreviewT3Utility_' . $recipeKey,
                $recipe['info_callback'] ?? '',
                "Recipe '{$recipeKey}'.info_callback prefix mismatch"
            );
        }
    }

    public function testT3UtilityAreInToolsZone(): void
    {
        foreach (array_keys($this->expectedT3Utility()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame('инструменты', $recipe['zone_name'] ?? null);
        }
    }

    public function testT3UtilityHaveCoreRecipeFields(): void
    {
        $required = [
            'task_name', 'required_level', 'gold_required',
            'resources', 'crafted_items', 'requires_base',
            'required_crafted_items', 'duration_override_setting_key',
            'image_in_progress', 'start_caption_name', 'info_callback',
            'item_name_eng', 'item_name_rus', 'icon_emoji', 'zone_emoji', 'zone_name',
            'agility_bonus', 'intellect_bonus', 'image_completed', 'craft_again_callback',
        ];
        foreach (array_keys($this->expectedT3Utility()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $recipe, "Recipe '{$recipeKey}' missing field: {$field}");
            }
        }
    }

    public function testT3UtilityRequireBase(): void
    {
        foreach (array_keys($this->expectedT3Utility()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertTrue((bool) ($recipe['requires_base'] ?? false), "Recipe '{$recipeKey}' requires_base");
        }
    }
}
