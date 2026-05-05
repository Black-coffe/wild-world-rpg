<?php
namespace App\Controllers;

use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;
use App\TaskHandlers\TaxCollectionHandler;

class Worker extends Controller
{
    protected $characterTaskModel;
    protected $taskModel;

    public function __construct()
    {
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel = new TaskModel();
    }

    public function processTasks()
    {
        // F0.1 — flock-mutex на весь Worker.
        // Cron каждую минуту дёргает этот endpoint. Если предыдущий запуск
        // не уложился в минуту — следующий нашёл бы те же character_tasks
        // в статусе 'in_work' и обработал их повторно (двойная выдача наград).
        // LOCK_NB = non-blocking: если уже занято — тихо выходим.
        $lockPath = WRITEPATH . 'worker.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if ($lockHandle === false) {
            log_message('error', "[Worker] Не удалось открыть lock-файл {$lockPath}");
            return;
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            log_message('info', '[Worker] Предыдущий запуск ещё работает — пропускаем тик.');
            fclose($lockHandle);
            return;
        }
        // Lock освобождается автоматически при завершении процесса
        // (на Linux — также если PHP-FPM убивает воркер).

        try {
            $now = date('Y-m-d H:i:s');
            $activeTasks = $this->characterTaskModel
                ->where('status', 'in_work')
                ->where('end_time <', $now)
                ->findAll();

            foreach ($activeTasks as $task) {
                $taskDetails = $this->taskModel->find($task['task_id']);
                if ($taskDetails) {
                    $this->handleTask($task, $taskDetails);
                }
            }

            // Выполняем различные периодические действия (не связанные напрямую с задачами):
            $this->processUnlinkedActions();

            // Вызов TaxCollectionHandler (налоги) — например, раз в сутки или каждый раз
            (new TaxCollectionHandler())->handle();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    protected function handleTask($task, $taskDetails)
    {
        $handlerClassName = $this->getHandlerClassName($taskDetails['name']);

        if (!class_exists($handlerClassName)) {
            log_message('error', "[Worker] Handler-класс не найден: {$handlerClassName} (task #{$task['id']})");
            return;
        }

        // F0.3 — atomic claim задачи перед обработкой.
        // Помечаем задачу как 'completed' ДО запуска handler'а одним атомарным
        // UPDATE с условием WHERE status='in_work'. Если другой Worker уже
        // забрал задачу — affected rows = 0, мы её пропускаем.
        // На исключение в handler'е ставим статус 'interrupted' для расследования.
        $db = \Config\Database::connect();
        $claimed = $db->table('character_tasks')
            ->where('id', $task['id'])
            ->where('status', 'in_work')
            ->update(['status' => 'completed', 'updated_at' => date('Y-m-d H:i:s')]);

        if (!$claimed || $db->affectedRows() === 0) {
            log_message('info', "[Worker] task #{$task['id']} уже взят другим воркером — пропуск.");
            return;
        }

        try {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'handle')) {
                $handler->handle($task);
            }
        } catch (\Throwable $e) {
            // Откатываем статус, чтобы handler можно было перезапустить вручную
            // или расследовать. Не бросаем дальше — иначе один упавший handler
            // ломает весь tick.
            $db->table('character_tasks')
                ->where('id', $task['id'])
                ->update(['status' => 'interrupted', 'updated_at' => date('Y-m-d H:i:s')]);
            log_message('error', "[Worker] Handler {$handlerClassName} упал на task #{$task['id']}: " . $e->getMessage());
        }
    }

    protected function processUnlinkedActions()
    {
        // Здесь можно вызвать любые методы других классов, которые должны выполняться по расписанию
        $this->executeHealthRegeneration();
        $this->resourceBankUpdate();
        $this->foodAndWaterConsumption();
        $this->characterData();
        $this->eventActivation();
        $this->epidemicHandler();
        $this->tremorHandler();
        $this->feverHandler();
        $this->volcanicEruptionHandler();
        $this->flashForestFireHandler();
        $this->hurricaneHandler();
        $this->sandStormHandler();
        $this->meteorShowerHandler();
        $this->dungeonOpeningHandler();
        $this->mountainEchoHandler();
        $this->nightAttacksHandler();
        $this->goldVeinHandler();
        $this->snowFallHandler();
        $this->northernLightsHandler();
        $this->shootingStarHandler();
        $this->geothermalSpringsHandler();
        $this->mirageOasisHandler();
        $this->worldObjectGeneratorHandler();
        $this->questExplore30CellsHandler();
        $this->questExploreAllBiomesHandler();
        $this->questExplore300CellsHandler();
        $this->questFirstAidkitBasicHandler();
        $this->factionNotificationHandler();
        $this->lowHealthWarningHandler();
        $this->DeathRouletteHandler();

        (new \App\TaskHandlers\GreenhouseProductionHandler())->handle();
        (new \App\TaskHandlers\Built\GymProductionHandler())->handle();
        (new \App\TaskHandlers\Built\HandPumpProductionHandler())->handle();
        (new \App\TaskHandlers\NPC\SpawnSandyWolfRaidersCron())->run();
        (new \App\TaskHandlers\NPC\AutoPveHandler())->run();
    }

    protected function DeathRouletteHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\DeathRouletteHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function lowHealthWarningHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\LowHealthWarningHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function factionNotificationHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Other\\FactionNotificationHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function questFirstAidkitBasicHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Quests\\QuestFirstAidkitBasicHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function questExplore300CellsHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Quests\\QuestExplore300CellsHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function questExploreAllBiomesHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Quests\\QuestExploreAllBiomesHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function questExplore30CellsHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Quests\\QuestExplore30CellsHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function worldObjectGeneratorHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Other\\WorldObjectGeneratorHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function mirageOasisHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\MirageOasisHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function geothermalSpringsHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\GeothermalSpringsHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function shootingStarHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\ShootingStarHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function northernLightsHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\NorthernLightsHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function snowFallHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\SnowFallHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function goldVeinHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\GoldVeinHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function nightAttacksHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\NightAttacksHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function mountainEchoHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\MountainEchoHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function dungeonOpeningHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\DungeonOpeningHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function meteorShowerHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\MeteorShowerHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function sandStormHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\SandStormHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function hurricaneHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\HurricaneHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function flashForestFireHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\FlashForestFireHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function volcanicEruptionHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\VolcanicEruptionHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function feverHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\FeverHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function epidemicHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\EpidemicHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function tremorHandler()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\TremorHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function executeHealthRegeneration()
    {
        $handlerClassName = 'App\\TaskHandlers\\HealthRegenerationHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function characterData()
    {
        $handlerClassName = 'App\\TaskHandlers\\CharacterDataHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function foodAndWaterConsumption()
    {
        $handlerClassName = 'App\\TaskHandlers\\FoodAndWaterConsumptionHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function eventActivation()
    {
        $handlerClassName = 'App\\TaskHandlers\\Events\\EventActivationHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    protected function resourceBankUpdate()
    {
        $handlerClassName = 'App\\TaskHandlers\\ResourceBankUpdateHandler';
        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'process')) {
                $handler->process();
            }
        }
    }

    /**
     * Сопоставляем name из таблицы tasks -> обработчику
     */
    protected $taskHandlerMap = [
        'ExploreTheArea' => 'ExplorationTaskHandler',
        'Gather' => 'GatherTaskHandler',
        // F3.B5 (v0.21.0) cutover: все 8 medical крафтов идут через
        // GenericCraftCompletionHandler. Рецепт читается из
        // app/Config/CraftRecipes.php (ключ task_settings.recipe).
        // Action-side контракт обеспечивает GenericCraftActionStart.
        'craftStrengtheningElixir' => 'Craft\GenericCraftCompletionHandler',
        'craftAntiseptic'          => 'Craft\GenericCraftCompletionHandler',
        'craftBandage'             => 'Craft\GenericCraftCompletionHandler',
        'craftPainReliefPower'     => 'Craft\GenericCraftCompletionHandler',
        'craftSedative'            => 'Craft\GenericCraftCompletionHandler',
        'craftStimulator'          => 'Craft\GenericCraftCompletionHandler',
        'craftRegenerator'         => 'Craft\GenericCraftCompletionHandler',
        'craftBasicMedKit'         => 'Craft\GenericCraftCompletionHandler',
        'craftLumberjackAxe' => 'Craft\CraftCompletionLumberjackAxeHandler',
        'craftStonePickaxe' => 'Craft\CraftCompletionStonePickaxeHandler',
        'craftIronShovel' => 'Craft\CraftCompletionIronShovelHandler',
        'craftFishingRod' => 'Craft\CraftCompletionFishingRodHandler',
        'craftHoe' => 'Craft\CraftCompletionHoeHandler',
        'craftFoldingKnife' => 'Craft\CraftCompletionFoldingKnifeHandler',
        'craftIronPickaxe' => 'Craft\CraftCompletionIronPickaxeHandler',
        'craftTireIron' => 'Craft\CraftCompletionTireIronHandler',
        // F3.B6 (v0.22.0) cutover: 10 components крафтов через GenericCraftCompletionHandler.
        'craftMetalFragments'       => 'Craft\GenericCraftCompletionHandler',
        'craftFabric'               => 'Craft\GenericCraftCompletionHandler',
        'craftStoneBlocks'          => 'Craft\GenericCraftCompletionHandler',
        'craftFertilizer'           => 'Craft\GenericCraftCompletionHandler',
        'craftWoodMaterials'        => 'Craft\GenericCraftCompletionHandler',
        'craftSoil'                 => 'Craft\GenericCraftCompletionHandler',
        'buildingManualPump' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'craftCharcoalBriquettes'   => 'Craft\GenericCraftCompletionHandler', // F3.B6
        'buildBlastFurnace' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'buildWorkshop' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildWarehouse' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'craftWorkbenchOne' => 'Craft\CraftCompletionWorkbenchOneHandler',
        'craftGlassBags'            => 'Craft\GenericCraftCompletionHandler', // F3.B6
        'startBuildSolarStation' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildGreenhouse' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildGym' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildLab' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildRoboticsWorkshop' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'craftRobotExplorer' => 'Craft\CraftCompletionRobotExplorerHandler',
        'craftRobotGatherer' => 'Craft\CraftCompletionRobotGathererHandler',
        'ExploringLocationRobot' => 'CompleteRobotExplorationHandler',
        'GatheringResourcesRobot' => 'CompleteRobotGatheringHandler',
        'BaseRelocation' => 'Built\BaseRelocationCompletionHandler',
        'FullRelocation' => 'Built\BaseFullRelocationCompletionHandler',
        'craftWiring'               => 'Craft\GenericCraftCompletionHandler', // F3.B6
        'craftElectronicComponents' => 'Craft\GenericCraftCompletionHandler', // F3.B6
        'startBuildTeleportationCenter' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'craftTeleportBeaconBasic' => 'Craft\WorkbenchStandard\CraftCompletionTeleportBeaconBasicHandler',
        'craftTeleportBackpack' => 'Craft\WorkbenchStandard\CraftCompletionTeleportBackpackHandler',
        'startBuildArsenal' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildCommunicationTower' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'craftArmorRaggedShirt' => 'Craft\WorkbenchStandard\Armor\CraftCompletionRaggedShirtHandler',
        'craftArmorDrifterClothes' => 'Craft\WorkbenchStandard\Armor\CraftCompletionDrifterClothesHandler',
        'craftLeatherJacket' => 'Craft\WorkbenchStandard\Armor\CraftCompletionLeatherJacketHandler',
        'craftReinforcedLeatherJacket' => 'Craft\WorkbenchStandard\Armor\CraftCompletionReinforcedLeatherHandler',
        'craftMetalSpear' => 'Craft\WorkbenchStandard\Weapons\CraftCompletionMetalSpearHandler',
        'craftPipeGun' => 'Craft\WorkbenchStandard\Weapons\CraftCompletionPipeGunHandler',
        'craftWiredBat' => 'Craft\WorkbenchStandard\Weapons\CraftCompletionWiredBatHandler',
        'craftCrossbowMk1' => 'Craft\WorkbenchStandard\Weapons\CraftCompletionCrossbowMk1Handler',

        // Другие соответствия
    ];

    protected function getHandlerClassName($taskName)
    {
        if (array_key_exists($taskName, $this->taskHandlerMap)) {
            return 'App\\TaskHandlers\\' . $this->taskHandlerMap[$taskName];
        } else {
            // На случай, если нет в map, делаем "слепить" имя "SomeTaskName" => "App\TaskHandlers\SomeTaskNameHandler"
            return 'App\\TaskHandlers\\' . str_replace(' ', '', ucwords($taskName)) . 'Handler';
        }
    }
}
