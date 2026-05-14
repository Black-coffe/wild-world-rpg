<?php

declare(strict_types=1);

namespace Tests\Unit\Services\World;

use App\Services\World\ObjectDiscoveryService;
use App\TaskHandlers\Objects\AbandonedTruckHandler;
use App\TaskHandlers\Objects\ToolkitHandler;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Phase B6 (ADR-023, 2026-05-14) — DB-level handler_key priority в
 * ObjectDiscoveryService::getHandler().
 *
 * @internal
 */
final class ObjectDiscoveryHandlerKeyPriorityTest extends CIUnitTestCase
{
    private function invokeGetHandler(string $type, ?string $explicitKey): ?object
    {
        $service = new ObjectDiscoveryService(null, null);
        $refl    = new ReflectionClass($service);
        $method  = $refl->getMethod('getHandler');
        $method->setAccessible(true);

        $result = $method->invoke($service, $type, $explicitKey);

        return is_object($result) ? $result : null;
    }

    public function testExplicitHandlerKeyOverridesNameMap(): void
    {
        // name_en='UnknownObject' (нема у $objectHandlerKeyMap), explicit
        // handler_key='world_object_toolkit' → ToolkitHandler.
        $handler = $this->invokeGetHandler('UnknownObject', 'world_object_toolkit');
        $this->assertInstanceOf(ToolkitHandler::class, $handler);
    }

    public function testExplicitHandlerKeyTrumpsNameMapWhenBothValid(): void
    {
        // name_en='Abandoned truck' зазвичай → AbandonedTruckHandler.
        // Але explicit handler_key='world_object_toolkit' має взяти верх.
        $handler = $this->invokeGetHandler('Abandoned truck', 'world_object_toolkit');
        $this->assertInstanceOf(
            ToolkitHandler::class,
            $handler,
            'Explicit handler_key має trump-нути name→key map (B6 priority)',
        );
    }

    public function testEmptyExplicitHandlerKeyFallsBackToNameMap(): void
    {
        $handler = $this->invokeGetHandler('Abandoned truck', '');
        $this->assertInstanceOf(AbandonedTruckHandler::class, $handler);
    }

    public function testNullExplicitHandlerKeyFallsBackToNameMap(): void
    {
        $handler = $this->invokeGetHandler('Abandoned truck', null);
        $this->assertInstanceOf(AbandonedTruckHandler::class, $handler);
    }

    public function testInvalidExplicitHandlerKeyFallsBackToNameMap(): void
    {
        $handler = $this->invokeGetHandler('Abandoned truck', 'totally_made_up_key');
        $this->assertInstanceOf(AbandonedTruckHandler::class, $handler);
    }

    public function testExplicitHandlerKeyForWrongInterfaceFallsBackToNameMap(): void
    {
        // explicit handler_key='gather' — це TaskHandlerInterface, не ObjectHandlerInterface.
        $handler = $this->invokeGetHandler('Abandoned truck', 'gather');
        $this->assertInstanceOf(AbandonedTruckHandler::class, $handler);
    }
}
