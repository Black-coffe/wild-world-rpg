<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\EventDispatcher;
use App\Services\Events\IntentApplier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7.3 — smoke-test на EventDispatcher: проверяем що клас инстантіюється,
 * должен правильний public API, возвращает stats array з ожидаетсяю shape.
 *
 * Глубоке integration-тестування потребує seeded DB (events/active_events/
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
        // Без mock'ів — використовує дефолтни модели.
        // Если DB недолжен → tickAllActive викине exception на findAll().
        // Для smoke просто проверяем instantiate.
        $dispatcher = new EventDispatcher();
        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);
    }

    public function testTickAllActiveReturnsStatsShape(): void
    {
        $db = \Config\Database::connect();
        if (!$db->tableExists('active_events')) {
            $this->markTestSkipped('Test DB не должен active_events. Запускати на testbot/проди.');
            return;
        }

        $dispatcher = new EventDispatcher();
        $stats      = $dispatcher->tickAllActive();

        // Ожидаема shape stats:
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('active_events_total', $stats);
        $this->assertArrayHasKey('events_dispatched',   $stats);
        $this->assertArrayHasKey('players_evaluated',   $stats);
        $this->assertArrayHasKey('effects_applied',     $stats);
        $this->assertArrayHasKey('errors',              $stats);

        // Все — ints
        foreach ($stats as $key => $val) {
            $this->assertIsInt($val, "stats.{$key} должен бути int");
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
