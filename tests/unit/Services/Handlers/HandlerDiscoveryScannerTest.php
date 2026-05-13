<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Handlers;

use App\Services\Handlers\HandlerDiscoveryScanner;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Handlers\Fixtures\AlphaHandler;
use Tests\Support\Handlers\Fixtures\BetaHandler;
use Tests\Support\Handlers\Fixtures\GammaTaskHandler;
use Tests\Support\Handlers\Fixtures\SampleEffectInterface;

/**
 * Phase B1 (ADR-023) — unit-тесты auto-discovery scanner'а.
 *
 * Используем fixture-классы из `tests/_support/Handlers/Fixtures/`
 * и кастомный classmap-файл (избегаем зависимости от full vendor classmap).
 *
 * @internal
 */
final class HandlerDiscoveryScannerTest extends CIUnitTestCase
{
    private string $fixtureClassmapPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureClassmapPath = __DIR__ . '/../../../_support/Handlers/test-classmap.php';
        $this->assertFileExists($this->fixtureClassmapPath, 'Fixture classmap must exist');
    }

    public function testDiscoversHandlersWithAttribute(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        $entries = $scanner->discover(['Tests\\Support\\Handlers\\Fixtures\\']);

        $keys = array_map(static fn ($e): string => $e->getKey(), $entries);
        sort($keys);

        // alpha, beta, gamma имеют атрибут; abstract — abstract; untagged/interface — без атрибута.
        $this->assertSame(['alpha', 'beta', 'gamma'], $keys);
    }

    public function testSkipsClassesWithoutAttribute(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        $entries = $scanner->discover(['Tests\\Support\\Handlers\\Fixtures\\']);

        foreach ($entries as $entry) {
            $this->assertNotSame(
                'Tests\\Support\\Handlers\\Fixtures\\UntaggedHandler',
                $entry->class,
                'UntaggedHandler must not be in registry',
            );
        }
    }

    public function testSkipsAbstractClasses(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        $entries = $scanner->discover(['Tests\\Support\\Handlers\\Fixtures\\']);

        foreach ($entries as $entry) {
            $this->assertNotSame(
                'Tests\\Support\\Handlers\\Fixtures\\AbstractParent',
                $entry->class,
                'AbstractParent (abstract class) must not be in registry',
            );
        }
    }

    public function testSkipsInterfaces(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        $entries = $scanner->discover(['Tests\\Support\\Handlers\\Fixtures\\']);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('Interface', $entry->class);
        }
    }

    public function testCapturesInterfacesOfHandler(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        $entries = $scanner->discover(['Tests\\Support\\Handlers\\Fixtures\\']);

        $alpha = $this->findByClass($entries, AlphaHandler::class);
        $this->assertNotNull($alpha);
        $this->assertContains(SampleEffectInterface::class, $alpha->interfaces);
    }

    public function testNamespacePrefixFilteringExcludesUnrelated(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        // Префикс, под которым в fixture-classmap'е нет ни одного класса.
        $entries = $scanner->discover(['App\\NonExistentNamespace\\']);
        $this->assertSame([], $entries);
    }

    public function testEmptyPrefixListReturnsEmptyResult(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        $entries = $scanner->discover([]);
        $this->assertSame([], $entries);
    }

    public function testMissingClassmapFileReturnsEmpty(): void
    {
        $scanner = new HandlerDiscoveryScanner('/tmp/definitely-not-existing-' . uniqid() . '.php');
        $entries = $scanner->discover(['Tests\\Support\\Handlers\\Fixtures\\']);
        $this->assertSame([], $entries);
    }

    public function testHandlerKeyMetadataIsPopulated(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        $entries = $scanner->discover(['Tests\\Support\\Handlers\\Fixtures\\']);

        $alpha = $this->findByClass($entries, AlphaHandler::class);
        $this->assertNotNull($alpha);
        $this->assertSame('alpha', $alpha->metadata->key);
        $this->assertSame('Альфа-обработчик', $alpha->metadata->displayName);
        $this->assertStringContainsString('Тестовый', $alpha->metadata->description);
        $this->assertCount(1, $alpha->metadata->inputSchema);
        $this->assertSame('threshold', $alpha->metadata->inputSchema[0]['name']);
    }

    public function testFiltersByMultiplePrefixes(): void
    {
        $scanner = new HandlerDiscoveryScanner($this->fixtureClassmapPath);
        $entries = $scanner->discover([
            'Tests\\Support\\Handlers\\Fixtures\\',
            'App\\NoSuchNamespace\\',
        ]);

        $keys = array_map(static fn ($e): string => $e->getKey(), $entries);
        $this->assertContains('alpha', $keys);
        $this->assertContains('gamma', $keys);
    }

    /**
     * @param list<\App\Services\Handlers\HandlerEntry> $entries
     */
    private function findByClass(array $entries, string $class): ?\App\Services\Handlers\HandlerEntry
    {
        foreach ($entries as $e) {
            if ($e->class === $class) {
                return $e;
            }
        }
        return null;
    }
}
