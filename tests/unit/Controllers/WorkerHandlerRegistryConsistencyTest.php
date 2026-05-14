<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Worker;
use App\Services\Handlers\HandlerRegistry;
use App\TaskHandlers\BaseTaskHandler;
use App\TaskHandlers\Contracts\TaskHandlerInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use ReflectionClass;

/**
 * Phase B3 (ADR-023, 2026-05-14) — anti-drift guard для пары
 * `Worker::$taskHandlerKeyMap` ↔ `Worker::$taskHandlerMap` (legacy FQCN)
 * ↔ `HandlerRegistry` (`#[HandlerKey]` attributes на task-handler классах).
 *
 * Проверки:
 *  1. Для каждого `tasks.name` ключа в `$taskHandlerKeyMap` registry находит
 *     entry — handler-key зарегистрирован через `#[HandlerKey]`.
 *  2. Entry->class implements `TaskHandlerInterface` (надёжный dispatch).
 *  3. Класс из registry === класс из legacy `$taskHandlerMap` — нет рассинхрона
 *     между двумя map'ами.
 *  4. Каждый concrete `*Handler` класс в `app/TaskHandlers/` (extends
 *     `BaseTaskHandler`, не abstract, не интерфейс, не в `Objects/` — то B4)
 *     имеет `#[HandlerKey]` атрибут. Защита от «забыл атрибут на новом handler».
 *
 * Тест защищает от ситуации: разработчик добавил task.name в Worker map'ы,
 * но забыл `#[HandlerKey]` на классе → silent fallback на legacy FQCN, registry
 * path не работает, и admin-form (B5) не увидит handler в dropdown'е.
 *
 * @internal
 */
final class WorkerHandlerRegistryConsistencyTest extends CIUnitTestCase
{
    public function testRegistryServiceIsBound(): void
    {
        $registry = Services::handlerRegistry();
        $this->assertInstanceOf(HandlerRegistry::class, $registry);
    }

    public function testEveryTaskHandlerKeyMapEntryResolvesViaRegistry(): void
    {
        $registry = Services::handlerRegistry();
        $keyMap   = $this->taskHandlerKeyMap();

        $missing = [];
        foreach ($keyMap as $taskName => $handlerKey) {
            if ($registry->getByKey($handlerKey) === null) {
                $missing[] = "{$taskName} → {$handlerKey}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            'task.name значения с handler-key не зарегистрированными в HandlerRegistry: '
                . implode(', ', $missing)
                . ' — добавь #[HandlerKey] на соответствующий handler-класс (ADR-023 B3).',
        );
    }

    public function testEveryRegistryEntryImplementsTaskHandlerInterface(): void
    {
        $registry = Services::handlerRegistry();

        foreach ($this->taskHandlerKeyMap() as $taskName => $handlerKey) {
            $entry = $registry->getByKey($handlerKey);
            $this->assertNotNull($entry, "Registry entry for '{$handlerKey}' (task '{$taskName}') missing");
            $this->assertContains(
                TaskHandlerInterface::class,
                $entry->interfaces,
                "Класс {$entry->class} для key='{$handlerKey}' должен implements TaskHandlerInterface "
                    . '(обычно через `extends BaseTaskHandler`)',
            );
        }
    }

    public function testRegistryClassesMatchLegacyFqcnMap(): void
    {
        $registry  = Services::handlerRegistry();
        $worker    = new Worker();
        $keyMap    = $this->taskHandlerKeyMap();
        $legacyMap = $this->extractProperty($worker, 'taskHandlerMap');

        foreach ($keyMap as $taskName => $handlerKey) {
            $this->assertArrayHasKey(
                $taskName,
                $legacyMap,
                "Drift: '{$taskName}' is in taskHandlerKeyMap но не в legacy taskHandlerMap — "
                    . 'legacy fallback не сработает если registry отвалится.',
            );

            $entry         = $registry->getByKey($handlerKey);
            $expectedClass = 'App\\TaskHandlers\\' . $legacyMap[$taskName];
            $this->assertNotNull($entry, "Registry миссит '{$handlerKey}' для task '{$taskName}'");
            $this->assertSame(
                $expectedClass,
                $entry->class,
                "Drift: registry для '{$handlerKey}' = {$entry->class}, "
                    . "legacy taskHandlerMap для '{$taskName}' = {$expectedClass}. "
                    . 'Один из map\'ов надо обновить.',
            );
        }
    }

    public function testWorkerResolvesViaRegistryForKnownTasks(): void
    {
        $worker = new Worker();
        $refl   = new ReflectionClass($worker);
        $method = $refl->getMethod('getHandlerClassName');
        $method->setAccessible(true);

        $samples = [
            'Marching'        => \App\TaskHandlers\MarchingTaskHandler::class,
            'Gather'          => \App\TaskHandlers\GatherTaskHandler::class,
            'craftBandage'    => \App\TaskHandlers\Craft\GenericCraftCompletionHandler::class,
            'buildWorkshop'   => \App\TaskHandlers\Built\GenericBuildingCompletionHandler::class,
            'BaseRelocation'  => \App\TaskHandlers\Built\BaseRelocationCompletionHandler::class,
            'craftArmorRaggedShirt' => \App\TaskHandlers\Craft\WorkbenchStandard\Armor\CraftCompletionRaggedShirtHandler::class,
        ];

        foreach ($samples as $taskName => $expectedClass) {
            $resolved = $method->invoke($worker, $taskName);
            $this->assertSame(
                $expectedClass,
                $resolved,
                "Worker::getHandlerClassName('{$taskName}') должен вернуть {$expectedClass}",
            );
        }
    }

    public function testEveryConcreteTaskHandlerClassHasAttribute(): void
    {
        $appPath  = rtrim(APPPATH, '/\\');
        $rootDir  = $appPath . DIRECTORY_SEPARATOR . 'TaskHandlers';
        $excluded = [
            // B4 scope — отдельный ADR/phase для world-object handler'ов.
            'App\\TaskHandlers\\Objects\\',
            // Contracts — это интерфейсы, не handler'ы.
            'App\\TaskHandlers\\Contracts\\',
        ];

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

            $skip = false;
            foreach ($excluded as $prefix) {
                if (str_starts_with($fqcn, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            if (!class_exists($fqcn)) {
                continue;  // file is interface/trait/non-class — skip.
            }

            $refl = new ReflectionClass($fqcn);
            if ($refl->isAbstract() || $refl->isInterface() || $refl->isTrait()) {
                continue;
            }
            // Только handler'ы — те что extends BaseTaskHandler (=
            // implement TaskHandlerInterface через base).
            if (!$refl->isSubclassOf(BaseTaskHandler::class)) {
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
            'Concrete task-handler классы без #[HandlerKey] атрибута: '
                . "\n  · " . implode("\n  · ", $missing)
                . "\nДобавь #[HandlerKey(key:..., displayName:..., description:...)] над `class ... extends BaseTaskHandler` "
                . '(ADR-023 B3). Если handler намеренно неактивен — расширь $excluded в этом тесте.',
        );
    }

    /**
     * @return array<string, string>
     */
    private function taskHandlerKeyMap(): array
    {
        return $this->extractProperty(new Worker(), 'taskHandlerKeyMap');
    }

    /**
     * @return array<string, string>
     */
    private function extractProperty(Worker $worker, string $name): array
    {
        $refl = new ReflectionClass($worker);
        $prop = $refl->getProperty($name);
        $prop->setAccessible(true);
        $value = $prop->getValue($worker);
        $this->assertIsArray($value, "Worker::\${$name} must be array");
        /** @var array<string, string> $value */
        return $value;
    }
}
