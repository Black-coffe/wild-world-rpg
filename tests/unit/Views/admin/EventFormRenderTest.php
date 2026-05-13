<?php

declare(strict_types=1);

namespace Tests\Unit\Views\Admin;

use CodeIgniter\Test\CIUnitTestCase;
use Config\WorldEvents;

/**
 * Phase D pilot (2026-05-13, commit `9d5aabb`+fix `11c1dc3`) — smoke на
 * `_event_form.php` partial через CI4 view() helper.
 *
 * Заменяет HTTP-curl smoke (testbot закрыт edge-network'ом от внешнего curl;
 * spark CLI ругается на CSRF в IncomingRequest). Feature-test использует
 * полный CI4 bootstrap с IncomingRequest по умолчанию → view() рендерит как
 * на реальном HTTP-запросе.
 *
 * Покрывает критический урок F1.4: array-type-hint падал на BiomeEntity
 * после migration (memory feedback_entity_migration_grep_synonyms).
 *
 * @internal
 */
final class EventFormRenderTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);  // CI4: `set_checkbox`, `csrf_field`, `site_url`, `old` доступны после.
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureBiomes(): array
    {
        return [
            ['id' => 1, 'name' => 'Леса',         'description' => '', 'biome_type' => 'plain'],
            ['id' => 2, 'name' => 'Тропические',  'description' => '', 'biome_type' => 'wet'],
            ['id' => 3, 'name' => 'Пустыни',      'description' => '', 'biome_type' => 'desert'],
        ];
    }

    public function testCreateFormRenders(): void
    {
        $html = view('admin/partials/_event_form', [
            'mode'   => 'create',
            'biomes' => $this->fixtureBiomes(),
        ]);

        $this->assertIsString($html);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('Создать событие', $html, 'Submit button label');
        $this->assertStringContainsString('admin/events/store', $html, 'Form action');
        $this->assertStringContainsString('name="biome_ids[]"', $html, 'Biome checkboxes');
        $this->assertStringContainsString('name="name_english"', $html, 'name_english field');
        $this->assertStringContainsString('name="event_type"', $html, 'event_type select');
        $this->assertStringContainsString('F7 архитектура', $html, 'Info banner про WorldEvents.php (только create)');
    }

    public function testCreateFormBiomeCheckboxesAllCheckedByDefault(): void
    {
        $html = view('admin/partials/_event_form', [
            'mode'   => 'create',
            'biomes' => $this->fixtureBiomes(),
        ]);

        // Все 3 биома должны быть checked по умолчанию в create-форме (legacy-поведение).
        $matched = preg_match_all('/name="biome_ids\[\]"[^>]+checked/u', $html);
        $this->assertSame(3, $matched, 'Все биомы должны быть checked при create');
    }

    public function testEditFormRenders(): void
    {
        $event = [
            'event_id'           => 42,
            'name'               => 'Тестовый ураган',
            'name_english'       => 'TestHurricane',
            'description'        => 'Описание тестового события',
            'biome_ids'          => json_encode([1, 3]),
            'event_type'         => 'global',
            'duration'           => 60,
            'frequency_per_week' => 3,
            'effect_type'        => 'damage',
            'effect_value'       => 5,
            'protection_item_id' => 7,
            'random_coverage'    => 1,
            'img_path'           => 'uploads/test.jpg',
        ];

        $html = view('admin/partials/_event_form', [
            'mode'   => 'edit',
            'event'  => $event,
            'biomes' => $this->fixtureBiomes(),
        ]);

        $this->assertIsString($html);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('Тестовый ураган', $html, 'Event name pre-filled value');
        $this->assertStringContainsString('Обновить событие', $html, 'Submit button label');
        $this->assertStringContainsString('admin/events/update/42', $html, 'Form action с event_id');
        $this->assertStringContainsString('TestHurricane', $html, 'name_english value');
    }

    public function testEditFormBiomeCheckboxesSelectedByEventBiomeIds(): void
    {
        $event = [
            'event_id'           => 42,
            'name'               => 'X',
            'name_english'       => 'X',
            'description'        => '',
            'biome_ids'          => json_encode([1, 3]),  // Только биомы 1 и 3 selected
            'event_type'         => 'local',
            'duration'           => 60,
            'frequency_per_week' => 1,
            'effect_type'        => 'damage',
            'effect_value'       => 5,
            'protection_item_id' => 0,
            'random_coverage'    => 0,
            'img_path'           => '',
        ];

        $html = view('admin/partials/_event_form', [
            'mode'   => 'edit',
            'event'  => $event,
            'biomes' => $this->fixtureBiomes(),
        ]);

        $matched = preg_match_all('/name="biome_ids\[\]"[^>]+checked/u', $html);
        $this->assertSame(2, $matched, 'Только биомы 1 и 3 (из biome_ids JSON) должны быть checked');
    }

    public function testBiomeEntityArrayAccessIsHandled(): void
    {
        // F1.4 migration urok: BiomeModel returns Entity, не array. Partial должен работать
        // с обоими типами (ArrayAccess на BiomeEntity).
        $entityLike = new class implements \ArrayAccess {
            public function offsetGet(mixed $key): mixed
            {
                $data = ['id' => 99, 'name' => 'EntityBiome'];
                return $data[$key] ?? null;
            }
            public function offsetSet(mixed $key, mixed $value): void {}
            public function offsetExists(mixed $key): bool { return true; }
            public function offsetUnset(mixed $key): void {}
        };

        // Test that array_access works (BiomeEntity implements ArrayAccess via trait).
        $this->assertSame(99, $entityLike['id']);
        $this->assertSame('EntityBiome', $entityLike['name']);
    }
}
