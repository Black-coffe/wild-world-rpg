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
        $recipe = $this->cfg->get('Bandage');
        $this->assertSame('craftBandage_1', $recipe['craft_again_callback']);
    }

    public function testUnknownRecipeReturnsNull(): void
    {
        $this->assertNull($this->cfg->get('NonexistentRecipe'));
    }

    public function testKeysIncludesBandage(): void
    {
        $this->assertContains('Bandage', $this->cfg->keys());
    }
}
