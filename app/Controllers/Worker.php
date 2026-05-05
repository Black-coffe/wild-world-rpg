<?php
namespace App\Controllers;

use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;

/**
 * Главный dispatcher для character_tasks (gather/explore/build/craft completion).
 *
 * Запускается каждую минуту через codeigniter4/tasks scheduler
 * (см. app/Config/Tasks.php → 'worker.character-tasks'). Параллельно с
 * scheduler'ом legacy curl-cron (CloudPanel) дёргает GET /cbgsvd-d-dw/worker
 * — strangler-режим F4.1 до cutover в F4.2.
 *
 * Recurring handlers (HealthRegen, EventActivation, FoodAndWater, Greenhouse
 * production и др.) живут в Tasks.php, НЕ здесь. F4.1 удалил их wrapper-методы
 * из этого контроллера (~300 LOC blob processUnlinkedActions).
 *
 * Защита от race-conditions:
 *   - F0.1: flock() mutex на worker.lock (никогда два processTasks параллельно)
 *   - F0.3: atomic claim через UPDATE ... WHERE status='in_work' (если другой
 *     воркер успел — affected_rows=0, пропускаем)
 *   - Tasks.php singleInstance(2*MINUTE) сверху лока — двойная защита
 */
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

    /**
     * Сопоставляем name из таблицы tasks -> обработчику character_tasks completion.
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
        // F3.B7 (v0.23.0) cutover: 8 tools крафтов через GenericCraftCompletionHandler.
        'craftLumberjackAxe'        => 'Craft\GenericCraftCompletionHandler',
        'craftStonePickaxe'         => 'Craft\GenericCraftCompletionHandler',
        'craftIronShovel'           => 'Craft\GenericCraftCompletionHandler',
        'craftFishingRod'           => 'Craft\GenericCraftCompletionHandler',
        'craftHoe'                  => 'Craft\GenericCraftCompletionHandler',
        'craftFoldingKnife'         => 'Craft\GenericCraftCompletionHandler',
        'craftIronPickaxe'          => 'Craft\GenericCraftCompletionHandler',
        'craftTireIron'             => 'Craft\GenericCraftCompletionHandler',
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
        'craftWorkbenchOne'         => 'Craft\GenericCraftCompletionHandler', // F3.B8
        'craftGlassBags'            => 'Craft\GenericCraftCompletionHandler', // F3.B6
        'startBuildSolarStation' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildGreenhouse' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildGym' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildLab' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'startBuildRoboticsWorkshop' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'craftRobotExplorer'        => 'Craft\GenericCraftCompletionHandler', // F3.B8
        'craftRobotGatherer'        => 'Craft\GenericCraftCompletionHandler', // F3.B8
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
        // F3.B9 (v0.25.0) cutover: 4 weapon крафтов через GenericCraftCompletionHandler
        // (output_type=weapon dispatch на characters_weapons + updateStrengthAndAgility).
        'craftMetalSpear'           => 'Craft\GenericCraftCompletionHandler',
        'craftPipeGun'              => 'Craft\GenericCraftCompletionHandler',
        'craftWiredBat'             => 'Craft\GenericCraftCompletionHandler',
        'craftCrossbowMk1'          => 'Craft\GenericCraftCompletionHandler',
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
