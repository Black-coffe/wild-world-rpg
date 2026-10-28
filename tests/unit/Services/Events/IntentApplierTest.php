<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\IntentApplier;
use App\Services\Player\CharacterStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.3 — тести на IntentApplier без БД.
 *
 * s2-conditional-writes-06 (F16): статы й золото пишуться дельтою через
 * CharacterStatsService::adjust() від свіжих значень, а не абсолютом зі снімка.
 * Двійник сервісу підміняє лише mutate() (SELECT … FOR UPDATE + UPDATE):
 * справжня математика adjust()/applyDeltas()/clamp() виконується над «свіжими»
 * значеннями двійника, а записане значення перехоплюється.
 *
 * Перевіряємо що apply():
 *   1. Застосовує floor=0.01 на health (не йдемо в мінус)
 *   2. Золото накопичується від свіжого значення, а не від снімка $character
 *   3. health/tired/attribute deltas — одним записом, gold_delta=0 не пишеться
 *   4. Порожній result — жодного запису
 *
 * @internal
 */
final class IntentApplierTest extends CIUnitTestCase
{
    /**
     * @param array<string, float> $fresh «свіжі» значення статів у БД
     * @param list<array{id: int, fields: list<string>, data: array<string, float>}> $captured
     */
    private function makeApplier(array $fresh, array &$captured): IntentApplier
    {
        $stats = new class($fresh, $captured) extends CharacterStatsService {
            /** @var array<string, float> */
            private array $fresh;
            /** @var list<array{id: int, fields: list<string>, data: array<string, float>}> */
            public array $captured;

            /**
             * @param array<string, float> $fresh
             * @param list<array{id: int, fields: list<string>, data: array<string, float>}> $captured
             */
            public function __construct(array $fresh, array &$captured)
            {
                $this->fresh    = $fresh;
                $this->captured = &$captured;
            }

            public function mutate(int $characterId, array $fields, callable $mutator): ?array
            {
                $stats = [];
                foreach ($fields as $f) {
                    $stats[$f] = $this->fresh[$f] ?? 0.0;
                }
                $write = [];
                foreach ($mutator($stats) as $f => $v) {
                    $write[$f] = (float) $v;
                }
                $this->captured[] = ['id' => $characterId, 'fields' => $fields, 'data' => $write];

                return ['before' => $stats, 'after' => $write];
            }
        };

        $charModel = new class extends \App\Models\CharacterModel {
            public function update($id = null, $data = null): bool
            {
                throw new \LogicException('IntentApplier не має писати статы через CharacterModel::update()');
            }
            public function find($id = null) { return null; }
        };

        return new IntentApplier(
            $charModel,
            new \App\Models\CharacterResourceModel(),
            new \App\Models\CharacterTaskModel(),
            new \App\Models\ResourceModel(),
            new \App\Models\TaskModel(),
            $stats,
        );
    }

    public function testHealthDeltaCallsUpdateWithFloorOf001(): void
    {
        $captured = [];
        $applier  = $this->makeApplier(['health' => 10.0, 'tired' => 100.0, 'gold' => 0.0], $captured);

        // Гравець з health=10, отримує -50 → floor 0.01
        $character = ['id' => 99, 'health' => 10.0, 'tired' => 100.0, 'gold' => 0];
        $result    = ['health_delta' => -50.0, 'tired_delta' => 0, 'gold_delta' => 0];

        $applier->apply($character, $result);

        $this->assertNotEmpty($captured, 'adjust() мало бути викликано');
        $this->assertSame(99, $captured[0]['id']);
        $this->assertSame(0.01, $captured[0]['data']['health'], 'health не йде нижче 0.01');
    }

    public function testGoldDeltaAccumulates(): void
    {
        $captured = [];
        // Снімок застарів: у БД уже 700 (інша подія встигла додати 200) — дельта лягає на свіже.
        $applier = $this->makeApplier(['health' => 100.0, 'tired' => 100.0, 'gold' => 700.0], $captured);

        $applier->apply(
            ['id' => 1, 'health' => 100, 'tired' => 100, 'gold' => 500],
            ['health_delta' => 0, 'tired_delta' => 0, 'gold_delta' => 1500]
        );

        $this->assertSame(['gold'], $captured[0]['fields']);
        $this->assertSame(2200.0, $captured[0]['data']['gold']);
    }

    public function testAttributeDeltasCombined(): void
    {
        $captured = [];
        $applier  = $this->makeApplier(
            ['health' => 80.0, 'tired' => 80.0, 'gold' => 0.0, 'agility' => 10.5, 'experience' => 50.0],
            $captured
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

        $this->assertCount(1, $captured, 'усі дельти — одним записом');
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
        $applier  = $this->makeApplier(['health' => 100.0, 'tired' => 100.0, 'gold' => 0.0], $captured);

        $applier->apply(
            ['id' => 1, 'health' => 100, 'tired' => 100, 'gold' => 0],
            ['health_delta' => 0, 'tired_delta' => 0, 'gold_delta' => 0]
        );

        $this->assertEmpty($captured, 'Empty deltas → жодного DB-запису');
    }
}
