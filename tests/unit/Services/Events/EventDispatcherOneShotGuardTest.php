<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Events;

use App\Services\Events\EventDispatcher;
use CodeIgniter\Test\CIUnitTestCase;
use Config\WorldEvents;

/**
 * ADR-141 — хотфикс one-shot guard для damage_resources (инцидент 2026-06-26, чар Father).
 *
 * Баг: MeteorRain (effect_params.one_shot_at_end=true, tick_chance=1.0) применялся на
 * КАЖДОМ поминутном тике вместо одного раза → IntentApplier снимал −15% от стака каждый
 * тик → за ~42 мин компаундинг 0.85^N ≈ −88% ВСЕГО склада у всех в задетом биоме.
 *
 * Фикс: EventDispatcher::dispatchEvent для one-shot эффектов проверяет
 * active_events.effect_applied (раньше — мёртвая колонка) и применяет ровно один раз.
 *
 * Этот тест лочит ЧИСТУЮ логику решения (без БД): какие события one-shot и как читается
 * замок. Глубокий dispatch (set-flag side) проверяется на testbot Tier-3.
 *
 * @internal
 */
final class EventDispatcherOneShotGuardTest extends CIUnitTestCase
{
    public function testMeteorRainIsOneShot(): void
    {
        $cfg    = (new WorldEvents())->get('MeteorRain');
        $this->assertIsArray($cfg, 'MeteorRain должен быть в WorldEvents config');
        $this->assertTrue(
            EventDispatcher::isOneShotEffect($cfg),
            'MeteorRain помечен one_shot_at_end=true → применяется один раз за событие'
        );
    }

    public function testPerTickDamageEventIsNotOneShot(): void
    {
        // Hurricane — continuous damage_health (тикает каждую минуту by design, НЕ one-shot).
        $cfg = (new WorldEvents())->get('Hurricane');
        $this->assertIsArray($cfg, 'Hurricane должен быть в WorldEvents config');
        $this->assertFalse(
            EventDispatcher::isOneShotEffect($cfg),
            'Hurricane — per-tick эффект, не one-shot → guard его не трогает'
        );
    }

    public function testMeteorImpactNotGatedByOneShotFlag(): void
    {
        // MeteorImpact — devastating 40% но duration=1 (ровно 1 тик), one_shot_at_end НЕ задан.
        // Guard на него не влияет: он и так применяется один раз (через 1-тиковую длительность).
        $cfg = (new WorldEvents())->get('MeteorImpact');
        $this->assertIsArray($cfg, 'MeteorImpact должен быть в WorldEvents config');
        $this->assertFalse(
            EventDispatcher::isOneShotEffect($cfg),
            'MeteorImpact не помечен one_shot_at_end (одноразовость даёт duration=1)'
        );
    }

    public function testIsOneShotFalseForMissingOrMalformedConfig(): void
    {
        $this->assertFalse(EventDispatcher::isOneShotEffect([]));
        $this->assertFalse(EventDispatcher::isOneShotEffect(['effect_params' => []]));
        $this->assertFalse(EventDispatcher::isOneShotEffect(['effect_params' => 'broken']));
        $this->assertFalse(EventDispatcher::isOneShotEffect(['effect_params' => ['one_shot_at_end' => false]]));
    }

    public function testAlreadyAppliedReadsLockCorrectly(): void
    {
        // Замок НЕ взведён → можно применять.
        $this->assertFalse(EventDispatcher::oneShotAlreadyApplied(['effect_applied' => 0]));
        $this->assertFalse(EventDispatcher::oneShotAlreadyApplied(['effect_applied' => '0']));
        $this->assertFalse(EventDispatcher::oneShotAlreadyApplied(['effect_applied' => null]));
        $this->assertFalse(EventDispatcher::oneShotAlreadyApplied(['effect_applied' => false]));
        $this->assertFalse(EventDispatcher::oneShotAlreadyApplied([]), 'нет ключа → не применён');

        // Замок взведён → пропускаем (анти-компаундинг).
        $this->assertTrue(EventDispatcher::oneShotAlreadyApplied(['effect_applied' => 1]));
        $this->assertTrue(EventDispatcher::oneShotAlreadyApplied(['effect_applied' => '1']));
        $this->assertTrue(EventDispatcher::oneShotAlreadyApplied(['effect_applied' => true]));
    }
}
