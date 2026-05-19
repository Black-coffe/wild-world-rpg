<?php

namespace Tests\Unit\Services\Events\Effects;

use App\Services\Events\Effects\RareResourceGrantEffect;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S10 (v0.51.192) — тести на RareResourceGrantEffect: live-tunable
 * через GameSettings override fallback на effect_params.
 *
 * Покриває:
 *  - Fallback на effect_params коли activeEvent.name_english=null (старі call-sites).
 *  - GameSettings override `rare_event.<name>.chance_per_tick`.
 *  - GameSettings override `rare_event.<name>.amount_min/max`.
 *  - Біом-фільтр + requires_state (regression).
 *  - Auto-fix max < min (graceful handling).
 *
 * @internal
 */
final class RareResourceGrantEffectTest extends CIUnitTestCase
{
    /**
     * Лёгкий fake tunable-reader — повертає підставні значення без БД.
     *
     * @param  array<string, float|int> $overrides
     * @return callable(string,mixed):mixed
     */
    private function fakeReader(array $overrides): callable
    {
        return static fn (string $key, mixed $default): mixed
            => $overrides[$key] ?? $default;
    }

    public function testFallbackToEffectParamsWhenEventNameMissing(): void
    {
        $effect = new RareResourceGrantEffect($this->fakeReader([]));
        $r      = $effect->compute(
            ['biome_id' => 8, 'level' => 20],
            ['effect_params' => [
                'resource_keyword' => 'fuel_rods',
                'rarity_filter'    => 1,
                'amount_range'     => [1, 2],
                'chance_per_tick'  => 1.0,  // garanteed
                'requires_state'   => 'gather',
                'biome_type_filter' => null,
            ]],
            [/* no name_english */],
            ['is_gathering' => true]
        );

        $this->assertTrue($r['applied']);
        $intent = $r['resource_grant_intents'][0];
        $this->assertSame('fuel_rods', $intent['keyword']);
        $this->assertSame(8, $intent['biome_id']);
        $this->assertGreaterThanOrEqual(1, $intent['amount']);
        $this->assertLessThanOrEqual(2, $intent['amount']);
    }

    public function testGameSettingsChanceOverrideZeroAlwaysSkips(): void
    {
        $effect = new RareResourceGrantEffect($this->fakeReader([
            'rare_event.VolcanicFuelCache.chance_per_tick' => 0.0,
        ]));

        // Effect_params chance=1.0, але GameSettings перевизначає на 0.0 → завжди skip.
        for ($i = 0; $i < 50; $i++) {
            $r = $effect->compute(
                ['biome_id' => 8, 'level' => 20],
                ['effect_params' => [
                    'resource_keyword' => 'fuel_rods',
                    'amount_range'     => [1, 2],
                    'chance_per_tick'  => 1.0,
                    'requires_state'   => 'gather',
                ]],
                ['name_english' => 'VolcanicFuelCache'],
                ['is_gathering' => true]
            );
            $this->assertFalse($r['applied']);
        }
    }

    public function testGameSettingsChanceOverrideOneAlwaysApplies(): void
    {
        $effect = new RareResourceGrantEffect($this->fakeReader([
            'rare_event.VolcanicFuelCache.chance_per_tick' => 1.0,
        ]));

        // Effect_params chance=0.0, але GameSettings перевизначає на 1.0 → завжди apply.
        $applied = 0;
        for ($i = 0; $i < 20; $i++) {
            $r = $effect->compute(
                ['biome_id' => 8, 'level' => 20],
                ['effect_params' => [
                    'resource_keyword' => 'fuel_rods',
                    'amount_range'     => [1, 2],
                    'chance_per_tick'  => 0.0,  // ← effect_params SKIP, GameSettings APPLY
                    'requires_state'   => 'gather',
                ]],
                ['name_english' => 'VolcanicFuelCache'],
                ['is_gathering' => true]
            );
            if ($r['applied'] ?? false) {
                $applied++;
            }
        }
        $this->assertSame(20, $applied, 'GameSettings chance=1.0 повинен override effect_params chance=0.0');
    }

    public function testGameSettingsAmountOverride(): void
    {
        $effect = new RareResourceGrantEffect($this->fakeReader([
            'rare_event.MountainArmyDepot.amount_min' => 7,
            'rare_event.MountainArmyDepot.amount_max' => 7,
        ]));

        $r = $effect->compute(
            ['biome_id' => 2, 'level' => 18],
            ['effect_params' => [
                'resource_keyword' => 'medical_compound',
                'amount_range'     => [1, 2],  // ← override на 7-7
                'chance_per_tick'  => 1.0,
                'requires_state'   => 'gather',
            ]],
            ['name_english' => 'MountainArmyDepot'],
            ['is_gathering' => true]
        );

        $this->assertTrue($r['applied']);
        $this->assertSame(7, $r['resource_grant_intents'][0]['amount']);
    }

    public function testAmountMaxBelowMinAutoFixed(): void
    {
        // GameSettings: amount_max=1 < amount_min=5 → effect повинен виправити до min=max=5.
        $effect = new RareResourceGrantEffect($this->fakeReader([
            'rare_event.IndustrialDumpFind.amount_min' => 5,
            'rare_event.IndustrialDumpFind.amount_max' => 1,
        ]));

        $r = $effect->compute(
            ['biome_id' => 5, 'level' => 15],
            ['effect_params' => [
                'resource_keyword' => 'industrial_plastic',
                'amount_range'     => [1, 3],
                'chance_per_tick'  => 1.0,
                'requires_state'   => 'gather',
            ]],
            ['name_english' => 'IndustrialDumpFind'],
            ['is_gathering' => true]
        );

        $this->assertTrue($r['applied']);
        $this->assertSame(5, $r['resource_grant_intents'][0]['amount']);
    }

    public function testRequiresGatherStateRegression(): void
    {
        // Не gather → skip (незалежно від GameSettings overrides).
        $effect = new RareResourceGrantEffect($this->fakeReader([
            'rare_event.VolcanicFuelCache.chance_per_tick' => 1.0,
        ]));

        $r = $effect->compute(
            ['biome_id' => 8, 'level' => 20],
            ['effect_params' => [
                'resource_keyword' => 'fuel_rods',
                'amount_range'     => [1, 2],
                'chance_per_tick'  => 1.0,
                'requires_state'   => 'gather',
            ]],
            ['name_english' => 'VolcanicFuelCache'],
            ['is_gathering' => false]
        );
        $this->assertFalse($r['applied']);
    }

    public function testBiomeTypeFilterRegression(): void
    {
        // RevealingHiddenCameras має biome_type_filter='cave'; гравець у Forest → skip.
        $effect = new RareResourceGrantEffect($this->fakeReader([]));

        $r = $effect->compute(
            ['biome_id' => 1, 'level' => 30],
            ['effect_params' => [
                'resource_keyword'   => 'RevealingHiddenCameras',
                'amount_range'       => [1, 2],
                'chance_per_tick'    => 1.0,
                'requires_state'     => 'gather',
                'biome_type_filter'  => 'cave',
            ]],
            ['name_english' => 'RevealingHiddenCameras'],
            ['is_gathering' => true, 'biome' => ['biome_type' => 'plain']]
        );

        $this->assertFalse($r['applied']);
    }
}
