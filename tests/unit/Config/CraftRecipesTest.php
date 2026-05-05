<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * F2.2 — unit-тесты на recipe-config CraftRecipes.php.
 *
 * Bandage recipe должен быть 1:1 с legacy CraftCompletionBandageHandler.php
 * v0.1.0 (числа взяты оттуда). При расширении CraftRecipes на оставшиеся
 * 41 рецепт — добавлять similar-тест на каждый.
 *
 * @internal
 */
final class CraftRecipesTest extends CIUnitTestCase
{
    private CraftRecipes $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CraftRecipes();
    }

    public function testBandageRecipeExists(): void
    {
        $recipe = $this->cfg->get('Bandage');
        $this->assertNotNull($recipe);
    }

    public function testBandageRecipeHasAllRequiredFields(): void
    {
        $recipe   = $this->cfg->get('Bandage');
        $required = [
            'item_name_eng', 'item_name_rus', 'icon_emoji',
            'zone_emoji', 'zone_name', 'agility_bonus',
            'intellect_bonus', 'image_completed', 'craft_again_callback',
        ];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $recipe, "Bandage recipe missing: $field");
        }
    }

    public function testBandageBonusesMatchLegacy(): void
    {
        // Числа из CraftCompletionBandageHandler.php v0.1.0
        // (updateAgilityAndIntellect(charId, 0.02, 0.01)).
        $recipe = $this->cfg->get('Bandage');
        $this->assertSame(0.02, $recipe['agility_bonus']);
        $this->assertSame(0.01, $recipe['intellect_bonus']);
    }

    public function testBandageItemNameEng(): void
    {
        $recipe = $this->cfg->get('Bandage');
        $this->assertSame('Bandage', $recipe['item_name_eng']);
    }

    public function testBandageImagePathIsRelativeToFCPATH(): void
    {
        $recipe = $this->cfg->get('Bandage');
        // F2.2: путь относительный (без http://), GenericCraftCompletionHandler
        // делает FCPATH . $recipe['image_completed'] для encodeFile().
        $this->assertStringStartsNotWith('http', $recipe['image_completed']);
        $this->assertStringStartsWith('uploads/', $recipe['image_completed']);
    }

    public function testCraftAgainCallbackFormat(): void
    {
        // F3.B5 (v0.21.0): callback переехал на унифицированный формат
        // genericCraft_<RecipeKey>_<qty> — обрабатывается GenericCraftActionStart.
        $recipe = $this->cfg->get('Bandage');
        $this->assertSame('genericCraft_Bandage_1', $recipe['craft_again_callback']);
    }

    public function testUnknownRecipeReturnsNull(): void
    {
        $this->assertNull($this->cfg->get('NonexistentRecipe'));
    }

    public function testKeysIncludesBandage(): void
    {
        $this->assertContains('Bandage', $this->cfg->keys());
    }

    /**
     * F3.B5 (v0.21.0) + F3.B6 (v0.22.0) + F3.B7 (v0.23.0):
     * 8 medical + 10 components + 8 tools = 26 рецептов с единой схемой
     * (start-side + completion-side + унифицированный callback).
     */
    public function testAllMedicalRecipesPresentWithFullSchema(): void
    {
        $expectedKeys = [
            // Medical (B5)
            'Bandage', 'Antiseptic', 'PainReliefPower', 'StrengthElixir',
            'Sedative', 'Stimulator', 'Regenerator', 'BasicMedKit',
            // Components (B6)
            'Wiring', 'Fabric', 'Soil', 'ElectronicComponents', 'StoneBlocks',
            'MetalFragments', 'Fertilizer', 'WoodMaterials', 'CharcoalBriquettes',
            'GlassBags',
            // Tools (B7)
            'StonePickaxe', 'IronShovel', 'IronPickaxe', 'LumberjackAxe',
            'FishingRod', 'Hoe', 'FoldingKnife', 'TireIron',
            // Workbench + Robots (B8)
            'WorkbenchOne', 'RobotExplorer', 'RobotGatherer',
        ];

        foreach ($expectedKeys as $key) {
            $recipe = $this->cfg->get($key);
            $this->assertNotNull($recipe, "Recipe missing: {$key}");

            // Start-side поля (для GenericCraftActionStart)
            $this->assertArrayHasKey('task_name', $recipe, "{$key} missing task_name");
            $this->assertArrayHasKey('resources', $recipe, "{$key} missing resources");
            $this->assertArrayHasKey('image_in_progress', $recipe, "{$key} missing image_in_progress");
            $this->assertArrayHasKey('start_caption_name', $recipe, "{$key} missing start_caption_name");
            $this->assertIsArray($recipe['resources'], "{$key} resources must be array");
            $this->assertNotEmpty($recipe['resources'], "{$key} resources must not be empty");

            // Completion-side поля (для GenericCraftCompletionHandler)
            $this->assertArrayHasKey('item_name_eng', $recipe, "{$key} missing item_name_eng");
            $this->assertArrayHasKey('agility_bonus', $recipe);
            $this->assertArrayHasKey('intellect_bonus', $recipe);
            $this->assertArrayHasKey('image_completed', $recipe);

            // Унифицированный callback
            $this->assertSame(
                "genericCraft_{$key}_1",
                $recipe['craft_again_callback'],
                "{$key} craft_again_callback must follow genericCraft_<Key>_1 convention"
            );
        }
    }

    /**
     * F3.B5 — BasicMedKit требует Bandage:5 (crafted_items).
     * GenericCraftActionStart должен проверять и списывать.
     */
    public function testBasicMedKitRequiresBandageCraftedItem(): void
    {
        $recipe = $this->cfg->get('BasicMedKit');
        $this->assertArrayHasKey('crafted_items', $recipe);
        $this->assertSame(5, $recipe['crafted_items']['Bandage'] ?? null);
    }

    /**
     * F3.B6 — Wiring и ElectronicComponents требуют metalFragments
     * (cross-craft зависимость на products самого components-цеха).
     */
    public function testWiringRequiresMetalFragments(): void
    {
        $recipe = $this->cfg->get('Wiring');
        $this->assertSame(3, $recipe['crafted_items']['metalFragments'] ?? null);
    }

    public function testElectronicComponentsRequiresMetalFragments(): void
    {
        $recipe = $this->cfg->get('ElectronicComponents');
        $this->assertSame(5, $recipe['crafted_items']['metalFragments'] ?? null);
    }

    /**
     * F3.B6 — у CharcoalBriquettes картинка с расширением .png
     * (исторически у остальных components — .jpg).
     */
    public function testCharcoalBriquettesUsesPngExtension(): void
    {
        $recipe = $this->cfg->get('CharcoalBriquettes');
        $this->assertStringEndsWith('.png', $recipe['image_completed']);
        $this->assertStringEndsWith('.png', $recipe['image_in_progress']);
    }

    /**
     * F3.B7 — у tools картинка процесса крафта (image_in_progress) общая
     * (huge_mechanical_workbench.jpg) для всех инструментов кроме FishingRod.
     * Картинка завершения (image_completed) уникальна для каждого инструмента.
     */
    public function testToolsShareWorkbenchImageInProgress(): void
    {
        $sharedImage = 'uploads/telegram/craft/huge_mechanical_workbench.jpg';

        $sharedKeys = ['StonePickaxe', 'IronShovel', 'IronPickaxe', 'LumberjackAxe', 'Hoe', 'FoldingKnife', 'TireIron'];
        foreach ($sharedKeys as $key) {
            $recipe = $this->cfg->get($key);
            $this->assertSame(
                $sharedImage,
                $recipe['image_in_progress'],
                "{$key} should use shared workbench image_in_progress"
            );
            $this->assertNotSame(
                $sharedImage,
                $recipe['image_completed'],
                "{$key} should have unique image_completed (not workbench)"
            );
        }

        // FishingRod — exception, использует свою картинку и для start и для completion
        $rod = $this->cfg->get('FishingRod');
        $this->assertSame($rod['image_in_progress'], $rod['image_completed']);
        $this->assertStringContainsString('fishing-rod', $rod['image_in_progress']);
    }

    /**
     * F3.B8 — WorkbenchOne, RobotExplorer, RobotGatherer требуют золото
     * (новое поле gold_required, обрабатывается GenericCraftActionStart).
     */
    public function testB8RecipesRequireGold(): void
    {
        $expectedGold = [
            'WorkbenchOne'   => 20000,
            'RobotExplorer'  => 15000,
            'RobotGatherer'  => 21000,
        ];
        foreach ($expectedGold as $key => $gold) {
            $recipe = $this->cfg->get($key);
            $this->assertSame($gold, $recipe['gold_required'] ?? null, "{$key} gold_required mismatch");
            $this->assertTrue($recipe['requires_base'] ?? false, "{$key} should require base");
        }
    }

    /**
     * F3.B8 — RobotExplorer/RobotGatherer требуют RoboticsWorkshop;
     * RobotGatherer дополнительно требует Workshop.
     */
    public function testB8RobotsRequireBuildings(): void
    {
        $explorer = $this->cfg->get('RobotExplorer');
        $this->assertSame(['RoboticsWorkshop'], $explorer['required_buildings'] ?? null);

        $gatherer = $this->cfg->get('RobotGatherer');
        $this->assertSame(['RoboticsWorkshop', 'Workshop'], $gatherer['required_buildings'] ?? null);

        // WorkbenchOne — только база, без зданий
        $workbench = $this->cfg->get('WorkbenchOne');
        $this->assertSame([], $workbench['required_buildings'] ?? null);
    }
}
