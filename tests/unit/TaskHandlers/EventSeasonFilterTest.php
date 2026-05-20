<?php

namespace Tests\Unit\TaskHandlers;

use App\TaskHandlers\Events\EventActivationHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WorldEvents;

/**
 * V4 (ADR-032) — сезонный гейт world-событий: EventActivationHandler::filterBySeason().
 *
 * Pure/статический фильтр (без БД). Проверяет: 4 signature-события доступны только
 * в свой сезон, year-round события и неизвестные ключи — всегда, killswitch (''=no-op).
 *
 * @internal
 */
final class EventSeasonFilterTest extends CIUnitTestCase
{
    private WorldEvents $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new WorldEvents();
    }

    /** @return list<array<string,mixed>> */
    private function sample(): array
    {
        return [
            ['name_english' => 'Snowfall'],     // required winter
            ['name_english' => 'SpringFlood'],  // required spring
            ['name_english' => 'Dryness'],      // required summer
            ['name_english' => 'BerryBoom'],    // required autumn
            ['name_english' => 'Hurricane'],    // no required_season → круглогодично
            ['name_english' => 'UnknownXyz'],   // нет в config → круглогодично
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<string>
     */
    private function names(array $events): array
    {
        return array_map(
            static fn (array $e): string => isset($e['name_english']) && is_string($e['name_english']) ? $e['name_english'] : '',
            $events
        );
    }

    public function testEmptySeasonReturnsAllUnfiltered(): void
    {
        // killswitch / межсезонье → фильтр no-op (backward-compat).
        $out = EventActivationHandler::filterBySeason($this->sample(), $this->cfg, '');
        $this->assertCount(6, $out);
    }

    public function testWinterKeepsSnowfallDropsOtherSeasonal(): void
    {
        $names = $this->names(EventActivationHandler::filterBySeason($this->sample(), $this->cfg, 'winter'));
        $this->assertContains('Snowfall', $names);
        $this->assertContains('Hurricane', $names);   // year-round
        $this->assertContains('UnknownXyz', $names);  // не в config → year-round
        $this->assertNotContains('SpringFlood', $names);
        $this->assertNotContains('Dryness', $names);
        $this->assertNotContains('BerryBoom', $names);
    }

    public function testEachSeasonKeepsOnlyItsSignature(): void
    {
        $map = [
            'winter' => 'Snowfall',
            'spring' => 'SpringFlood',
            'summer' => 'Dryness',
            'autumn' => 'BerryBoom',
        ];

        foreach ($map as $season => $expected) {
            $names = $this->names(EventActivationHandler::filterBySeason($this->sample(), $this->cfg, $season));
            $this->assertContains($expected, $names, "{$season} должен оставить {$expected}");
            $this->assertContains('Hurricane', $names, "{$season}: year-round событие всегда доступно");
            foreach ($map as $otherSeason => $signature) {
                if ($otherSeason !== $season) {
                    $this->assertNotContains($signature, $names, "{$season} должен исключить {$signature}");
                }
            }
        }
    }

    public function testResultIsReindexedList(): void
    {
        $out = EventActivationHandler::filterBySeason($this->sample(), $this->cfg, 'winter');
        $this->assertSame(range(0, count($out) - 1), array_keys($out));
    }

    /** Anti-drift: 4 signature-события реально имеют required_season в config. */
    public function testConfigHasRequiredSeasonOnSignatures(): void
    {
        $expected = [
            'Snowfall'    => 'winter',
            'SpringFlood' => 'spring',
            'Dryness'     => 'summer',
            'BerryBoom'   => 'autumn',
        ];
        foreach ($expected as $event => $season) {
            $cfg = $this->cfg->get($event);
            $this->assertIsArray($cfg, "{$event} есть в WorldEvents config");
            $this->assertSame($season, $cfg['required_season'] ?? null, "{$event}.required_season == {$season}");
        }
    }
}
