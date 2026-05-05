<?php

namespace Tests\Unit\TaskHandlers\Events;

use App\TaskHandlers\Events\EventActivationHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WorldEvents;

/**
 * F7.9 — тесты на pickWeighted (заміна array_rand → weighted random).
 *
 * Pure-static method, тестується без I/O.
 *
 * @internal
 */
final class EventActivationHandlerWeightedTest extends CIUnitTestCase
{
    public function testSingleEventReturnedAsIs(): void
    {
        $cfg = new WorldEvents();
        $events = [['name_english' => 'Hurricane', 'event_id' => 1]];

        $picked = EventActivationHandler::pickWeighted($events, $cfg);
        $this->assertSame('Hurricane', $picked['name_english']);
    }

    public function testHigherWeightWinsMoreOften(): void
    {
        $cfg = new WorldEvents();
        // Starfall = weight 5, Hurricane = weight 1
        $events = [
            ['name_english' => 'Hurricane', 'event_id' => 1],
            ['name_english' => 'Starfall',  'event_id' => 21],
        ];

        $hurricaneCount = 0;
        $starfallCount  = 0;
        $iterations     = 1000;

        for ($i = 0; $i < $iterations; $i++) {
            $picked = EventActivationHandler::pickWeighted($events, $cfg);
            if ($picked['name_english'] === 'Hurricane') {
                $hurricaneCount++;
            } elseif ($picked['name_english'] === 'Starfall') {
                $starfallCount++;
            }
        }

        $this->assertSame($iterations, $hurricaneCount + $starfallCount);

        // Ожидается: Hurricane ~166 (1/6), Starfall ~833 (5/6)
        // Допускаем ±25% варіацію (statistical noise)
        $this->assertGreaterThan($hurricaneCount * 3, $starfallCount,
            "Starfall (weight 5) должен бути значительно чаще за Hurricane (weight 1). " .
            "Got Starfall={$starfallCount}, Hurricane={$hurricaneCount}");
    }

    public function testEqualWeightsRoughlyEqualDistribution(): void
    {
        $cfg = new WorldEvents();
        // Hurricane и Snowfall обидва weight=1
        $events = [
            ['name_english' => 'Hurricane', 'event_id' => 1],
            ['name_english' => 'Snowfall',  'event_id' => 9],
        ];

        $h = 0;
        $s = 0;
        for ($i = 0; $i < 1000; $i++) {
            $picked = EventActivationHandler::pickWeighted($events, $cfg);
            if ($picked['name_english'] === 'Hurricane') {
                $h++;
            } else {
                $s++;
            }
        }

        // Ожидаемо ~500/500. Допускаем ±15%
        $this->assertGreaterThan(350, $h, 'Hurricane должен бути ~500 з 1000');
        $this->assertGreaterThan(350, $s, 'Snowfall должен бути ~500 з 1000');
    }

    public function testUnknownEventGetsDefaultWeightOf1(): void
    {
        $cfg = new WorldEvents();
        // 'NotInConfig' fallback на weight=1, Starfall weight=5
        $events = [
            ['name_english' => 'NotInConfig', 'event_id' => 999],
            ['name_english' => 'Starfall',    'event_id' => 21],
        ];

        $unknown = 0;
        $star    = 0;
        for ($i = 0; $i < 600; $i++) {
            $picked = EventActivationHandler::pickWeighted($events, $cfg);
            if ($picked['name_english'] === 'NotInConfig') {
                $unknown++;
            } else {
                $star++;
            }
        }

        // Starfall (5) должен бути значительно чаще за NotInConfig (1)
        $this->assertGreaterThan($unknown * 3, $star);
    }

    public function testMultipleEventsAllAppear(): void
    {
        $cfg = new WorldEvents();
        $events = [
            ['name_english' => 'Hurricane',    'event_id' => 1],
            ['name_english' => 'Snowfall',     'event_id' => 9],
            ['name_english' => 'MountainEcho', 'event_id' => 18],
            ['name_english' => 'Starfall',     'event_id' => 21],
        ];

        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $picked = EventActivationHandler::pickWeighted($events, $cfg);
            $seen[$picked['name_english']] = ($seen[$picked['name_english']] ?? 0) + 1;
        }

        // Все 4 подіи должны з'явитись хоча б раз за 500 попыток
        $this->assertCount(4, $seen);
    }
}
