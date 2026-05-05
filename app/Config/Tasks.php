<?php

namespace Config;

use CodeIgniter\Tasks\Config\Tasks as BaseTasks;
use CodeIgniter\Tasks\Scheduler;

/**
 * F1.2 — декларативное расписание для всех recurring task-handler'ов.
 *
 * Запуск (один cron-line):
 *   * * * * * cd /path/to/mmorpg && php spark tasks:run
 *
 * Управление:
 *   php spark tasks:list         — что зарегистрировано
 *   php spark tasks:enable <id>
 *   php spark tasks:disable <id>
 *
 * Все задачи помечены ->singleInstance() — фреймворк ставит lock на каждое
 * имя задачи, и если предыдущий вызов ещё бежит — следующий пропускается.
 * Это решает overlap-проблему ровно так же, как F0.1 flock(), но per-task.
 *
 * Strangler-pattern (на 2026-05-04):
 *   ⚠️ Worker.php::processUnlinkedActions() ВСЁ ЕЩЁ работает параллельно
 *   с этим scheduler'ом. Мы оставляем оба на ~неделю-две для сравнения.
 *   После того как поверим что новый scheduler работает корректно
 *   (мониторинг логов /admin_audit_log + сравнение Worker wall-time):
 *     1. Удалить processUnlinkedActions() из Worker.php (–~300 LOC).
 *     2. Поменять cron на 'php spark tasks:run' (одна строка).
 *     3. Старый /cbgsvd-d-dw/worker route удалить из Routes.php.
 *
 * Частоты: пока что ВСЕ handler'ы на everyMinute() — то же самое поведение
 * что и в processUnlinkedActions (там Worker дёргает их все каждую минуту,
 * а handler сам внутри проверяет нужное ли время). Это safe default.
 * На прод-замерах распишем правильные триггеры (->daily('21:33'),
 * ->hourly(), ->cron('* /15 * * * *')) — там где это действительно нужно.
 *
 * См. mmorpg-vault/decisions/ADR-012-Tick-system-direction.md (Phase B).
 */
class Tasks extends BaseTasks
{
    public bool $logPerformance = false; // если включим — нужна settings-table
    public int $maxLogsPerTask  = 10;

