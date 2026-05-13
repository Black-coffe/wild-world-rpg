<?php

declare(strict_types=1);

namespace Tests\Unit\Views\Admin;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase D final (2026-05-13) — view-level smoke на `_world_object_form.php` partial.
 *
 * Покрывает bug-fix legacy edit-view: `explode(',', $object['biome_id'])` для
 * JSON-string `[1,3]` давал мусор. Defensive decode: JSON-first, CSV fallback.
 *
 * @internal
 */
final class WorldObjectFormRenderTest extends CIUnitTestCase
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
        $html = view('admin/partials/_world_object_form', ['mode' => 'create', 'biomes' => $this->biomes()]);

        $this->assertStringContainsString('admin/world-objects/store', $html);
        $this->assertStringContainsString('Добавить объект', $html);
        $this->assertStringContainsString('name="name_en"', $html);
        $this->assertStringContainsString('name="biome_id[]"', $html);
        $this->assertStringContainsString('name="discovery_tools"', $html);
        $this->assertStringContainsString('name="contents"', $html);
        $this->assertStringContainsString('Активный', $html);
        $this->assertStringContainsString('Неактивный', $html);
    }

    public function testEditRendersWithJsonBiomeIds(): void
    {
        // JSON-формат biome_id в БД (после WorldObjectController::prepareJsonData).
        $object = [
            'id'              => 42,
            'name'            => 'Брошенный грузовик',
            'name_en'         => 'AbandonedTruck',
            'status'          => 'active',
            'description'    => 'desc',
            'biome_id'        => '[1,3]',  // JSON-encoded array
            'max_count'       => 10,
            'respawn_time'    => 24,
            'discovery_tools' => '["Лом","Фонарь"]',
            'contents'        => '{"resources":{"Галька":350}}',
        ];

        $html = view('admin/partials/_world_object_form', ['mode' => 'edit', 'object' => $object, 'biomes' => $this->biomes()]);

        $this->assertStringContainsString('admin/world-objects/update/42', $html);
        $this->assertStringContainsString('Обновить объект', $html);
        $this->assertStringContainsString('value="Брошенный грузовик"', $html);

        // Defensive JSON decode: биомы 1 и 3 selected, 2 нет.
        $this->assertMatchesRegularExpression('/<option value="1"\s+selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="3"\s+selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="2"\s+selected>/', $html);
    }

    public function testEditWithLegacyCsvBiomeIdsFallsBackGracefully(): void
    {
        // Legacy data: biome_id может быть в CSV-формате (до миграции на JSON).
        // Defensive код в partial должен fallback на explode(',').
        $object = [
            'id' => 1, 'name' => 'X', 'name_en' => 'X', 'status' => 'active',
            'description' => '',
            'biome_id' => '1,2',  // CSV-формат (legacy)
            'max_count' => 1, 'respawn_time' => 1,
            'discovery_tools' => '', 'contents' => '',
        ];

        $html = view('admin/partials/_world_object_form', ['mode' => 'edit', 'object' => $object, 'biomes' => $this->biomes()]);

        $this->assertMatchesRegularExpression('/<option value="1"\s+selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="2"\s+selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="3"\s+selected>/', $html);
    }

    public function testEditPopulatesJsonFieldValues(): void
    {
        $object = [
            'id' => 1, 'name' => 'X', 'name_en' => 'X', 'status' => 'active',
            'description' => '',
            'biome_id' => '[]', 'max_count' => 1, 'respawn_time' => 1,
            'discovery_tools' => '["Лом"]',
            'contents'        => '{"a":1}',
        ];

        $html = view('admin/partials/_world_object_form', ['mode' => 'edit', 'object' => $object, 'biomes' => $this->biomes()]);

        // JSON-строки попадают в value="..." через esc(). Проверяем что они там.
        $this->assertStringContainsString('discovery_tools', $html);
        $this->assertStringContainsString('contents', $html);
        // Лом — кириллица, после esc() остаётся читаемой.
        $this->assertStringContainsString('Лом', $html);
    }

    public function testEditWithArrayBiomeIdFromEntity(): void
    {
        // Гипотетический случай: F1.4 entity может вернуть biome_id как array,
        // не string. Defensive код должен обработать.
        $object = [
            'id' => 1, 'name' => 'X', 'name_en' => 'X', 'status' => 'active',
            'description' => '',
            'biome_id' => [2, 3],  // array directly
            'max_count' => 1, 'respawn_time' => 1,
            'discovery_tools' => '', 'contents' => '',
        ];

        $html = view('admin/partials/_world_object_form', ['mode' => 'edit', 'object' => $object, 'biomes' => $this->biomes()]);

        $this->assertMatchesRegularExpression('/<option value="2"\s+selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="3"\s+selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="1"\s+selected>/', $html);
    }
}
