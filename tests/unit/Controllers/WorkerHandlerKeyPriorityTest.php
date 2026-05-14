<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Worker;
use App\TaskHandlers\GatherTaskHandler;
use App\TaskHandlers\MarchingTaskHandler;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Phase B6 (ADR-023, 2026-05-14) — DB-level handler_key priority в Worker.
 *
 * Перевіряє нову поведінку:
 *  1. Якщо передано explicit handler_key — Worker резолвить через registry прямо.
 *  2. Якщо explicit handler_key NULL/empty — Worker йде через name→key map (B3 шлях).
 *  3. Якщо explicit handler_key invalid → fallback на name→key map.
 *  4. Якщо explicit handler_key неправильного інтерфейсу (наприклад, effect-key
 *     для task-handler context) → fallback.
 *
 * @internal
 */
final class WorkerHandlerKeyPriorityTest extends CIUnitTestCase
{
    /** @return list<string> */
    private function invokeWith(string $taskName, ?string $explicitKey): array
    {
        $worker = new Worker();
        $refl   = new ReflectionClass($worker);
        $method = $refl->getMethod('getHandlerClassName');
        $method->setAccessible(true);

        $resolved = $method->invoke($worker, $taskName, $explicitKey);

        return [is_string($resolved) ? $resolved : ''];
    }

    public function testExplicitHandlerKeyOverridesNameMap(): void
    {
        // task.name='UnknownTask' (нема у $taskHandlerKeyMap), але explicit
        // handler_key='gather' — Worker має взяти GatherTaskHandler через registry.
        [$resolved] = $this->invokeWith('UnknownTask', 'gather');
        $this->assertSame(GatherTaskHandler::class, $resolved);
    }

    public function testExplicitHandlerKeyTrumpsNameMapWhenBothValid(): void
    {
        // task.name='Marching' зазвичай → marching → MarchingTaskHandler.
        // Але explicit handler_key='gather' має взяти верх.
        [$resolved] = $this->invokeWith('Marching', 'gather');
        $this->assertSame(
            GatherTaskHandler::class,
            $resolved,
            'Explicit handler_key має trump-нути name→key map (B6 priority)',
        );
    }

    public function testEmptyExplicitHandlerKeyFallsBackToNameMap(): void
    {
        // explicit handler_key = '' (порожній) → НЕ override → name→key map.
        [$resolved] = $this->invokeWith('Marching', '');
        $this->assertSame(MarchingTaskHandler::class, $resolved);
    }

    public function testNullExplicitHandlerKeyFallsBackToNameMap(): void
    {
        // explicit handler_key = null → name→key map.
        [$resolved] = $this->invokeWith('Marching', null);
        $this->assertSame(MarchingTaskHandler::class, $resolved);
    }

    public function testInvalidExplicitHandlerKeyFallsBackToNameMap(): void
    {
        // explicit handler_key='totally_made_up' (не у registry) → fallback на name→key.
        [$resolved] = $this->invokeWith('Marching', 'totally_made_up_key');
        $this->assertSame(MarchingTaskHandler::class, $resolved);
    }

    public function testExplicitHandlerKeyForWrongInterfaceFallsBackToNameMap(): void
    {
        // explicit handler_key='damage_health' — це effect-handler (EventEffectInterface),
        // НЕ TaskHandlerInterface. Worker має проігнорувати і fallback на name→key.
        [$resolved] = $this->invokeWith('Marching', 'damage_health');
        $this->assertSame(MarchingTaskHandler::class, $resolved);
    }
}
