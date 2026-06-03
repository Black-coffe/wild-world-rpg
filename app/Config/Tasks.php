<?php

declare(strict_types=1);

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

        $schedule->call(static fn() => (new \App\TaskHandlers\CharacterDataHandler())->handle())
            ->everyMinute()->singleInstance()->named('character-data.refresh');

        $schedule->call(static fn() => (new \App\TaskHandlers\LowHealthWarningHandler())->handle())
            ->everyMinute()->singleInstance()->named('low-health.warning');

        $schedule->call(static fn() => (new \App\TaskHandlers\DeathRouletteHandler())->handle())
            ->everyMinute()->singleInstance()->named('death-roulette');

        // Питание/вода — v0.51.95: stable since FoodWater hotfix v0.51.36 (2026-05-06).
        // Switched everyMinute() + internal 21:33 check → native daily('21:33').
        // Saves ~1440 noop cron calls/day. Handler keeps internal time guard
        // (defense in depth: cron driver retry / timezone edge cases).
        $schedule->call(static fn() => (new \App\TaskHandlers\FoodAndWaterConsumptionHandler())->handle())
            ->daily('21:33')->singleInstance()->named('food-water.consumption');

        // Налоги — v0.51.95: stable. Switched everyMinute() + internal 03:00 check
        // → native daily('03:00'). Saves ~1440 noop cron calls/day.
        $schedule->call(static fn() => (new \App\TaskHandlers\TaxCollectionHandler())->handle())
            ->daily('03:00')->singleInstance()->named('tax.collection');

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
        // WORLD EVENTS — F7.3 cutover: 1 dispatcher замість 19 окремих
        // ============================================================
        //
        // EventActivationHandler — лишається (окрема відповідальність:
        // активувати нові події з пулу events).
        //
        // EventTickHandler — НОВИЙ. Замінив 19 окремих event.* schedules
        // (Hurricane, Snowfall, Fever, Volcanic, MeteorShower, Tremor, ...).
        // Внутрі — EventDispatcher::tickAllActive() читає active_events,
        // resolve через WorldEvents.php config + EffectResolver, dispatch
        // на 10 effect-strategy classes (Damage/Heal/Boost/...).
        //
        // Старі handler-файли в app/TaskHandlers/Events/*Handler.php
        // НЕ видалені — залишилися для швидкого rollback через git revert
        // (F7.10 cleanup видалить їх після prod-stabilization).
        //
        // Див. mmorpg-vault/lore/refactor/F7-Audit.md (Step F7.3).

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\EventActivationHandler())->handle())
            ->everyMinute()->singleInstance()->named('event.activation');

        $schedule->call(static fn() => (new \App\TaskHandlers\Events\EventTickHandler())->process())
            ->everyMinute()->singleInstance()->named('event.tick');

        // ============================================================
        // WORLD CONTENT — генерация объектов и NPC-спавн
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\Other\WorldObjectGeneratorHandler())->handle())
            ->everyMinute()->singleInstance()->named('world-objects.generate');

        $schedule->call(static fn() => (new \App\TaskHandlers\NPC\SpawnSandyWolfRaidersCron())->run())
            ->everyMinute()->singleInstance()->named('npc.sandy-wolves');

        // V25 (ADR-057) — спавн странствующих NPC-караванов до caravan.max_active.
        $schedule->call(static fn() => (new \App\TaskHandlers\NPC\SpawnCaravanCron())->run())
            ->everyMinute()->singleInstance()->named('npc.caravans');

        // ADR-089 Фаза 1 — спавн нейтральных NPC-странников до npc.wanderer_population
        // (killswitch npc.interaction_enabled → dormant = no-op).
        $schedule->call(static fn() => (new \App\TaskHandlers\NPC\WandererSpawnHandler())->run())
            ->everyMinute()->singleInstance()->named('npc.wanderers');

        // ADR-089 Phase 6 (контент-пасс) — фикс-ландмарк размещение именных NPC
        // (killswitch npc.named_placement_enabled → dormant = no-op).
        $schedule->call(static fn() => (new \App\TaskHandlers\NPC\NamedNpcPlacementHandler())->run())
            ->everyMinute()->singleInstance()->named('npc.named-placement');

        // W2 (ADR-058) — recharge дронов on_base (everyMinute, charge_rate × interval).
        $schedule->call(static fn() => (new \App\TaskHandlers\Drone\DroneRechargeCron())->run(1))
            ->everyMinute()->singleInstance()->named('drone.recharge');

        $schedule->call(static fn() => (new \App\TaskHandlers\NPC\AutoPveHandler())->run())
            ->everyMinute()->singleInstance()->named('npc.auto-pve');

        // ============================================================
        // QUESTS — handler сам проверяет прогресс игроков
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestExplore30CellsHandler())->handle())
            ->everyMinute()->singleInstance()->named('quest.explore-30');

        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestExploreAllBiomesHandler())->handle())
            ->everyMinute()->singleInstance()->named('quest.explore-biomes');

        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestExplore300CellsHandler())->handle())
            ->everyMinute()->singleInstance()->named('quest.explore-300');

        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestFirstAidkitBasicHandler())->handle())
            ->everyMinute()->singleInstance()->named('quest.first-aid-kit');

        // V12 (ADR-037): генерик-завершение data-driven квестов цепочек
        // (craft_item/explore_cells/char_level). discover_object — в StrategicLootHandler.
        $schedule->call(static fn() => (new \App\TaskHandlers\Quests\QuestObjectiveHandler())->handle())
            ->everyMinute()->singleInstance()->named('quest.objective');

        // ============================================================
        // SOCIAL
        // ============================================================

        $schedule->call(static fn() => (new \App\TaskHandlers\Other\FactionNotificationHandler())->handle())
            ->everyMinute()->singleInstance()->named('faction.notification');

        // ============================================================
        // ACHIEVEMENTS — W9 (ADR-066) state-driven cron-poll выдача
        // ============================================================
        // everyMinute: для каждого enabled-достижения set-based SQL находит выполнивших
        // критерий и ещё не получивших → выдаёт + батч-уведомление per-player. Killswitch
        // achievement.enabled (default OFF на ship W9 — no-op до активации в W10). Cap
        // achievement.max_awards_per_tick против notification-шторма при активации.
        $schedule->call(static fn() => (new \App\TaskHandlers\Achievements\AchievementCheckCron())->handle())
            ->everyMinute()->singleInstance()->named('achievement.check');

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

        // v0.51.43 — TTL cleanup для battle_logs (видаляє >90 днів,
        // ~23k rows/initial run, ~646 rows/day після стабілізації).
        $schedule->command('battles:cleanup')
            ->daily('03:35')->named('battle-logs.cleanup');

        // v0.51.113 — Endgame threshold check + scenario activation.
        // Daily 04:00 EEST. Idempotent (no-op якщо threshold ще не hit
        // або вже triggered). Перевіряє чи якась з 4 factions hit 75k
        // → triggers FINAL state + broadcast до всіх chars.
        $schedule->call(static fn() => (new \App\TaskHandlers\Endgame\EndgameThresholdHandler())->handle())
            ->daily('04:00')->singleInstance()->named('endgame.threshold-check');

        // S28 (ADR-032) — смена craft-сезона + анонс. Daily 00:00. Idempotent
        // (cache-state, first-run silent). Killswitch seasonal.enabled.
        $schedule->call(static fn() => (new \App\TaskHandlers\Other\SeasonalTransitionHandler())->handle())
            ->daily('00:00')->singleInstance()->named('seasonal.transition');

        // ADR-038 Фаза C — «Совет дня»: ежедневная авто-рассылка совета игрокам.
        // everyMinute + внутренние guard'ы (hour=tips.daily_hour + once/day cache).
        // Killswitch tips.daily_enabled. singleInstance против overlap при рассылке 364.
        $schedule->call(static fn() => (new \App\TaskHandlers\Tips\DailyTipBroadcastHandler())->handle())
            ->everyMinute()->singleInstance()->named('tips.daily-broadcast');

        // W18 (ADR-072) — PvP-ладдер: еженедельный топ + сброс сезона.
        // everyMinute + внутренние guard'ы (day/hour/once-week). Killswitch pvp.ladder.enabled
        // + pvp.ladder.broadcast_enabled (оба OFF dormant). singleInstance против overlap.
        $schedule->call(static fn() => (new \App\TaskHandlers\PVP\PvpLadderWeeklyBroadcastHandler())->handle())
            ->everyMinute()->singleInstance()->named('pvp-ladder.weekly-broadcast');
    }
}
