<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\EventDispatcher;
use App\Services\Events\IntentApplier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.3 — smoke-test на EventDispatcher: перевіряємо що клас інстантіюється,
 * має правильний public API, повертає stats array з очікуваною shape.
 *
 * Глибоке integration-тестування потребує seeded DB (events/active_events/
 * characters/biomes) — цей test обмежується wiring-perевіркою + контрактом
 * tickAllActive() return.
 *
 * Реальне end-to-end smoke виконується на testbot через Telegram bot.
 *
 * @internal
 */
final class EventDispatcherSmokeTest extends CIUnitTestCase
{
    public function testDispatcherCanInstantiateWithDefaults(): void
    {
        // Без mock'ів — використовує дефолтні моделі.
        // Якщо DB немає → tickAllActive викине exception на findAll().
        // Для smoke просто перевіряємо instantiate.
        $dispatcher = new EventDispatcher();
        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);
    }

    public function testTickAllActiveReturnsStatsShape(): void
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('active_events')) {
            $this->markTestSkipped('Test DB не має active_events. Запускати на testbot/проді.');
            return;
        }

        $dispatcher = new EventDispatcher();
        $stats      = $dispatcher->tickAllActive();

        // Очікувана shape stats:
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('active_events_total', $stats);
        $this->assertArrayHasKey('events_dispatched',   $stats);
        $this->assertArrayHasKey('players_evaluated',   $stats);
        $this->assertArrayHasKey('effects_applied',     $stats);
        $this->assertArrayHasKey('errors',              $stats);

        // Усі — ints
        foreach ($stats as $key => $val) {
            $this->assertIsInt($val, "stats.{$key} має бути int");
        }
    }

    public function testIntentApplierCanInstantiate(): void
    {
        $applier = new IntentApplier(
            new \App\Models\CharacterModel(),
            new \App\Models\CharacterResourceModel(),
            new \App\Models\CharacterTaskModel(),
            new \App\Models\ResourceModel(),
            new \App\Models\TaskModel(),
        );
        $this->assertInstanceOf(IntentApplier::class, $applier);
    }
}
