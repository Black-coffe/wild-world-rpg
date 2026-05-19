<?php

namespace Tests\Unit\Config;

use Config\WorldEvents;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S10 (v0.51.192) — anti-drift guard для 4 нових rare-drop events.
 *
 * Падає якщо хтось:
 *  - перейменує `name_english` ключ у Config\WorldEvents (рассинхрон з БД row)
 *  - відключить `effect_kind='rare_resource_grant'`
 *  - забуде resource_keyword або amount_range / chance_per_tick
 *
 * @internal
 */
final class WorldEventsS10RareEventsTest extends CIUnitTestCase
{
    /** @var list<string> */
    private const S10_EVENT_KEYS = [
        'VolcanicFuelCache',
        'PreCollapseVaultOpening',
        'IndustrialDumpFind',
        'MountainArmyDepot',
    ];

    /** @var array<string, string> name_english → expected resource_keyword */
    private const EXPECTED_KEYWORDS = [
        'VolcanicFuelCache'        => 'fuel_rods',
        'PreCollapseVaultOpening'  => 'pre_collapse_electronics',
        'IndustrialDumpFind'       => 'industrial_plastic',
        'MountainArmyDepot'        => 'medical_compound',
    ];

    private WorldEvents $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new WorldEvents();
    }

    public function testAllFourS10EventsRegistered(): void
    {
        $registered = $this->cfg->keys();
        foreach (self::S10_EVENT_KEYS as $key) {
            $this->assertContains(
                $key,
                $registered,
                "S10 event '{$key}' відсутній у Config\\WorldEvents.php — drift із міграцією events row."
            );
        }
    }

    public function testAllS10EventsUseRareResourceGrantEffect(): void
    {
        foreach (self::S10_EVENT_KEYS as $key) {
            $cfg = $this->cfg->get($key);
            $this->assertIsArray($cfg, "Config для '{$key}' має бути array");
            $this->assertSame(
                'rare_resource_grant',
                $cfg['effect_kind'] ?? null,
                "S10 event '{$key}' повинен мати effect_kind='rare_resource_grant'"
            );
        }
    }

    public function testKeywordMappingMatchesMigration(): void
    {
        foreach (self::EXPECTED_KEYWORDS as $event => $expectedKeyword) {
            $cfg = $this->cfg->get($event);
            $this->assertIsArray($cfg);
            $this->assertSame(
                $expectedKeyword,
                $cfg['effect_params']['resource_keyword'] ?? null,
                "S10 event '{$event}' resource_keyword рассинхронізований з S10AddRareResources міграцією (keyword)."
            );
        }
    }

    public function testAllS10EventsRequireGatherAndRarity1(): void
    {
        foreach (self::S10_EVENT_KEYS as $key) {
            $cfg = $this->cfg->get($key);
            $this->assertIsArray($cfg);
            $params = $cfg['effect_params'] ?? [];
            $this->assertSame('gather', $params['requires_state'] ?? null, "S10 event '{$key}' має requires_state='gather'");
            $this->assertSame(1, $params['rarity_filter'] ?? null, "S10 event '{$key}' має rarity_filter=1 (rare semantic)");
        }
    }

    public function testAmountRangeSensible(): void
    {
        foreach (self::S10_EVENT_KEYS as $key) {
            $cfg = $this->cfg->get($key);
            $this->assertIsArray($cfg);
            $range = $cfg['effect_params']['amount_range'] ?? null;
            $this->assertIsArray($range, "S10 event '{$key}' повинен мати amount_range");
            $this->assertCount(2, $range);
            $this->assertGreaterThanOrEqual(1, $range[0]);
            $this->assertLessThanOrEqual($range[1], $range[0], 'amount_range[0] <= amount_range[1]');
        }
    }
}
