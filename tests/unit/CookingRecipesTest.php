<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Telegram\Commands\Actions\Craft\Cooking\CampfireCookingSelect;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * V8 (vNext) — anti-drift: меню готовки (CampfireCookingSelect::COOKING_RECIPES)
 * ↔ Config\CraftRecipes. Каждый ключ блюда резолвится в рецепт с согласованными
 * item_name_eng / task_name, без сезонного гейта (готовка круглогодична).
 *
 * @internal
 */
final class CookingRecipesTest extends CIUnitTestCase
{
    public function testFiveCookingRecipes(): void
    {
        $this->assertCount(5, CampfireCookingSelect::COOKING_RECIPES);
        $this->assertSame(
            ['MushroomSoup', 'BerryBrew', 'BakedFruit', 'GrainPorridge', 'HeartyStew'],
            CampfireCookingSelect::COOKING_RECIPES,
        );
    }

    public function testCookingRecipesResolveInConfig(): void
    {
        $cfg = config('CraftRecipes');

        foreach (CampfireCookingSelect::COOKING_RECIPES as $key) {
            $recipe = $cfg->get($key);
            $this->assertIsArray($recipe, "cooking recipe {$key} отсутствует в CraftRecipes");
            $this->assertSame($key, $recipe['item_name_eng'] ?? null, "{$key}: item_name_eng mismatch");
            $this->assertSame('craft' . $key, $recipe['task_name'] ?? null, "{$key}: task_name mismatch");
            // Готовка круглогодична — без сезонного гейта.
            $this->assertArrayNotHasKey('required_season', $recipe, "{$key}: cooking не должен иметь required_season");
            // Default crafted_item output (type='drug' через crafted_items).
            $this->assertArrayNotHasKey('output_type', $recipe, "{$key}: cooking output должен быть default crafted_item");
            // Ингредиенты заданы.
            $this->assertIsArray($recipe['resources'] ?? null, "{$key}: resources missing");
            $this->assertNotEmpty($recipe['resources'], "{$key}: resources пусты");
        }
    }
}
