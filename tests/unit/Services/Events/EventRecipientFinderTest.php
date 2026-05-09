<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\EventRecipientFinder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Twin guard regression — `resolveBiomeIds` повинен повертати [] для global
 * events (biome_ids = NULL / '' / []), а не падати з TypeError як було до
 * fix v0.51.130.
 *
 * Контекст: v0.51.127 виправив той самий клас bug у EventDispatcher,
 * але EventRecipientFinder зберігав дзеркальну assumption "biome_ids
 * завжди NOT NULL" — smoke на MeteorImpact start notification зловив це.
 *
 * @internal
 */
final class EventRecipientFinderTest extends CIUnitTestCase
{
    private EventRecipientFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finder = new EventRecipientFinder();
    }

    public function testResolveBiomeIdsHandlesNull(): void
    {
        $result = $this->finder->resolveBiomeIds(['biome_ids' => null]);
        $this->assertSame([], $result);
    }

    public function testResolveBiomeIdsHandlesEmptyString(): void
    {
        $result = $this->finder->resolveBiomeIds(['biome_ids' => '']);
        $this->assertSame([], $result);
    }

    public function testResolveBiomeIdsHandlesMissingKey(): void
    {
        $result = $this->finder->resolveBiomeIds([]);
        $this->assertSame([], $result);
    }

    public function testResolveBiomeIdsParsesValidJson(): void
    {
        $result = $this->finder->resolveBiomeIds(['biome_ids' => '[1, 3, 7]']);
        $this->assertSame([1, 3, 7], $result);
    }

    public function testResolveBiomeIdsHandlesEmptyArray(): void
    {
        $result = $this->finder->resolveBiomeIds(['biome_ids' => '[]']);
        $this->assertSame([], $result);
    }

    public function testResolveBiomeIdsHandlesMalformedJson(): void
    {
        $result = $this->finder->resolveBiomeIds(['biome_ids' => 'not-json']);
        $this->assertSame([], $result);
    }
}
