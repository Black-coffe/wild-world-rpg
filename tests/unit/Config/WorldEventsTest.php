<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\WorldEvents;

/**
 * F7.1 — unit-тесты на declarative registry WorldEvents.php.
 *
 * Проверяем:
 *   1. Кожен row у БД `events` должен відповідник у WorldEvents::$events
 *      (по `name_english` ключу). Це гарантує що F7 cutover не пропустить
 *      ни однои існуючои подіи.
 *   2. Все effect_kind у конфігу — валідни enum'и (10 дозволених kinds).
 *   3. Все notification_kind — валідни enum'и (3 дозволених kinds).
 *   4. Структурни инварианти per-kind (наприклад, damage_health МАЕ
 *      damage_target и state_modifier).
 *   5. Дюраціи — не довши за 90 хв (анти-spam policy).
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
    // Coverage: вси 24 подіи з БД есть в конфігу
    // ============================================================

    /**
     * Helper: returns DB rows or null if events table doesn't exist
     * (e.g., test DB не засіяна; запускати на testbot/проди з реальною DB).
     */
    private function fetchDbEventRowsOrSkip(): ?array
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('events')) {
            $this->markTestSkipped(
                "Test DB не должен таблици 'events'. Тест passes на testbot/проди з реальною БД. " .
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
                "DB event '{$name}' отсутствій у WorldEvents::\$events. " .
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
                "WorldEvents config должен '{$configKey}' але DB.events не має. " .
                "Або додай у БД, або прибери з конфіга."
            );
        }
    }

    public function testConfigHasExactly24Events(): void
    {
        // 2026-05-05: у БД зареєстровано 24 подіи. Если це число изменяетться,
        // оновити тут и в hot.md/Events-actual.md.
        $this->assertCount(24, $this->cfg->keys(), 'Очікується 24 подіи у конфігу');
    }

    // ============================================================
    // Schema: все обов'язкови поля заповнени
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
                "tick_chance у '{$key}' должен бути float, отримано: " . gettype($tc));
            $this->assertGreaterThanOrEqual(0.0, $tc,
                "tick_chance у '{$key}' должен бути >= 0");
            $this->assertLessThanOrEqual(1.0, $tc,
                "tick_chance у '{$key}' должен бути <= 1");
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
                "Подія '{$key}' должен невалидний effect_kind '{$event['effect_kind']}'. " .
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
                "Подія '{$key}' должен невалидний notification_kind '{$event['notification_kind']}'"
            );
        }
    }

    // ============================================================
    // Duration policy: anti-spam — все ≤ 90 хв
    // ============================================================

    public function testEveryDurationIsAtMost90Minutes(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            $this->assertLessThanOrEqual(
                90,
                $event['duration_minutes'],
                "Подія '{$key}' должен duration_minutes={$event['duration_minutes']}, " .
                "максимум 90 за анти-spam policy F7."
            );
            $this->assertGreaterThan(
                0,
                $event['duration_minutes'],
                "Подія '{$key}' должен нульову/від'ємну дюрацію"
            );
        }
    }

    public function testEveryFrequencyWeightIsPositive(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            $this->assertGreaterThan(
                0,
                $event['frequency_weight'],
                "Подія '{$key}' должен невалидний frequency_weight={$event['frequency_weight']}"
            );
            $this->assertIsInt(
                $event['frequency_weight'],
                "Подія '{$key}' frequency_weight должен бути int"
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
                "damage_health подія '{$key}' должен містити effect_params.damage_target");
            $this->assertArrayHasKey('state_modifier', $event['effect_params'],
                "damage_health подія '{$key}' должен містити effect_params.state_modifier");

            $sm = $event['effect_params']['state_modifier'];
            foreach (['base_idle', 'biome_idle', 'biome_active'] as $stateKey) {
                $this->assertArrayHasKey($stateKey, $sm,
                    "state_modifier у '{$key}' должен містити '{$stateKey}'");
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
                "gather_debuff '{$key}' должен містити effect_params.gather_rate_modifier");
            $this->assertLessThanOrEqual(
                0,
                $event['effect_params']['gather_rate_modifier'],
                "gather_rate_modifier у '{$key}' должен бути <=0 (це debuff)"
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
                "rare_resource_grant '{$key}' должен містити effect_params.resource_keyword");
            $this->assertArrayHasKey('amount_range', $event['effect_params'],
                "rare_resource_grant '{$key}' должен містити effect_params.amount_range");
            $this->assertArrayHasKey('chance_per_tick', $event['effect_params'],
                "rare_resource_grant '{$key}' должен містити effect_params.chance_per_tick");
        }
    }

    public function testGoldGrantEventHasCapFormula(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            if ($event['effect_kind'] !== 'gold_grant') {
                continue;
            }
            $this->assertArrayHasKey('cap_formula', $event['effect_params'],
                "gold_grant '{$key}' должен містити effect_params.cap_formula (анти-power-creep)");
        }
    }

    public function testTaskExtendEventsHaveTaskFilter(): void
    {
        foreach ($this->cfg->events as $key => $event) {
            if ($event['effect_kind'] !== 'task_extend') {
                continue;
            }
            $this->assertArrayHasKey('task_filter', $event['effect_params'],
                "task_extend '{$key}' должен містити effect_params.task_filter");
            $this->assertNotEmpty($event['effect_params']['task_filter'],
                "task_filter у '{$key}' порожній — нічого подовжувати");
        }
    }

    // ============================================================
    // Spot checks (важливи рішення з audit'а)
    // ============================================================

    public function testDrynessShortenedDramatically(): void
    {
        // Audit decision: Засуха 14г → 90 хв
        $event = $this->cfg->get('Dryness');
        $this->assertNotNull($event, 'Dryness должен бути в конфігу (ранее dead handler)');
        $this->assertSame(90, $event['duration_minutes'],
            'Dryness должен бути 90 хв (з 860 хв у legacy DB)');
        $this->assertSame('gather_debuff', $event['effect_kind']);
    }

    public function testVolcanicEruptionShortenedFrom256To60(): void
    {
        $event = $this->cfg->get('volcanic_eruption');
        $this->assertNotNull($event);
        $this->assertSame(60, $event['duration_minutes'],
            'volcanic_eruption должен бути 60 хв (з 256 хв у legacy DB)');
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
            'Starfall — найчащеа подія за лором («сотни падаючих зірок»)');
    }

    public function testGoldMineHasCapFormulaToFightPowerCreep(): void
    {
        $event = $this->cfg->get('GoldMine');
        $this->assertNotNull($event);
        $this->assertSame('level_50', $event['effect_params']['cap_formula'],
            'GoldMine должен cap_formula=level_50 (анти-power-creep, 1500g на рівни 30)');
    }

    public function testPolarNightGrantsImmunityToNightAttacks(): void
    {
        $event = $this->cfg->get('PolarNight');
        $this->assertNotNull($event);
        $this->assertContains('NightAttacks', $event['effect_params']['grants_immunity_to'] ?? [],
            'PolarNight должен давати immunity до NightAttacks (lore: постійна темрява = недолжен обычних нічних нападників)');
    }

    public function testNightAttacksSkipsSleepingPlayers(): void
    {
        $event = $this->cfg->get('NightAttacks');
        $this->assertNotNull($event);
        $this->assertSame(12, $event['effect_params']['sleeping_player_skip'] ?? null,
            'NightAttacks должен skip игрокив з last_seen > 12 годин (не караємо сов)');
    }

    public function testHurricaneHasBandageAsProtection(): void
    {
        $event = $this->cfg->get('Hurricane');
        $this->assertNotNull($event);
        $this->assertSame('Bandage', $event['protection_item']);
    }
}
