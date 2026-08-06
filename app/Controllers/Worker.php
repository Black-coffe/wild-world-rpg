<?php
namespace App\Controllers;

use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Services\Handlers\HandlerRegistry;
use App\TaskHandlers\Contracts\TaskHandlerInterface;
use CodeIgniter\Controller;
use Throwable;

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

        // ADR-148 (сигнал доставки) — фоновые уведомления о завершении задач шлются отсюда,
        // и молчаливая потеря здесь опаснее: игрок даже не знает, что чего-то ждал.
        // Ставим пробу один раз на прогон, ДО первого хендлера.
        \App\Services\Logging\TelegramDeliveryProbe::install();

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
        $handlerKey       = $taskDetails['handler_key'] ?? null;
        $handlerClassName = $this->getHandlerClassName($taskDetails['name'], is_string($handlerKey) ? $handlerKey : null);

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

        // ADR-148 (расширение охвата) — firehose фоновых завершений задач игрока.
        // character_id из character_tasks = надёжная связь; имя задачи = action_name.
        $charId   = is_numeric($task['character_id'] ?? null) ? (int) $task['character_id'] : null;
        $taskName = is_string($taskDetails['name'] ?? null) ? $taskDetails['name'] : '';

        // ADR-148 (сигнал доставки) — СВОЁ окно на задачу: один прогон завершает N задач в
        // одном процессе, и без сброса провал первой приписался бы всем следующим.
        $logger = \App\Services\Logging\PlayerActionLogger::current();
        $logger->openDeliveryWindow();

        try {
            $handler = new $handlerClassName();
            if (method_exists($handler, 'handle')) {
                $handler->handle($task);
            }
            // Хендлер отработал — но дошло ли уведомление? Провал одной отправки при удачном
            // фолбэке остаётся 'ok' (вердикт считает сам логгер).
            $undelivered = $logger->deliveryFailureText();
            $logger->recordTaskCompletion(
                $charId,
                $taskName,
                $undelivered === null ? 'ok' : 'undelivered',
                $undelivered
            );
        } catch (\Throwable $e) {
            // Откатываем статус, чтобы handler можно было перезапустить вручную
            // или расследовать. Не бросаем дальше — иначе один упавший handler
            // ломает весь tick.
            $db->table('character_tasks')
                ->where('id', $task['id'])
                ->update(['status' => 'interrupted', 'updated_at' => date('Y-m-d H:i:s')]);
            \App\Services\Logging\PlayerActionLogger::current()->recordTaskCompletion($charId, $taskName, 'error', $e->getMessage());
            log_message('error', "[Worker] Handler {$handlerClassName} упал на task #{$task['id']}: " . $e->getMessage());
        }
    }

    /**
     * Phase B3 (ADR-023, 2026-05-14) — task.name → handler-key (зареєстрований
     * `#[HandlerKey]` атрибут на handler-класі). Resolve через
     * {@see HandlerRegistry} → fallback на legacy `$taskHandlerMap` нижче.
     *
     * Anti-drift guard: `TaskHandlerRegistryConsistencyTest` гарантує, що для
     * кожного запису у legacy `$taskHandlerMap` існує запис у цьому masь,
     * і registry-resolved клас === legacy-resolved клас.
     *
     * @var array<string, string>
     */
    protected $taskHandlerKeyMap = [
        // Concrete task-handler'и (1 task.name → 1 спеціалізований клас).
        'Marching'        => 'marching',
        'Gather'          => 'gather',
        'BaseRelocation'  => 'base_relocation',
        'FullRelocation'  => 'base_full_relocation',
        'ExploringLocationRobot'  => 'complete_robot_exploration',
        'GatheringResourcesRobot' => 'complete_robot_gathering',
        // Generic craft handler — покриває ~34 task.name'и через CraftRecipes lookup.
        'craftStrengtheningElixir' => 'generic_craft',
        'craftAntiseptic'          => 'generic_craft',
        'craftBandage'             => 'generic_craft',
        'craftPainReliefPower'     => 'generic_craft',
        'craftSedative'            => 'generic_craft',
        'craftStimulator'          => 'generic_craft',
        'craftRegenerator'         => 'generic_craft',
        'craftBasicMedKit'         => 'generic_craft',
        'craftLumberjackAxe'        => 'generic_craft',
        'craftStonePickaxe'         => 'generic_craft',
        'craftIronShovel'           => 'generic_craft',
        'craftFishingRod'           => 'generic_craft',
        'craftHoe'                  => 'generic_craft',
        'craftFoldingKnife'         => 'generic_craft',
        'craftIronPickaxe'          => 'generic_craft',
        'craftTireIron'             => 'generic_craft',
        'craftMetalFragments'       => 'generic_craft',
        'craftFabric'               => 'generic_craft',
        'craftStoneBlocks'          => 'generic_craft',
        'craftFertilizer'           => 'generic_craft',
        'craftWoodMaterials'        => 'generic_craft',
        'craftSoil'                 => 'generic_craft',
        'craftCharcoalBriquettes'   => 'generic_craft',
        'craftWorkbenchOne'         => 'generic_craft',
        'craftGlassBags'            => 'generic_craft',
        'craftRobotExplorer'        => 'generic_craft',
        'craftRobotGatherer'        => 'generic_craft',
        'craftWiring'               => 'generic_craft',
        'craftElectronicComponents' => 'generic_craft',
        'craftMeteorShelter'        => 'generic_craft',  // v0.51.127 community idea #2 (latent gap caught by B6 backfill 2026-05-14)
        'craftMetalSpear'           => 'generic_craft',
        'craftPipeGun'              => 'generic_craft',
        'craftWiredBat'             => 'generic_craft',
        'craftCrossbowMk1'          => 'generic_craft',
        'craftProfessionalWorkbench'=> 'generic_craft',  // S16 (v0.51.198) — T3 verstack, ADR-026
        // S17 (v0.51.199) — 5 T3 weapon crafts через ProfessionalWorkbench (ADR-026 reusable pattern).
        // Recipes в CraftRecipes.php, gating через recipe.required_crafted_items.
        'craftGaussPistol'          => 'generic_craft',
        'craftRailCarbineVikhr'     => 'generic_craft',
        'craftIonDestabilizer'      => 'generic_craft',
        'craftFlamethrowerAid'      => 'generic_craft',
        'craftExoRailgunBehemoth'   => 'generic_craft',
        // S25 (v0.51.205) — 4 faction-unique weapons (output_type=weapon, ADR-029).
        'craftBunkerRifle'          => 'generic_craft',
        'craftTechnoBeamShotgun'    => 'generic_craft',
        'craftGhostCityKnife'       => 'generic_craft',
        'craftFarmersHarvestScythe' => 'generic_craft',
        // S18 (v0.51.200) — 4 T3 armor crafts (output_type=outfit, characters_outfits).
        'craftTacticalArmorSuit'    => 'generic_craft',
        'craftExoskeletonStrekoza'  => 'generic_craft',
        'craftTitanPowerArmor'      => 'generic_craft',
        'craftTeslaShardArmor'      => 'generic_craft',
        // S19 (v0.51.201) — 3 T3 medical crafts (output_type=crafted_item, type='drug').
        'craftSyntheticMedicine'    => 'generic_craft',
        'craftEmergencyTransfusion' => 'generic_craft',
        'craftSurgicalKit'          => 'generic_craft',
        // S20 (v0.51.202) — 3 T3 utility tool crafts (output_type=crafted_item, type='tool').
        'craftDiamondPickaxe'       => 'generic_craft',
        'craftSapperShovel'         => 'generic_craft',
        'craftGoldenHoe'            => 'generic_craft',
        // V6 (ADR-033) — 4 seed crafts (output_type=resource → character_resources).
        'craftBerrySeeds'           => 'generic_craft',
        'craftMushroomSeeds'        => 'generic_craft',
        'craftFruitSeeds'           => 'generic_craft',
        'craftCropSeeds'            => 'generic_craft',
        // V6 (ADR-033) — посадка культуры (активное земледелие, отдельный handler).
        'plantCrop'                 => 'planting',
        // V8 (vNext) — 5 cooking crafts (Костёр, output_type=crafted_item type='drug').
        'craftMushroomSoup'         => 'generic_craft',
        'craftBerryBrew'            => 'generic_craft',
        'craftBakedFruit'           => 'generic_craft',
        'craftGrainPorridge'        => 'generic_craft',
        'craftHeartyStew'           => 'generic_craft',
        // V10 (vNext) — 2 консервы (Костёр, shelf-stable).
        'craftStewPreserve'         => 'generic_craft',
        'craftDryRation'            => 'generic_craft',
        // Generic building handler — покриває 12 task.name'ів через Buildings config.
        'buildingManualPump'             => 'generic_building',
        'buildBlastFurnace'              => 'generic_building',
        'buildWorkshop'                  => 'generic_building',
        'startBuildWarehouse'            => 'generic_building',
        'startBuildSolarStation'         => 'generic_building',
        'startBuildGreenhouse'           => 'generic_building',
        'startBuildGym'                  => 'generic_building',
        'startBuildLab'                  => 'generic_building',
        'startBuildRoboticsWorkshop'     => 'generic_building',
        'startBuildTeleportationCenter'  => 'generic_building',
        'startBuildArsenal'              => 'generic_building',
        'startBuildCommunicationTower'   => 'generic_building',
        // Спеціалізовані craft handler'и (НЕ generic — окремий клас).
        'craftTeleportBeaconBasic'      => 'craft_teleport_beacon_basic',
        'craftTeleportBackpack'         => 'craft_teleport_backpack',
        'craftPortableTeleport'         => 'craft_portable_teleport',
        'craftArmorRaggedShirt'         => 'craft_armor_ragged_shirt',
        'craftArmorDrifterClothes'      => 'craft_armor_drifter_clothes',
        'craftLeatherJacket'            => 'craft_leather_jacket',
        'craftReinforcedLeatherJacket'  => 'craft_reinforced_leather',
    ];

    /**
     * Сопоставляем name из таблицы tasks -> обработчику character_tasks completion.
     */
    protected $taskHandlerMap = [
        // ADR-019 cleanup-тег: 'ExploreTheArea' => 'ExplorationTaskHandler' удалён вместе с
        // handler'ом после дренажа in-flight задач. Стоячий explore заменён «Походом» (Marching).
        'Marching' => 'MarchingTaskHandler', // ADR-019 §3 — «Поход» (цепочка 1-клеточных задач, тик = 1 клетка)
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
        'craftMeteorShelter'        => 'Craft\GenericCraftCompletionHandler', // v0.51.127 latent gap fix (B6 backfill 2026-05-14)
        'startBuildTeleportationCenter' => 'Built\GenericBuildingCompletionHandler', // F3.B4
        'craftTeleportBeaconBasic' => 'Craft\WorkbenchStandard\CraftCompletionTeleportBeaconBasicHandler',
        'craftTeleportBackpack' => 'Craft\WorkbenchStandard\CraftCompletionTeleportBackpackHandler',
        'craftPortableTeleport' => 'Craft\WorkbenchStandard\CraftCompletionPortableTeleportHandler',
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
        'craftProfessionalWorkbench'=> 'Craft\GenericCraftCompletionHandler', // S16 (v0.51.198) — T3 verstack
        // S17 (v0.51.199) — 5 T3 weapons (output_type=weapon dispatch на characters_weapons).
        'craftGaussPistol'          => 'Craft\GenericCraftCompletionHandler',
        'craftRailCarbineVikhr'     => 'Craft\GenericCraftCompletionHandler',
        'craftIonDestabilizer'      => 'Craft\GenericCraftCompletionHandler',
        'craftFlamethrowerAid'      => 'Craft\GenericCraftCompletionHandler',
        'craftExoRailgunBehemoth'   => 'Craft\GenericCraftCompletionHandler',
        // S25 (v0.51.205) — 4 faction-unique weapons (output_type=weapon, ADR-029).
        'craftBunkerRifle'          => 'Craft\GenericCraftCompletionHandler',
        'craftTechnoBeamShotgun'    => 'Craft\GenericCraftCompletionHandler',
        'craftGhostCityKnife'       => 'Craft\GenericCraftCompletionHandler',
        'craftFarmersHarvestScythe' => 'Craft\GenericCraftCompletionHandler',
        // S18 (v0.51.200) — 4 T3 armor (output_type=outfit dispatch на characters_outfits).
        'craftTacticalArmorSuit'    => 'Craft\GenericCraftCompletionHandler',
        'craftExoskeletonStrekoza'  => 'Craft\GenericCraftCompletionHandler',
        'craftTitanPowerArmor'      => 'Craft\GenericCraftCompletionHandler',
        'craftTeslaShardArmor'      => 'Craft\GenericCraftCompletionHandler',
        // S19 (v0.51.201) — 3 T3 medical (output_type=crafted_item dispatch на crafted_items_log).
        'craftSyntheticMedicine'    => 'Craft\GenericCraftCompletionHandler',
        'craftEmergencyTransfusion' => 'Craft\GenericCraftCompletionHandler',
        'craftSurgicalKit'          => 'Craft\GenericCraftCompletionHandler',
        // S20 (v0.51.202) — 3 T3 utility tools (output_type=crafted_item dispatch на crafted_items_log).
        'craftDiamondPickaxe'       => 'Craft\GenericCraftCompletionHandler',
        'craftSapperShovel'         => 'Craft\GenericCraftCompletionHandler',
        'craftGoldenHoe'            => 'Craft\GenericCraftCompletionHandler',
        // V6 (ADR-033) — 4 seed crafts (output_type=resource dispatch на character_resources).
        'craftBerrySeeds'           => 'Craft\GenericCraftCompletionHandler',
        'craftMushroomSeeds'        => 'Craft\GenericCraftCompletionHandler',
        'craftFruitSeeds'           => 'Craft\GenericCraftCompletionHandler',
        'craftCropSeeds'            => 'Craft\GenericCraftCompletionHandler',
        // V6 (ADR-033) — посадка культуры (активное земледелие).
        'plantCrop'                 => 'Farm\PlantCropCompletionHandler',
        // V8 (vNext) — 5 cooking crafts (Костёр, output_type=crafted_item type='drug').
        'craftMushroomSoup'         => 'Craft\GenericCraftCompletionHandler',
        'craftBerryBrew'            => 'Craft\GenericCraftCompletionHandler',
        'craftBakedFruit'           => 'Craft\GenericCraftCompletionHandler',
        'craftGrainPorridge'        => 'Craft\GenericCraftCompletionHandler',
        'craftHeartyStew'           => 'Craft\GenericCraftCompletionHandler',
        // V10 (vNext) — 2 консервы (shelf-stable).
        'craftStewPreserve'         => 'Craft\GenericCraftCompletionHandler',
        'craftDryRation'            => 'Craft\GenericCraftCompletionHandler',
    ];

    protected function getHandlerClassName($taskName, ?string $explicitHandlerKey = null)
    {
        // Phase B6 (ADR-023) — DB-level handler_key priority. Якщо у tasks-row
        // adminом встановлено handler_key — використовуємо його напряму через
        // registry. Це новий "перший" шлях dispatch'а, він обходить
        // tasks.name → key mapping повністю.
        if ($explicitHandlerKey !== null && $explicitHandlerKey !== '') {
            $registry = $this->handlerRegistry();
            if ($registry !== null) {
                $entry = $registry->getByKey($explicitHandlerKey);
                if ($entry !== null && is_subclass_of($entry->class, TaskHandlerInterface::class)) {
                    log_message(
                        'debug',
                        "[Worker] task '{$taskName}' explicit handler_key='{$explicitHandlerKey}' → {$entry->class} (via DB handler_key)"
                    );
                    return $entry->class;
                }
                log_message(
                    'warning',
                    "[Worker] task '{$taskName}': explicit handler_key='{$explicitHandlerKey}' not found in HandlerRegistry — falling back to name→key map"
                );
            }
        }

        // Phase B3 (ADR-023): друга спроба — через name→key map + HandlerRegistry
        // (`#[HandlerKey]` атрибут на handler-класах). Якщо реєстр недоступний
        // або в ньому немає запису для handler-key — fallback на legacy
        // FQCN-map нижче. Dual-write вікно B3–B7 за ADR-023.
        if (array_key_exists($taskName, $this->taskHandlerKeyMap)) {
            $handlerKey = $this->taskHandlerKeyMap[$taskName];
            $registry   = $this->handlerRegistry();
            if ($registry !== null) {
                $entry = $registry->getByKey($handlerKey);
                if ($entry !== null && is_subclass_of($entry->class, TaskHandlerInterface::class)) {
                    log_message(
                        'debug',
                        "[Worker] task '{$taskName}' → key '{$handlerKey}' → {$entry->class} (via HandlerRegistry)"
                    );
                    return $entry->class;
                }
                log_message(
                    'warning',
                    "[Worker] task '{$taskName}': key '{$handlerKey}' not found in HandlerRegistry — falling back to legacy FQCN map"
                );
            }
        }

        if (array_key_exists($taskName, $this->taskHandlerMap)) {
            return 'App\\TaskHandlers\\' . $this->taskHandlerMap[$taskName];
        }

        // На случай, если нет в map, делаем "слепить" имя "SomeTaskName" => "App\TaskHandlers\SomeTaskNameHandler"
        return 'App\\TaskHandlers\\' . str_replace(' ', '', ucwords($taskName)) . 'Handler';
    }

    /**
     * Lazy-доступ до registry через service() helper. Якщо service container
     * недоступний (rannі CLI bootstrap, минімальні unit-тести без CI4
     * environment) — повертаємо null, далі піде legacy FQCN fallback.
     */
    private function handlerRegistry(): ?HandlerRegistry
    {
        if (!function_exists('service')) {
            return null;
        }
        try {
            $candidate = service('handlerRegistry');
        } catch (Throwable) {
            return null;
        }
        return $candidate instanceof HandlerRegistry ? $candidate : null;
    }
}
