<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\WorldEvents;

/**
 * F7.1 — unit-тести на declarative registry WorldEvents.php.
 *
 * Перевіряємо:
 *   1. Кожен row у БД `events` має відповідник у WorldEvents::$events
 *      (по `name_english` ключу). Це гарантує що F7 cutover не пропустить
 *      жодної існуючої події.
 *   2. Усі effect_kind у конфігу — валідні enum'и (10 дозволених kinds).
 *   3. Усі notification_kind — валідні enum'и (3 дозволених kinds).
 *   4. Структурні інваріанти per-kind (наприклад, damage_health МАЄ
 *      damage_target і state_modifier).
 *   5. Дюрації — не довші за 90 хв (анти-spam policy).
 *   6. Frequency_weight — позитивне ціле.
 *
 * @internal
 */
final class WorldEventsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $seed    = '';

    private WorldEvents $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new WorldEvents();
    }

    // ============================================================
    // Coverage: всі 24 події з БД є в конфігу
    // ============================================================

    /**
     * Helper: returns DB rows or null if events table doesn't exist
     * (e.g., test DB не засіяна; запускати на testbot/проді з реальною DB).
     */
    private function fetchDbEventRowsOrSkip(): ?array
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('events')) {
            $this->markTestSkipped(
                "Test DB не має таблиці 'events'. Тест passes на testbot/проді з реальною БД. " .
                "Для локального прогону — імпортувати prod dump або запустити seed."
            );
            return null;
        }
        return $db->table('events')->select('name_english')->get()->getResultArray();
    }

    public function testAllDbEventsHaveConfigEntry(): void
    {
        $rows = $this->fetchDbEventRowsOrSkip();
        if ($rows === null) {
            return;
        }

        $this->assertNotEmpty($rows, 'DB.events порожня — нічого тестувати');

        $configKeys = $this->cfg->keys();
        foreach ($rows as $r) {
            $name = $r['name_english'];
            $this->assertContains(
                $name,
                $configKeys,
                "DB event '{$name}' відсутній у WorldEvents::\$events. " .
                "Або додай у конфіг, або видали row з БД."
            );
        }
    }

    public function testConfigHasNo24OrphanEvents(): void
    {
        $rows = $this->fetchDbEventRowsOrSkip();
        if ($rows === null) {
            return;
        }

        $dbKeys = array_column($rows, 'name_english');

        foreach ($this->cfg->keys() as $configKey) {
            $this->assertContains(
                $configKey,
                $dbKeys,
                "WorldEvents config має '{$configKey}' але DB.events не має. " .
                "Або додай у БД, або прибери з конфіга."
            );
        }
    }

    public function testConfigHasExactly31Events(): void
    {
        // 2026-05-09: 25 подій (24 historical + MeteorImpact community idea #2 v0.51.127).
        // 2026-05-19: +4 S10 rare-drop events (VolcanicFuelCache / PreCollapseVaultOpening /
        // IndustrialDumpFind / MountainArmyDepot) → 29.
        // 2026-06-12: +2 E17 Ф2 (ADR-117) — RadioactiveFog (harm-tier) + CleanSpring (boon-tier) → 31.
        // 2026-07-10: +1 E32 — NovayaEra (noop/silent витринное событие, буст добычи вне tick-движка) → 32.
        // Якщо число змінюється, оновити тут і в hot.md/Events-actual.md.
        $this->assertCount(32, $this->cfg->keys(), 'Очікується 32 події у конфігу');
    }

    // ============================================================
    // Schema: усі обов'язкові поля заповнені
    // ============================================================

    public function testEveryEventHasAllRequiredTopLevelFields(): void
    {
        $required = [
            'effect_kind',
            'effect_params',
            'duration_minutes',
            'frequency_weight',
            'tick_chance',
            'protection_item',
            'notification_kind',
        ];

        foreach ($this->cfg->events as $key => $event) {
            foreach ($required as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $event,
                    "Подія '{$key}' не містить обов'язкове поле '{$field}'"
                );
            }
        }
    }

    public function testTickChanceWithinValidRange(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            $tc = $event['tick_chance'] ?? null;
            $this->assertIsFloat($tc,
                "tick_chance у '{$key}' має бути float, отримано: " . gettype($tc));
            $this->assertGreaterThanOrEqual(0.0, $tc,
                "tick_chance у '{$key}' має бути >= 0");
            $this->assertLessThanOrEqual(1.0, $tc,
                "tick_chance у '{$key}' має бути <= 1");
        }
    }

    // ============================================================
    // Enum validation
    // ============================================================

    public function testEveryEffectKindIsValidEnum(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            $this->assertContains(
                $event['effect_kind'],
                WorldEvents::VALID_EFFECT_KINDS,
                "Подія '{$key}' має невалідний effect_kind '{$event['effect_kind']}'. " .
                "Дозволені: " . implode(', ', WorldEvents::VALID_EFFECT_KINDS)
            );
        }
    }

    public function testEveryNotificationKindIsValidEnum(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            $this->assertContains(
                $event['notification_kind'],
                WorldEvents::VALID_NOTIFICATION_KINDS,
                "Подія '{$key}' має невалідний notification_kind '{$event['notification_kind']}'"
            );
        }
    }

    // ============================================================
    // Duration policy: anti-spam — все ≤ 90 хв
    // ============================================================

    public function testEveryDurationIsAtMost90Minutes(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            // Анти-spam cap ≤90 хв стосується TICK-подій (кожнохвилинний ефект = ризик спаму).
            // Витринні події з effect_kind='noop' + notification_kind='silent' (E32 NovayaEra:
            // тижневий буст добычі поза tick-движком, без нотифікацій) НЕ тікають і НЕ спамлять —
            // їхня реальна тривалість задається active_events.end_time, cap до них не застосовний.
            $isSilentShowcase = ($event['effect_kind'] ?? '') === 'noop'
                && ($event['notification_kind'] ?? '') === 'silent';

            if (! $isSilentShowcase) {
                $this->assertLessThanOrEqual(
                    90,
                    $event['duration_minutes'],
                    "Подія '{$key}' має duration_minutes={$event['duration_minutes']}, " .
                    "максимум 90 за анти-spam policy F7."
                );
            }

            $this->assertGreaterThan(
                0,
                $event['duration_minutes'],
                "Подія '{$key}' має нульову/від'ємну дюрацію"
            );
        }
    }

    public function testEveryFrequencyWeightIsPositive(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            $this->assertGreaterThan(
                0,
                $event['frequency_weight'],
                "Подія '{$key}' має невалідний frequency_weight={$event['frequency_weight']}"
            );
            $this->assertIsInt(
                $event['frequency_weight'],
                "Подія '{$key}' frequency_weight має бути int"
            );
        }
    }

    // ============================================================
    // Per-kind structural invariants
    // ============================================================

    public function testDamageHealthEventsHaveRequiredParams(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            if ($event['effect_kind'] !== 'damage_health') {
                continue;
            }
            $this->assertArrayHasKey('damage_target', $event['effect_params'],
                "damage_health подія '{$key}' має містити effect_params.damage_target");
            $this->assertArrayHasKey('state_modifier', $event['effect_params'],
                "damage_health подія '{$key}' має містити effect_params.state_modifier");

            $sm = $event['effect_params']['state_modifier'];
            foreach (['base_idle', 'biome_idle', 'biome_active'] as $stateKey) {
                $this->assertArrayHasKey($stateKey, $sm,
                    "state_modifier у '{$key}' має містити '{$stateKey}'");
            }
        }
    }

    public function testGatherDebuffEventsHaveRequiredParams(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            if ($event['effect_kind'] !== 'gather_debuff') {
                continue;
            }
            $this->assertArrayHasKey('gather_rate_modifier', $event['effect_params'],
                "gather_debuff '{$key}' має містити effect_params.gather_rate_modifier");
            $this->assertLessThanOrEqual(
                0,
                $event['effect_params']['gather_rate_modifier'],
                "gather_rate_modifier у '{$key}' має бути <=0 (це debuff)"
            );
        }
    }

    public function testRareResourceGrantEventsHaveRequiredParams(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            if ($event['effect_kind'] !== 'rare_resource_grant') {
                continue;
            }
            $this->assertArrayHasKey('resource_keyword', $event['effect_params'],
                "rare_resource_grant '{$key}' має містити effect_params.resource_keyword");
            $this->assertArrayHasKey('amount_range', $event['effect_params'],
                "rare_resource_grant '{$key}' має містити effect_params.amount_range");
            $this->assertArrayHasKey('chance_per_tick', $event['effect_params'],
                "rare_resource_grant '{$key}' має містити effect_params.chance_per_tick");
        }
    }

    public function testGoldGrantEventHasCapFormula(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            if ($event['effect_kind'] !== 'gold_grant') {
                continue;
            }
            $this->assertArrayHasKey('cap_formula', $event['effect_params'],
                "gold_grant '{$key}' має містити effect_params.cap_formula (анти-power-creep)");
        }
    }

    public function testTaskExtendEventsHaveTaskFilter(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            if ($event['effect_kind'] !== 'task_extend') {
                continue;
            }
            $this->assertArrayHasKey('task_filter', $event['effect_params'],
                "task_extend '{$key}' має містити effect_params.task_filter");
            $this->assertNotEmpty($event['effect_params']['task_filter'],
                "task_filter у '{$key}' порожній — нічого подовжувати");
        }
    }

    // ============================================================
    // Spot checks (важливі рішення з audit'а)
    // ============================================================

    public function testDrynessShortenedDramatically(): void
    {
        // Audit decision: Засуха 14г → 90 хв
        $event = $this->cfg->get('Dryness');
        $this->assertNotNull($event, 'Dryness має бути в конфігу (раніше dead handler)');
        $this->assertSame(90, $event['duration_minutes'],
            'Dryness має бути 90 хв (з 860 хв у legacy DB)');
        $this->assertSame('gather_debuff', $event['effect_kind']);
    }

    public function testVolcanicEruptionShortenedFrom256To60(): void
    {
        $event = $this->cfg->get('volcanic_eruption');
        $this->assertNotNull($event);
        $this->assertSame(60, $event['duration_minutes'],
            'volcanic_eruption має бути 60 хв (з 256 хв у legacy DB)');
    }

    public function testBerryBoomImplementedNotStub(): void
    {
        $event = $this->cfg->get('BerryBoom');
        $this->assertNotNull($event);
        $this->assertSame('rare_resource_grant', $event['effect_kind']);
        $this->assertSame('berry', $event['effect_params']['resource_keyword']);
    }

    public function testStarfallHasHighestFrequencyWeight(): void
    {
        $event = $this->cfg->get('Starfall');
        $this->assertNotNull($event);
        $this->assertSame(5, $event['frequency_weight'],
            'Starfall — найчастіша подія за лором («сотні падаючих зірок»)');
    }

    public function testGoldMineHasCapFormulaToFightPowerCreep(): void
    {
        $event = $this->cfg->get('GoldMine');
        $this->assertNotNull($event);
        $this->assertSame('level_50', $event['effect_params']['cap_formula'],
            'GoldMine має cap_formula=level_50 (анти-power-creep, 1500g на рівні 30)');
    }

    public function testPolarNightGrantsImmunityToNightAttacks(): void
    {
        $event = $this->cfg->get('PolarNight');
        $this->assertNotNull($event);
        $this->assertContains('NightAttacks', $event['effect_params']['grants_immunity_to'] ?? [],
            'PolarNight має давати immunity до NightAttacks (lore: постійна темрява = немає звичайних нічних нападників)');
    }

    public function testNightAttacksSkipsSleepingPlayers(): void
    {
        $event = $this->cfg->get('NightAttacks');
        $this->assertNotNull($event);
        $this->assertSame(12, $event['effect_params']['sleeping_player_skip'] ?? null,
            'NightAttacks має skip гравців з last_seen > 12 годин (не караємо сов)');
    }

    public function testHurricaneHasBandageAsProtection(): void
    {
        $event = $this->cfg->get('Hurricane');
        $this->assertNotNull($event);
        $this->assertSame('Bandage', $event['protection_item']);
    }
}
