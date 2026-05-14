<?php

declare(strict_types=1);

namespace Tests\Unit\Services\World;

use App\Services\Handlers\HandlerRegistry;
use App\Services\World\ObjectDiscoveryService;
use App\TaskHandlers\Objects\AbandonedTruckHandler;
use App\TaskHandlers\Objects\BaseObjectHandler;
use App\TaskHandlers\Objects\ClosedWarehouseHandler;
use App\TaskHandlers\Objects\ObjectHandlerInterface;
use App\TaskHandlers\Objects\StrategicLootHandler;
use App\TaskHandlers\Objects\ToolkitHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use ReflectionClass;

/**
 * Phase B4 (ADR-023, 2026-05-14) — anti-drift guard для пары
 * `ObjectDiscoveryService::$objectHandlerKeyMap` ↔ legacy `match`-branches
 * в `getHandler()` ↔ `HandlerRegistry` (`#[HandlerKey]` attributes на
 * world-object handler-классах).
 *
 * Проверки:
 *  1. Для каждого `world_objects.name_en` ключа в `$objectHandlerKeyMap`
 *     registry находит entry — handler-key зарегистрирован через `#[HandlerKey]`.
 *  2. Entry->class implements `ObjectHandlerInterface` (надёжный dispatch).
 *  3. Класс из registry === класс из legacy match'а в `getHandler()` —
 *     нет рассинхрона.
 *  4. Каждый concrete world-object handler-класс в `app/TaskHandlers/Objects/`
 *     (implements `ObjectHandlerInterface`, не abstract, не интерфейс) имеет
 *     `#[HandlerKey]` атрибут. Защита от «забыл атрибут на новом handler».
 *  5. Sample resolution через `ObjectDiscoveryService::getHandler()` —
 *     возвращает правильный handler для каждого known type.
 *
 * @internal
 */
final class ObjectDiscoveryRegistryConsistencyTest extends CIUnitTestCase
{
    public function testRegistryServiceIsBound(): void
    {
        $registry = Services::handlerRegistry();
        $this->assertInstanceOf(HandlerRegistry::class, $registry);
    }

    public function testEveryObjectHandlerKeyMapEntryResolvesViaRegistry(): void
    {
        $registry = Services::handlerRegistry();
        $keyMap   = $this->objectHandlerKeyMap();

        $missing = [];
        foreach ($keyMap as $nameEn => $handlerKey) {
            if ($registry->getByKey($handlerKey) === null) {
                $missing[] = "{$nameEn} → {$handlerKey}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            'world_objects.name_en значения с handler-key не зарегистрированными в HandlerRegistry: '
                . implode(', ', $missing)
                . ' — добавь #[HandlerKey] на соответствующий handler-класс (ADR-023 B4).',
        );
    }

    public function testEveryRegistryEntryImplementsObjectHandlerInterface(): void
    {
        $registry = Services::handlerRegistry();

        foreach ($this->objectHandlerKeyMap() as $nameEn => $handlerKey) {
            $entry = $registry->getByKey($handlerKey);
            $this->assertNotNull($entry, "Registry entry for '{$handlerKey}' (name_en '{$nameEn}') missing");
            $this->assertContains(
                ObjectHandlerInterface::class,
                $entry->interfaces,
                "Класс {$entry->class} для key='{$handlerKey}' должен implements ObjectHandlerInterface",
            );
        }
    }

    public function testRegistryClassesMatchLegacyMatchBranches(): void
    {
        $registry = Services::handlerRegistry();

        // Зеркало legacy match-branches из ObjectDiscoveryService::getHandler().
        // Должно обновляться синхронно с этим методом.
        $legacyMap = [
            'Abandoned truck'  => AbandonedTruckHandler::class,
            'Toolkit'          => ToolkitHandler::class,
            'Closed warehouse' => ClosedWarehouseHandler::class,
            'Bunker'           => StrategicLootHandler::class,
            'Technopark'       => StrategicLootHandler::class,
            'GhostCity'        => StrategicLootHandler::class,
        ];
        $keyMap = $this->objectHandlerKeyMap();

        $this->assertSame(
            array_keys($legacyMap),
            array_keys($keyMap),
            'Drift: legacy match-branches и $objectHandlerKeyMap покрывают разные name_en значения.',
        );

        foreach ($legacyMap as $nameEn => $expectedClass) {
            $handlerKey = $keyMap[$nameEn];
            $entry      = $registry->getByKey($handlerKey);
            $this->assertNotNull($entry, "Registry миссит '{$handlerKey}' для type '{$nameEn}'");
            $this->assertSame(
                $expectedClass,
                $entry->class,
                "Drift: registry для '{$handlerKey}' = {$entry->class}, "
                    . "legacy match для name_en='{$nameEn}' = {$expectedClass}.",
            );
        }
    }

    public function testGetHandlerResolvesKnownTypes(): void
    {
        $service = new ObjectDiscoveryService(null, null);
        $refl    = new ReflectionClass($service);
        $method  = $refl->getMethod('getHandler');
        $method->setAccessible(true);

        $samples = [
            'Abandoned truck'  => AbandonedTruckHandler::class,
            'Toolkit'          => ToolkitHandler::class,
            'Closed warehouse' => ClosedWarehouseHandler::class,
            'Bunker'           => StrategicLootHandler::class,
            'Technopark'       => StrategicLootHandler::class,
            'GhostCity'        => StrategicLootHandler::class,
        ];

        foreach ($samples as $nameEn => $expectedClass) {
            $handler = $method->invoke($service, $nameEn);
            $this->assertInstanceOf(
                $expectedClass,
                $handler,
                "ObjectDiscoveryService::getHandler('{$nameEn}') должен вернуть {$expectedClass}",
            );
        }

        // Unknown type → null.
        $this->assertNull($method->invoke($service, 'TotallyMadeUpObject'));
    }

    public function testEveryConcreteObjectHandlerClassHasAttribute(): void
    {
        $appPath = rtrim(APPPATH, '/\\');
        $rootDir = $appPath . DIRECTORY_SEPARATOR . 'TaskHandlers' . DIRECTORY_SEPARATOR . 'Objects';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS),
        );

        $missing = [];
        foreach ($iterator as $file) {
            if (!($file instanceof \SplFileInfo) || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relPath = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
            $fqcn    = 'App\\' . str_replace('/', '\\', substr($relPath, 0, -4));

            if (!class_exists($fqcn)) {
                continue;  // file is interface/trait/non-class — skip.
            }

            $refl = new ReflectionClass($fqcn);
            if ($refl->isAbstract() || $refl->isInterface() || $refl->isTrait()) {
                continue;
            }
            if (!$refl->implementsInterface(ObjectHandlerInterface::class)) {
                continue;
            }

            $attrs = $refl->getAttributes(\App\Attributes\HandlerKey::class);
            if ($attrs === []) {
                $missing[] = $fqcn;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Concrete world-object handler классы без #[HandlerKey] атрибута: '
                . "\n  · " . implode("\n  · ", $missing)
                . "\nДобавь #[HandlerKey(key:..., displayName:..., description:...)] над "
                . '`class ... implements ObjectHandlerInterface` (ADR-023 B4).',
        );
    }

    /**
     * @return array<string, string>
     */
    private function objectHandlerKeyMap(): array
    {
        $service = new ObjectDiscoveryService(null, null);
        $refl    = new ReflectionClass($service);
        $prop    = $refl->getProperty('objectHandlerKeyMap');
        $prop->setAccessible(true);
        $value = $prop->getValue($service);
        $this->assertIsArray($value);
        /** @var array<string, string> $value */
        return $value;
    }
}
