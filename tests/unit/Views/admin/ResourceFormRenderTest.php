<?php

declare(strict_types=1);

namespace Tests\Unit\Views\Admin;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase D final (2026-05-13) — view-level smoke на `_resource_form.php` partial.
 *
 * @internal
 */
final class ResourceFormRenderTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function biomes(): array
    {
        return [
            ['id' => 1, 'name' => 'Леса'],
            ['id' => 2, 'name' => 'Тропические'],
            ['id' => 3, 'name' => 'Пустыни'],
        ];
    }

    public function testCreateRenders(): void
    {
        $html = view('admin/partials/_resource_form', ['mode' => 'create', 'biomes' => $this->biomes()]);

        $this->assertStringContainsString('admin/resources/store', $html);
        $this->assertStringContainsString('Создать ресурс', $html);
        $this->assertStringContainsString('name="name_en"', $html);
        $this->assertStringContainsString('name="type[]"', $html);
        $this->assertStringContainsString('name="biome_id[]"', $html);
        $this->assertStringContainsString('value="crafting"', $html);
        $this->assertStringContainsString('value="food"', $html);
        $this->assertStringContainsString('value="water"', $html);
        $this->assertStringContainsString('value="material"', $html);
    }

    public function testEditRendersWithMultiSelectFromCSV(): void
    {
        // CSV-формат в БД (resources.biome_id = "1,3", type = "crafting,food").
        $resource = [
            'id'               => 17,
            'name'             => 'Тестовый ресурс',
            'name_en'          => 'TestResource',
            'keyword'          => 'test',
            'icon_text'        => '🌿',
            'type'             => 'crafting,food',
            'biome_id'         => '1,3',
            'price'            => 5,
            'rarity'           => 6,
            'initial_quantity' => 1000,
            'level_required'   => 2,
            'is_tradeable'     => 1,
            'description'      => 'desc',
        ];

        $html = view('admin/partials/_resource_form', ['mode' => 'edit', 'resource' => $resource, 'biomes' => $this->biomes()]);

        $this->assertStringContainsString('admin/resources/update/17', $html);
        $this->assertStringContainsString('Сохранить изменения', $html);
        $this->assertStringContainsString('value="Тестовый ресурс"', $html);
        $this->assertStringContainsString('value="TestResource"', $html);
        // type[] checkboxes — crafting + food отмечены
        $this->assertMatchesRegularExpression('/<input[^>]+value="crafting"[^>]+checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+value="food"[^>]+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+value="water"[^>]+checked/', $html);
        // biome_id[] options — 1 и 3 selected, 2 нет
        $this->assertMatchesRegularExpression('/<option value="1"\s+selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="3"\s+selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="2"\s+selected>/', $html);
    }

    public function testIsTradeableZeroIsSelected(): void
    {
        $resource = [
            'id' => 1, 'name' => 'X', 'name_en' => 'X', 'keyword' => '', 'icon_text' => '',
            'type' => '', 'biome_id' => '', 'price' => 1, 'rarity' => 1, 'initial_quantity' => 1,
            'level_required' => 1, 'is_tradeable' => 0, 'description' => '',
        ];

        $html = view('admin/partials/_resource_form', ['mode' => 'edit', 'resource' => $resource, 'biomes' => $this->biomes()]);

        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="is_tradeable"[^>]*>.*?<option value="0" selected>/s',
            $html,
        );
    }
}
