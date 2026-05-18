<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CraftRecipes;

/**
 * S8 — anti-drift guard для CraftRecipes ингредиентов.
 *
 * Каждый рецепт описывает 2 типа ингредиентов:
 *   - `resources => ['РусскоеИмя' => N]` — резолв через `resources.name`
 *   - `crafted_items => ['EnglishName' => N]` — резолв через `crafted_items.name_eng`
 *
 * Если рецепт ссылается на несуществующий name → silent fail при крафте
 * («не хватает ингредиента, которого нет в игре»). Этот тест ловит
 * такие dead refs до того как они доедут до игрока.
 *
 * Skip если test DB не имеет таблиц resources/crafted_items
 * (тест проходит на testbot/проде с реальной БД).
 *
 * Pattern взят из {@see WorldEventsTest}.
 *
 * @internal
 */
final class CraftRecipesIngredientsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $seed    = '';

    private CraftRecipes $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CraftRecipes();
    }

    /**
     * @return array{resources: array<string,bool>, crafted_items: array<string,bool>}|null
     */
    private function fetchValidNamesOrSkip(): ?array
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('resources') || !$db->tableExists('crafted_items')) {
            $this->markTestSkipped(
                "Test DB не має таблиць 'resources'/'crafted_items'. " .
                "Тест passes на testbot/проді з реальною БД."
            );
            return null;
        }

        $resources     = array_column(
            $db->table('resources')->select('name')->get()->getResultArray(),
            'name'
        );
        $craftedItems = array_column(
            $db->table('crafted_items')->select('name_eng')->get()->getResultArray(),
            'name_eng'
        );

        return [
            'resources'     => array_flip($resources),
            'crafted_items' => array_flip($craftedItems),
        ];
    }

    /**
     * @return list<array{recipe: string, ingredient: string}>
     */
    private function collectDeadResourceRefs(array $validNames): array
    {
        $dead = [];
        foreach ($this->cfg->recipes as $recipeKey => $recipe) {
            foreach (($recipe['resources'] ?? []) as $name => $qty) {
                if (!isset($validNames[$name])) {
                    $dead[] = ['recipe' => $recipeKey, 'ingredient' => $name];
                }
            }
        }
        return $dead;
    }

    /**
     * @return list<array{recipe: string, ingredient: string}>
     */
    private function collectDeadCraftedItemRefs(array $validNames): array
    {
        $dead = [];
        foreach ($this->cfg->recipes as $recipeKey => $recipe) {
            foreach (($recipe['crafted_items'] ?? []) as $nameEn => $qty) {
                if (!isset($validNames[$nameEn])) {
                    $dead[] = ['recipe' => $recipeKey, 'ingredient' => $nameEn];
                }
            }
        }
        return $dead;
    }

    /**
     * Каждый ru-ингредиент в `recipes[].resources` существует в `resources.name`.
     */
    public function testAllResourceIngredientsExistInDb(): void
    {
        $valid = $this->fetchValidNamesOrSkip();
        if ($valid === null) {
            return;
        }

        $dead = $this->collectDeadResourceRefs($valid['resources']);

        $this->assertSame(
            [],
            $dead,
            'Dead resource refs в CraftRecipes (нет в resources.name): ' .
            implode(', ', array_map(
                static fn (array $d): string => "{$d['recipe']}→«{$d['ingredient']}»",
                $dead
            ))
        );
    }

    /**
     * Каждый en-ингредиент в `recipes[].crafted_items` существует в `crafted_items.name_eng`.
     */
    public function testAllCraftedItemIngredientsExistInDb(): void
    {
        $valid = $this->fetchValidNamesOrSkip();
        if ($valid === null) {
            return;
        }

        $dead = $this->collectDeadCraftedItemRefs($valid['crafted_items']);

        $this->assertSame(
            [],
            $dead,
            'Dead crafted_item refs в CraftRecipes (нет в crafted_items.name_eng): ' .
            implode(', ', array_map(
                static fn (array $d): string => "{$d['recipe']}→«{$d['ingredient']}»",
                $dead
            ))
        );
    }

    /**
     * Sanity: recipes array не пустой (regression guard).
     */
    public function testRecipesArrayNotEmpty(): void
    {
        $this->assertNotEmpty($this->cfg->recipes, 'CraftRecipes->recipes должно содержать рецепты');
    }
}
