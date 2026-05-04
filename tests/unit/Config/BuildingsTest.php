<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Buildings;

/**
 * F2.1 — unit-тесты на recipe-config Buildings.php.
 *
 * Не запускаем Telegram-flow — тестируем что:
 *   1. Arsenal recipe загружается через config('Buildings').
 *   2. Все ожидаемые поля заполнены.
 *   3. Числовые/string значения совпадают с тем что было в legacy
 *      `StartBuildArsenalConstruction.php` v0.1.0 (точные числа взяты
 *      из Buildings.php docstring «Все числа взяты 1:1 из StartBuild...»).
 *
 * При расширении Buildings.php (Workshop, BlastFurnace, ...) —
 * добавлять similar-тест на каждое здание.
 *
 * @internal
 */
final class BuildingsTest extends CIUnitTestCase
{
    private Buildings $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new Buildings();
    }

    public function testArsenalRecipeExists(): void
    {
        $recipe = $this->cfg->get('Arsenal');
        $this->assertNotNull($recipe);
        $this->assertIsArray($recipe);
    }

    public function testArsenalRecipeHasAllRequiredFields(): void
    {
        $recipe = $this->cfg->get('Arsenal');
        $required = [
            'name_rus', 'level_required', 'task_name', 'task_settings',
            'resources', 'crafted_items', 'dependencies', 'image_in_progress',
        ];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $recipe, "Arsenal recipe missing field: $field");
        }
    }

    public function testArsenalLevelRequirement(): void
    {
        $recipe = $this->cfg->get('Arsenal');
        $this->assertSame(15, $recipe['level_required']);
    }

    public function testArsenalResourceCosts(): void
    {
        $recipe = $this->cfg->get('Arsenal');
        $this->assertSame([
            'Ironstone'  => 200,
            'RareMetals' => 60,
            'Oil'        => 70,
            'Sulfur'     => 50,
        ], $recipe['resources']);
    }

    public function testArsenalCraftedItemCosts(): void
    {
        $recipe = $this->cfg->get('Arsenal');
        $this->assertSame([
            'metalFragments'       => 120,
            'wiring'               => 15,
            'electronicComponents' => 8,
        ], $recipe['crafted_items']);
    }

    public function testArsenalDependencies(): void
    {
        $recipe = $this->cfg->get('Arsenal');
        $this->assertSame(
            ['Workshop', 'BlastFurnace', 'SolarStation', 'Laboratory'],
            $recipe['dependencies']
        );
    }

    public function testArsenalTaskName(): void
    {
        $recipe = $this->cfg->get('Arsenal');
        $this->assertSame('startBuildArsenal', $recipe['task_name']);
        $this->assertSame(['building' => 'Arsenal'], $recipe['task_settings']);
    }

    public function testUnknownRecipeReturnsNull(): void
    {
        $this->assertNull($this->cfg->get('NonexistentBuilding'));
    }

    public function testKeysListsRegisteredBuildings(): void
    {
        $keys = $this->cfg->keys();
        $this->assertContains('Arsenal', $keys);
    }
}
