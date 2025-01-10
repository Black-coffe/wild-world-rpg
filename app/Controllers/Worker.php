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

        // Вызов метода для выполнения не связанных с задачами действий
        $this->processUnlinkedActions();

        // Вызов TaxCollectionHandler только в 04:00 по серверному времени
        (new TaxCollectionHandler())->handle();
    }

    protected function handleTask($task, $taskDetails)
    {
        $handlerClassName = $this->getHandlerClassName($taskDetails['name']);

        if (class_exists($handlerClassName)) {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'handle')) {
                $handler->handle($task);
            }
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
        (new \App\TaskHandlers\GreenhouseProductionHandler())->handle();
        (new \App\TaskHandlers\GymProductionHandler())->handle();
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

    protected $taskHandlerMap = [
        'ExploreTheArea' => 'ExplorationTaskHandler',
        'Gather' => 'GatherTaskHandler',
        'craftStrengtheningElixir' => 'CraftCompletionStrengthElixirHandler',
        'craftAntiseptic' => 'CraftCompletionAntisepticHandler',
        'craftBandage' => 'CraftCompletionBandageHandler',
        'craftPainReliefPower' => 'CraftCompletionPainReliefPowerHandler',
        'craftSedative' => 'CraftCompletionSedativeHandler',
        'craftStimulator' => 'CraftCompletionStimulatorHandler',
        'craftRegenerator' => 'CraftCompletionRegeneratorHandler',
        'craftBasicMedKit' => 'CraftCompletionBasicMedKitHandler',
        'craftLumberjackAxe' => 'CraftCompletionLumberjackAxeHandler',
        'craftStonePickaxe' => 'CraftCompletionStonePickaxeHandler',
        'craftIronShovel' => 'CraftCompletionIronShovelHandler',
        'craftFishingRod' => 'CraftCompletionFishingRodHandler',
        'craftHoe' => 'CraftCompletionHoeHandler',
        'craftFoldingKnife' => 'CraftCompletionFoldingKnifeHandler',
        'craftIronPickaxe' => 'CraftCompletionIronPickaxeHandler',
        'craftTireIron' => 'CraftCompletionTireIronHandler',
        'craftMetalFragments' => 'CraftCompletionMetalFragmentsHandler',
        'craftFabric' => 'CraftCompletionFabricHandler',
        'craftStoneBlocks' => 'CraftCompletionStoneBlocksHandler',
        'craftFertilizer' => 'CraftCompletionFertilizerHandler',
        'craftWoodMaterials' => 'CraftCompletionWoodMaterialsHandler',
        'craftSoil' => 'CraftCompletionSoilHandler',
        'buildingManualPump' => 'BuiltCompletionHandPumpHandler',
        'craftCharcoalBriquettes' => 'CraftCompletionCharcoalBriquettesHandler',
        'buildBlastFurnace' => 'BuiltCompletionBlastFurnaceHandler',
        'buildWorkshop' => 'BuiltCompletionWorkshopHandler',
        'startBuildWarehouse' => 'BuiltCompletionWarehouseHandler',
        'craftWorkbenchOne' => 'CraftCompletionWorkbenchOneHandler',
        'craftGlassBags' => 'CraftCompletionGlassBagsHandler',
        'startBuildSolarStation' => 'BuiltCompletionSolarStationHandler',
        'startBuildGreenhouse' => 'BuiltCompletionGreenhouseHandler',
        'startBuildGym' => 'BuiltCompletionGymHandler',
        'startBuildLab' => 'BuiltCompletionLabHandler',
        'startBuildRoboticsWorkshop' => 'BuiltCompletionRoboticsWorkshopHandler',
        'craftRobotExplorer' => 'CraftCompletionRobotExplorerHandler',
        'craftRobotGatherer' => 'CraftCompletionRobotGathererHandler',
        'ExploringLocationRobot' => 'CompleteRobotExplorationHandler',
        // Другие соответствия названий задач и классов обработчиков
    ];

    protected function getHandlerClassName($taskName)
    {
        if (array_key_exists($taskName, $this->taskHandlerMap)) {
            return 'App\\TaskHandlers\\' . $this->taskHandlerMap[$taskName];
        } else {
            return str_replace(' ', '', ucwords($taskName)) . 'Handler';
        }
    }
}
