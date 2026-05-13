<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Handlers;

use App\Attributes\HandlerKey;
use App\Services\Handlers\HandlerDiscoveryScanner;
use App\Services\Handlers\HandlerEntry;
use App\Services\Handlers\HandlerRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Handlers\Fixtures\AlphaHandler;
use Tests\Support\Handlers\Fixtures\BetaHandler;
use Tests\Support\Handlers\Fixtures\GammaTaskHandler;
use Tests\Support\Handlers\Fixtures\SampleEffectInterface;
use Tests\Support\Handlers\Fixtures\SampleTaskInterface;

/**
 * Phase B1 (ADR-023) — unit-тесты на HandlerRegistry.
 *
 * Используем реальный scanner + fixture classmap (E2E lookup).
 * Cache отключён в большинстве тестов (`cacheEnabled: false`), отдельный
 * test проверяет write/read flow.
 *
 * @internal
 */
final class HandlerRegistryTest extends CIUnitTestCase
{
    private string $fixtureClassmapPath;
    private string $tempCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureClassmapPath = __DIR__ . '/../../../_support/Handlers/test-classmap.php';
        $this->tempCacheDir        = sys_get_temp_dir() . '/wildworld-test-' . uniqid();
        @mkdir($this->tempCacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempCacheDir)) {
            foreach (glob($this->tempCacheDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempCacheDir);
        }
        parent::tearDown();
    }

    private function makeRegistry(bool $cacheEnabled = false): HandlerRegistry
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        return new HandlerRegistry(
            namespacePrefixes: ['Tests\\Support\\Handlers\\Fixtures\\'],
            scanner:           $scanner,
            cachePath:         $this->tempCacheDir . '/handler-registry.json',
            cacheEnabled:      $cacheEnabled,
        );
    }

    public function testGetByKeyReturnsRegisteredHandler(): void
    {
        $registry = $this->makeRegistry();
        $entry    = $registry->getByKey('alpha');

        $this->assertInstanceOf(HandlerEntry::class, $entry);
        $this->assertSame(AlphaHandler::class, $entry->class);
        $this->assertSame('Альфа-обработчик', $entry->metadata->displayName);
    }

    public function testGetByKeyReturnsNullForUnknown(): void
    {
        $registry = $this->makeRegistry();
        $this->assertNull($registry->getByKey('nonexistent-key'));
    }

    public function testAllReturnsAllRegisteredHandlers(): void
    {
        $registry = $this->makeRegistry();
        $all      = $registry->all();

        $this->assertCount(3, $all, 'alpha + beta + gamma');

        $keys = array_map(static fn (HandlerEntry $e): string => $e->getKey(), $all);
        sort($keys);
        $this->assertSame(['alpha', 'beta', 'gamma'], $keys);
    }

    public function testGetByInterfaceFiltersCorrectly(): void
    {
        $registry = $this->makeRegistry();

        $effectHandlers = $registry->getByInterface(SampleEffectInterface::class);
        $taskHandlers   = $registry->getByInterface(SampleTaskInterface::class);

        $effectKeys = array_map(static fn (HandlerEntry $e): string => $e->getKey(), $effectHandlers);
        sort($effectKeys);
        $this->assertSame(['alpha', 'beta'], $effectKeys);

        $taskKeys = array_map(static fn (HandlerEntry $e): string => $e->getKey(), $taskHandlers);
        $this->assertSame(['gamma'], $taskKeys);
    }

    public function testGetByInterfaceReturnsEmptyForUnknownInterface(): void
    {
        $registry = $this->makeRegistry();
        $this->assertSame([], $registry->getByInterface('\\NonExistent\\Interface'));
    }

    public function testOptionsForReturnsCompactStructure(): void
    {
        $registry = $this->makeRegistry();
        $options  = $registry->optionsFor(SampleEffectInterface::class);

        $this->assertCount(2, $options);

        foreach ($options as $opt) {
            $this->assertArrayHasKey('key', $opt);
            $this->assertArrayHasKey('displayName', $opt);
            $this->assertArrayHasKey('description', $opt);
            $this->assertCount(3, $opt, 'Только 3 ключа (для admin-dropdown), без class/inputSchema');
        }
    }

    public function testCacheRoundTrip(): void
    {
        $cachePath = $this->tempCacheDir . '/handler-registry.json';

        // 1) Первый registry — cache enabled, scan'ит fixture'ы и пишет JSON.
        $registry1 = new HandlerRegistry(
            namespacePrefixes: ['Tests\\Support\\Handlers\\Fixtures\\'],
            scanner:           new HandlerDiscoveryScanner($this->fixtureClassmapPath),
            cachePath:         $cachePath,
            cacheEnabled:      true,
        );
        $all1 = $registry1->all();
        $this->assertCount(3, $all1);
        $this->assertFileExists($cachePath, 'Cache file должен быть создан');

        // 2) Второй registry — тот же cachePath. Из-за mtime compute (см. ниже)
        //    наш fixture-классмап лежит вне App\, поэтому maxMtime=0 → cache
        //    всегда «свежий» → этот вызов прочитает из кэша, не делая нового scan'а.
        $registry2 = new HandlerRegistry(
            namespacePrefixes: ['Tests\\Support\\Handlers\\Fixtures\\'],
            scanner:           new HandlerDiscoveryScanner('/tmp/nonexistent-' . uniqid() . '.php'),
            cachePath:         $cachePath,
            cacheEnabled:      true,
        );
        $all2 = $registry2->all();
        $this->assertCount(3, $all2, 'Реестр должен подняться из cache (scanner с битым classmap, но cache работает)');

        // hydrated keys равны исходным
        $keys2 = array_map(static fn (HandlerEntry $e): string => $e->getKey(), $all2);
        sort($keys2);
        $this->assertSame(['alpha', 'beta', 'gamma'], $keys2);
    }

    public function testCacheInvalidatedWhenNamespacesChange(): void
    {
        $cachePath = $this->tempCacheDir . '/handler-registry.json';

        // 1) Записываем кэш для одних namespace'ов.
        $reg1 = new HandlerRegistry(
            namespacePrefixes: ['Tests\\Support\\Handlers\\Fixtures\\'],
            scanner:           new HandlerDiscoveryScanner($this->fixtureClassmapPath),
            cachePath:         $cachePath,
            cacheEnabled:      true,
        );
        $reg1->all();
        $this->assertFileExists($cachePath);

        // 2) Реестр с другими namespace'ами — должен не использовать старый кэш.
        $reg2 = new HandlerRegistry(
            namespacePrefixes: ['App\\NonExistent\\'],
            scanner:           new HandlerDiscoveryScanner($this->fixtureClassmapPath),
            cachePath:         $cachePath,
            cacheEnabled:      true,
        );
        $this->assertSame([], $reg2->all());
    }

    public function testRefreshTriggersFreshScan(): void
    {
        $registry = $this->makeRegistry();
        $this->assertCount(3, $registry->all());

        $registry->refresh();
        $this->assertCount(3, $registry->all(), 'Refresh без code-change даёт тот же результат');
    }

    public function testEntryAdminOptionStructure(): void
    {
        $entry = new HandlerEntry(
            class:      AlphaHandler::class,
            metadata:   new HandlerKey(key: 'a', displayName: 'A', description: 'd'),
            interfaces: [],
        );

        $opt = $entry->toAdminOption();
        $this->assertSame(['key' => 'a', 'displayName' => 'A', 'description' => 'd'], $opt);
    }

    public function testEntryGetKeyDelegatesToMetadata(): void
    {
        $entry = new HandlerEntry(
            class:      BetaHandler::class,
            metadata:   new HandlerKey(key: 'beta', displayName: 'B'),
            interfaces: [SampleEffectInterface::class],
        );

        $this->assertSame('beta', $entry->getKey());
    }
}
