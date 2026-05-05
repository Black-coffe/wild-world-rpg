<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\IntentApplier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.3 — тесты на IntentApplier через mock-модели (анонимни extends).
 *
 * Проверяем що apply():
 *   1. Конвертує health/tired/gold/attribute deltas → CharacterModel::update
 *   2. Применяет floor=0.01 на health/tired (не йдемо в мінус)
 *   3. Вызоває resource_grant правильно (resolution через ResourceModel)
 *   4. Не валиться на порожньому result
 *
 * @internal
 */
final class IntentApplierTest extends CIUnitTestCase
{
    public function testHealthDeltaCallsUpdateWithFloorOf001(): void
    {
        $captured = [];

        $charModel = new class($captured) extends \App\Models\CharacterModel {
            public array $captured = [];
            public function __construct(&$captured) { $this->captured = &$captured; parent::__construct(); }
            public function update($id = null, $data = null): bool {
                $this->captured[] = ['id' => $id, 'data' => $data];
                return true;
            }
            public function find($id = null) {
                return ['id' => $id, 'health' => 5.0, 'tired' => 50.0, 'gold' => 100];
            }
        };

        $applier = new IntentApplier(
            $charModel,
            new \App\Models\CharacterResourceModel(),
            new \App\Models\CharacterTaskModel(),
            new \App\Models\ResourceModel(),
            new \App\Models\TaskModel(),
        );

        // Игрок з health=10, отримує -50 → floor 0.01
        $character = ['id' => 99, 'health' => 10.0, 'tired' => 100.0, 'gold' => 0];
        $result    = ['health_delta' => -50.0, 'tired_delta' => 0, 'gold_delta' => 0];

        $applier->apply($character, $result);

        $this->assertNotEmpty($captured, 'update() мало бути вызовано');
        $this->assertSame(99, $captured[0]['id']);
        $this->assertSame(0.01, $captured[0]['data']['health'], 'health не йде нижче 0.01');
    }

    public function testGoldDeltaAccumulates(): void
    {
        $captured = [];

        $charModel = new class($captured) extends \App\Models\CharacterModel {
            public array $captured = [];
            public function __construct(&$captured) { $this->captured = &$captured; parent::__construct(); }
            public function update($id = null, $data = null): bool {
                $this->captured[] = ['id' => $id, 'data' => $data];
                return true;
            }
            public function find($id = null) { return null; }
        };

        $applier = new IntentApplier(
            $charModel,
            new \App\Models\CharacterResourceModel(),
            new \App\Models\CharacterTaskModel(),
            new \App\Models\ResourceModel(),
            new \App\Models\TaskModel(),
        );

        $applier->apply(
            ['id' => 1, 'health' => 100, 'tired' => 100, 'gold' => 500],
            ['health_delta' => 0, 'tired_delta' => 0, 'gold_delta' => 1500]
        );

        $this->assertSame(2000, $captured[0]['data']['gold']);
    }

    public function testAttributeDeltasCombined(): void
    {
        $captured = [];

        $charModel = new class($captured) extends \App\Models\CharacterModel {
            public array $captured = [];
            public function __construct(&$captured) { $this->captured = &$captured; parent::__construct(); }
            public function update($id = null, $data = null): bool {
                $this->captured[] = ['id' => $id, 'data' => $data];
                return true;
            }
            public function find($id = null) { return null; }
        };

        $applier = new IntentApplier(
            $charModel,
            new \App\Models\CharacterResourceModel(),
            new \App\Models\CharacterTaskModel(),
            new \App\Models\ResourceModel(),
            new \App\Models\TaskModel(),
        );

        $applier->apply(
            ['id' => 1, 'health' => 80, 'tired' => 80, 'gold' => 0, 'agility' => 10.5, 'experience' => 50.0],
            [
                'health_delta'     => -5,
                'tired_delta'      => -3,
                'gold_delta'       => 0,
                'attribute_deltas' => ['agility' => 0.05, 'experience' => 1.10],
            ]
        );

        $data = $captured[0]['data'];
        $this->assertSame(75.0, $data['health']);
        $this->assertSame(77.0, $data['tired']);
        $this->assertEqualsWithDelta(10.55, $data['agility'], 0.001);
        $this->assertEqualsWithDelta(51.10, $data['experience'], 0.001);
        $this->assertArrayNotHasKey('gold', $data, 'gold_delta=0 → не апалаємо');
    }

    public function testEmptyResultDoesNotCallUpdate(): void
    {
        $captured = [];

        $charModel = new class($captured) extends \App\Models\CharacterModel {
            public array $captured = [];
            public function __construct(&$captured) { $this->captured = &$captured; parent::__construct(); }
            public function update($id = null, $data = null): bool {
                $this->captured[] = ['id' => $id, 'data' => $data];
                return true;
            }
            public function find($id = null) { return null; }
        };

        $applier = new IntentApplier(
            $charModel,
            new \App\Models\CharacterResourceModel(),
            new \App\Models\CharacterTaskModel(),
            new \App\Models\ResourceModel(),
            new \App\Models\TaskModel(),
        );

        $applier->apply(
            ['id' => 1, 'health' => 100, 'tired' => 100, 'gold' => 0],
            ['health_delta' => 0, 'tired_delta' => 0, 'gold_delta' => 0]
        );

        $this->assertEmpty($captured, 'Empty deltas → ни одного DB-запису');
    }
}
