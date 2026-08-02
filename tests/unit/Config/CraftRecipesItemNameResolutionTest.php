<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * Регрессия 2026-08-02 — «Не могу отремонтировать саперную лопату».
 *
 * Ремонт стартует от строки в БД (`crafted_items.name_eng`), а рецепт лежит под
 * СВОИМ ключом: 'Sapper Shovel' → 'SapperShovel', 'Golden Hoe' → 'GoldenHoe'.
 * Старый код искал `CraftRecipes::get($nameEng)` → null → игрок видел
 * «Инструмент не найден или не нуждается в ремонте» на инструменте, который
 * экран же и показывал изношенным (на проде: 5 лопат у 3 игроков + 1 мотыга).
 *
 * Гарантии:
 *   - `findByItemNameEng()` резолвит по name_eng, включая имена С ПРОБЕЛАМИ.
 *   - Каждый item_name_eng из конфига резолвится обратно в свой же рецепт.
 *   - Оба пути ремонта (ресурсы S5b + NPC gold) ходят именно через этот резолвер
 *     (source-scan — чтобы «оптимизация» обратно на ->get() не убила фичу молча).
 *
 * @internal
 */
final class CraftRecipesItemNameResolutionTest extends CIUnitTestCase
{
    private CraftRecipes $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CraftRecipes();
    }

    /**
     * @return array<string,array{0:string,1:string}> name_eng → ожидаемый task_name
     */
    public static function spacedItemNameProvider(): array
    {
        return [
            'Sapper Shovel' => ['Sapper Shovel', 'craftSapperShovel'],
            'Golden Hoe'    => ['Golden Hoe', 'craftGoldenHoe'],
        ];
    }

    /**
     * @dataProvider spacedItemNameProvider
     */
    public function testResolvesToolNamesWithSpaces(string $nameEng, string $expectedTask): void
    {
        $recipe = $this->cfg->findByItemNameEng($nameEng);

        $this->assertIsArray(
            $recipe,
            "Рецепт для crafted_items.name_eng='{$nameEng}' не найден — ремонт этого инструмента мёртв."
        );
        $this->assertSame($expectedTask, $recipe['task_name'] ?? null);
        $this->assertSame($nameEng, $recipe['item_name_eng'] ?? null);
        $this->assertNotEmpty(
            $recipe['resources'] ?? [],
            "У '{$nameEng}' пустой resources — не из чего считать стоимость ремонта."
        );
    }

    public function testEveryItemNameEngResolvesBackToItsRecipe(): void
    {
        foreach ($this->cfg->recipes as $recipeKey => $recipe) {
            if (! is_array($recipe)) {
                continue;
            }
            $nameEng = $recipe['item_name_eng'] ?? null;
            if (! is_string($nameEng) || $nameEng === '') {
                continue;
            }

            $resolved = $this->cfg->findByItemNameEng($nameEng);
            $this->assertIsArray($resolved, "item_name_eng='{$nameEng}' (рецепт '{$recipeKey}') не резолвится");
            $this->assertSame(
                $nameEng,
                $resolved['item_name_eng'] ?? null,
                "item_name_eng='{$nameEng}' резолвится в чужой рецепт"
            );
        }
    }

    public function testFallsBackToRecipeKeyWhenNamesMatch(): void
    {
        // Большинство инструментов: ключ == name_eng (LumberjackAxe, IronPickaxe…).
        $recipe = $this->cfg->findByItemNameEng('LumberjackAxe');
        $this->assertIsArray($recipe);
        $this->assertSame('craftLumberjackAxe', $recipe['task_name'] ?? null);
    }

    public function testUnknownAndEmptyNamesReturnNull(): void
    {
        $this->assertNull($this->cfg->findByItemNameEng(''));
        $this->assertNull($this->cfg->findByItemNameEng('Definitely Not A Craft'));
    }

    /**
     * Source-scan: оба repair-экрана обязаны резолвить рецепт по name_eng.
     * `->get($nameEng)` здесь = регрессия «инструмент не найден».
     *
     * @return array<string,array{0:string}>
     */
    public static function repairActionProvider(): array
    {
        $base = APPPATH . 'Controllers/Telegram/Commands/Actions/';

        return [
            'RepairToolsList'    => [$base . 'Craft/Repair/RepairToolsListAction.php'],
            'RepairCraftedItem'  => [$base . 'Craft/Repair/RepairCraftedItemAction.php'],
            'NpcRepair'          => [$base . 'Craft/Repair/NpcRepairAction.php'],
            'RobotRepairBase'    => [$base . 'Camp/Buildings/Robots/RobotRepairBaseAction.php'],
            'RepairDroneBase'    => [$base . 'Drone/RepairDroneBaseAction.php'],
        ];
    }

    /**
     * @dataProvider repairActionProvider
     */
    public function testRepairActionsResolveRecipeByItemName(string $file): void
    {
        $this->assertFileExists($file);
        $src = file_get_contents($file);
        $this->assertIsString($src);

        $this->assertStringContainsString(
            'findByItemNameEng(',
            $src,
            basename($file) . ': рецепт должен резолвиться по crafted_items.name_eng, иначе ремонт умирает '
                . 'для предметов, у которых ключ рецепта ≠ name_eng.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/->get\(\$name(Eng|En)\b/',
            $src,
            basename($file) . ': `->get($nameEng)` — та самая регрессия «Инструмент не найден».'
        );
    }
}