    public function init(Scheduler $schedule)
    {
        // ============================================================
        // PLAYER LIFECYCLE — каждую минуту
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\HealthRegenerationHandler())->process())
            ->everyMinute()->singleInstance()->named('regen.health');

        $schedule->call(static fn() => (new \App\TaskHandlers\ResourceBankUpdateHandler())->process())
            ->everyMinute()->singleInstance()->named('resource-bank.update');

        $schedule->call(static fn() => (new \App\TaskHandlers\CharacterDataHandler())->process())
            ->everyMinute()->singleInstance()->named('character-data.refresh');

        $schedule->call(static fn() => (new \App\TaskHandlers\LowHealthWarningHandler())->process())
            ->everyMinute()->singleInstance()->named('low-health.warning');

        $schedule->call(static fn() => (new \App\TaskHandlers\DeathRouletteHandler())->process())
            ->everyMinute()->singleInstance()->named('death-roulette');

        // Питание/вода — handler сам проверяет 21:33 (Europe/Kiev) внутри.
        // TODO: после стабилизации заменить на ->daily('21:33')
        $schedule->call(static fn() => (new \App\TaskHandlers\FoodAndWaterConsumptionHandler())->process())
            ->everyMinute()->singleInstance()->named('food-water.consumption');

        // Налоги — handler сам проверяет 03:00 внутри.
        // TODO: после стабилизации заменить на ->daily('03:00')
        $schedule->call(static fn() => (new \App\TaskHandlers\TaxCollectionHandler())->handle())
            ->everyMinute()->singleInstance()->named('tax.collection');

        // ============================================================
        // BUILDING PRODUCTION — каждую минуту
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\GreenhouseProductionHandler())->handle())
            ->everyMinute()->singleInstance()->named('greenhouse.produce');

        $schedule->call(static fn() => (new \App\TaskHandlers\Built\GymProductionHandler())->handle())
            ->everyMinute()->singleInstance()->named('gym.produce');

        $schedule->call(static fn() => (new \App\TaskHandlers\Built\HandPumpProductionHandler())->handle())
            ->everyMinute()->singleInstance()->named('handpump.produce');

        // ============================================================
        // WORLD EVENTS — handler внутри сам делает probabilistic activate
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\EventActivationHandler())->process())
            ->everyMinute()->singleInstance()->named('event.activation');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\EpidemicHandler())->process())
            ->everyMinute()->singleInstance()->named('event.epidemic');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\TremorHandler())->process())
            ->everyMinute()->singleInstance()->named('event.tremor');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\FeverHandler())->process())
            ->everyMinute()->singleInstance()->named('event.fever');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\VolcanicEruptionHandler())->process())
            ->everyMinute()->singleInstance()->named('event.volcanic');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\FlashForestFireHandler())->process())
            ->everyMinute()->singleInstance()->named('event.forest-fire');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\HurricaneHandler())->process())
            ->everyMinute()->singleInstance()->named('event.hurricane');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\SandStormHandler())->process())
            ->everyMinute()->singleInstance()->named('event.sandstorm');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\MeteorShowerHandler())->process())
            ->everyMinute()->singleInstance()->named('event.meteor-shower');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\DungeonOpeningHandler())->process())
            ->everyMinute()->singleInstance()->named('event.dungeon-opening');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\MountainEchoHandler())->process())
            ->everyMinute()->singleInstance()->named('event.mountain-echo');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\NightAttacksHandler())->process())
            ->everyMinute()->singleInstance()->named('event.night-attacks');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\GoldVeinHandler())->process())
            ->everyMinute()->singleInstance()->named('event.gold-vein');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\SnowFallHandler())->process())
            ->everyMinute()->singleInstance()->named('event.snowfall');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\NorthernLightsHandler())->process())
            ->everyMinute()->singleInstance()->named('event.northern-lights');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\ShootingStarHandler())->process())
            ->everyMinute()->singleInstance()->named('event.shooting-star');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\GeothermalSpringsHandler())->process())
            ->everyMinute()->singleInstance()->named('event.geothermal');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\MirageOasisHandler())->process())
            ->everyMinute()->singleInstance()->named('event.mirage-oasis');

        // ============================================================
        // WORLD CONTENT — генерация объектов и NPC-спавн
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\Other\WorldObjectGeneratorHandler())->process())
            ->everyMinute()->singleInstance()->named('world-objects.generate');

        $schedule->call(static fn() => (new \App\TaskHandlers\NPC\SpawnSandyWolfRaidersCron())->run())
            ->everyMinute()->singleInstance()->named('npc.sandy-wolves');

        $schedule->call(static fn() => (new \App\TaskHandlers\NPC\AutoPveHandler())->run())
            ->everyMinute()->singleInstance()->named('npc.auto-pve');

        // ============================================================
        // QUESTS — handler сам проверяет прогресс игроков
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestExplore30CellsHandler())->process())
            ->everyMinute()->singleInstance()->named('quest.explore-30');

        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestExploreAllBiomesHandler())->process())
            ->everyMinute()->singleInstance()->named('quest.explore-biomes');

        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestExplore300CellsHandler())->process())
            ->everyMinute()->singleInstance()->named('quest.explore-300');

        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestFirstAidkitBasicHandler())->process())
            ->everyMinute()->singleInstance()->named('quest.first-aid-kit');

        // ============================================================
        // SOCIAL
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\Other\FactionNotificationHandler())->process())
            ->everyMinute()->singleInstance()->named('faction.notification');

        // ============================================================
        // CHARACTER TASKS DISPATCHER (главный Worker loop)
        // ============================================================

        // F4.1 (v0.X.0): включён через scheduler. Worker::processTasks()
        // обрабатывает character_tasks с end_time<=NOW (gather/explore/build/craft).
        // Двойная защита: ->singleInstance(2 * MINUTE) на schedule + flock на
        // worker.lock внутри метода (F0.1). Параллельный legacy curl-cron на
        // /cbgsvd-d-dw/worker URL продолжает дёргать тот же endpoint в
        // strangler-режиме F4.1; тот тихо выходит на flock conflict. F4.2
        // удалит curl-cron + URL.
        $schedule->call(static fn() => (new \App\Controllers\Worker())->processTasks())
            ->everyMinute()->singleInstance(2 * MINUTE)->named('worker.character-tasks');

        // ============================================================
        // CLEANUP
        // ============================================================

        $schedule->command('tasks:cleanup')
            ->daily('03:30')->named('character-tasks.cleanup');
    }
}
