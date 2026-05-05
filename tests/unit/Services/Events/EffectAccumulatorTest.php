<?php

namespace Tests\Unit\Services\Events;

use App\Models\ActiveEventModel;
use App\Services\Events\EffectAccumulator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.4 — тести на EffectAccumulator через анонімну mock-модель.
 *
 * Перевіряємо:
 *   - Перший accumulate — створює entry в effect_log
 *   - Другий accumulate того ж char — додає deltas (не перезаписує)
 *   - applied=false → no-op
 *   - readForEvent повертає int-keyed array
 *   - Attribute deltas мерджаться (поелементно)
 *   - Resource grants накопичуються (append)
 *   - notified_users tracking (markUserNotified / isUserNotified)
 *
 * @internal
 */
final class EffectAccumulatorTest extends CIUnitTestCase
{
    /**
     * In-memory mock ActiveEventModel — зберігає одну row у $this->row.
     */
    private function makeMockModel(): ActiveEventModel
    {
        return new class extends ActiveEventModel {
            public ?array $row = ['id' => 100, 'effect_log' => null, 'notified_users' => null];

            public function find($id = null) {
                if ($id === null || (int)$id === (int)($this->row['id'] ?? 0)) {
                    return $this->row;
                }
                return null;
            }
            public function update($id = null, $data = null): bool {
                if ($id === null || (int)$id === (int)($this->row['id'] ?? 0)) {
                    $this->row = array_merge($this->row ?? [], (array)$data);
                }
                return true;
            }
        };
    }

    public function testAccumulateFirstResultCreatesEntry(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->accumulate(100, 486, [
            'applied'      => true,
            'health_delta' => -10,
            'tired_delta'  => 0,
            'gold_delta'   => 0,
            'log_summary'  => '-10 HP',
        ]);

        $log = $acc->readForEvent(100);
        $this->assertArrayHasKey(486, $log);
        $this->assertEquals(-10, $log[486]['health_delta']);  // assertEquals: int/float-tolerant (JSON round-trip)
        $this->assertSame(1, $log[486]['applied_count']);
    }

    public function testAccumulateSameCharacterTwiceAddsDeltas(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->accumulate(100, 486, ['applied' => true, 'health_delta' => -5, 'tired_delta' => -2, 'gold_delta' => 0]);
        $acc->accumulate(100, 486, ['applied' => true, 'health_delta' => -8, 'tired_delta' => -3, 'gold_delta' => 0]);

        $log = $acc->readForEvent(100);
        $this->assertEquals(-13, $log[486]['health_delta']);
        $this->assertEquals(-5,  $log[486]['tired_delta']);
        $this->assertSame(2,     $log[486]['applied_count']);
    }

    public function testNotAppliedIsNoOp(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->accumulate(100, 486, ['applied' => false, 'health_delta' => -100]);

        $this->assertEmpty($acc->readForEvent(100), 'applied=false → log не змінюється');
    }

    public function testGoldAccumulates(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->accumulate(100, 486, ['applied' => true, 'gold_delta' => 1500]);
        $acc->accumulate(100, 486, ['applied' => true, 'gold_delta' => 2500]);

        $this->assertSame(4000, $acc->readForEvent(100)[486]['gold_delta']);
    }

    public function testAttributeDeltasMergeElementwise(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->accumulate(100, 486, [
            'applied'          => true,
            'attribute_deltas' => ['agility' => 0.05, 'experience' => 1.10],
        ]);
        $acc->accumulate(100, 486, [
            'applied'          => true,
            'attribute_deltas' => ['agility' => 0.07, 'strength' => 0.5],
        ]);

        $deltas = $acc->readForEvent(100)[486]['attribute_deltas'];
        $this->assertEqualsWithDelta(0.12, $deltas['agility'], 0.0001);
        $this->assertEqualsWithDelta(1.10, $deltas['experience'], 0.0001);
        $this->assertEqualsWithDelta(0.5,  $deltas['strength'], 0.0001);
    }

    public function testResourceGrantsAreAppended(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->accumulate(100, 486, [
            'applied'                => true,
            'resource_grant_intents' => [['keyword' => 'berry', 'amount' => 3]],
        ]);
        $acc->accumulate(100, 486, [
            'applied'                => true,
            'resource_grant_intents' => [['keyword' => 'berry', 'amount' => 2]],
        ]);

        $grants = $acc->readForEvent(100)[486]['resource_grants'];
        $this->assertCount(2, $grants);
        $this->assertSame('berry', $grants[0]['keyword']);
        $this->assertSame(3, $grants[0]['amount']);
        $this->assertSame(2, $grants[1]['amount']);
    }

    public function testReadForEventReturnsIntKeys(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->accumulate(100, 486, ['applied' => true, 'health_delta' => -1]);
        $acc->accumulate(100, 489, ['applied' => true, 'health_delta' => -2]);

        $log = $acc->readForEvent(100);
        $this->assertArrayHasKey(486, $log);  // int, не string '486'
        $this->assertArrayHasKey(489, $log);
        $this->assertIsInt(array_keys($log)[0]);
    }

    public function testReadForEventOnEmptyReturnsEmptyArray(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $this->assertSame([], $acc->readForEvent(100));
    }

    // ============================================================
    // notified_users tracking
    // ============================================================

    public function testMarkUserNotifiedAndCheck(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $this->assertFalse($acc->isUserNotified(100, 486));
        $acc->markUserNotified(100, 486);
        $this->assertTrue($acc->isUserNotified(100, 486));
    }

    public function testMarkUserNotifiedIdempotent(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->markUserNotified(100, 486);
        $acc->markUserNotified(100, 486);  // повторний виклик
        $acc->markUserNotified(100, 486);

        $list = json_decode($model->row['notified_users'], true);
        $this->assertCount(1, $list, 'не дублюємо запис');
    }

    public function testMarkUserNotifiedDistinguishesUsers(): void
    {
        $model = $this->makeMockModel();
        $acc   = new EffectAccumulator($model);

        $acc->markUserNotified(100, 486);
        $acc->markUserNotified(100, 489);

        $this->assertTrue($acc->isUserNotified(100, 486));
        $this->assertTrue($acc->isUserNotified(100, 489));
        $this->assertFalse($acc->isUserNotified(100, 999));
    }
}
