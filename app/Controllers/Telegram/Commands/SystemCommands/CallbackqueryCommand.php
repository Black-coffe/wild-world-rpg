<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Player\CharacterService;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Class CallbackqueryCommand
 * Обработчик всех callback_data, приходящих от inline-кнопок Telegram.
 * Служит точкой входа, чтобы определить, какой Action-Class вызывать.
 */
class CallbackqueryCommand extends SystemCommand
{
    protected $name = 'callbackquery';

    public function execute(): ServerResponse
    {
        $callbackQuery = $this->getCallbackQuery();
        $callbackData  = $callbackQuery->getData();

        // Простейший кейс: разбираем callback_data
        // если это что-то вроде: "character_XYZ", "move_dir_north", "explore", etc.
        $parts  = explode('_', $callbackData);
        $action = $parts[0]; // Берём первую часть до "_"

        // Пример: если callback_data = "move_dir_north",
        // тогда $action = "move", но у нас условие: strpos(...)
        // См. ниже проверку на "move_dir_".
        // Или, если callback_data = "characterActions", $action = "characterActions" (целиком).

        // Если action==='character' — показываем персонажа через сервис
        if ($action === 'character') {
            $chatId = $callbackQuery->getMessage()->getChat()->getId();
            $from   = $callbackQuery->getFrom();
            $tid    = $from->getId();

            $userModel  = new TelegramUserModel();
            $userRow    = $userModel->where('telegram_id', $tid)->first();
            if (!$userRow) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'User not found!',
                ]);
            }

            $charModel    = new CharacterModel();
            $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
            if (!$characterRow) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Character not found!',
                ]);
            }

            $charService = new CharacterService();
            return $charService->showCharacterInfo($chatId, $characterRow);
        }

        // F2.5 wire-in: wildcard routing через CallbackRouter.
        // Pattern 'move_dir_*' матчит move_dir_north, move_dir_south, move_dir_east, move_dir_west.
        // По мере мигрирования других if(strpos)-блоков сюда же подключим.
        $router = (new \App\Services\Telegram\CallbackRouter())
            ->register('move_dir_*', \App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction::class);
        $routedClass = $router->resolve($callbackData);
        if ($routedClass !== null && class_exists($routedClass)) {
            $handler = new $routedClass($callbackQuery);
            return $handler->handle();
        }

        if (strpos($callbackData, 'pollVote_') === 0) {
            // Ожидаем формат: pollVote_{pollId}_{answerId}
            $parts = explode('_', $callbackData);
            // [0] => pollVote
            // [1] => pollId
            // [2] => answerId
            if (count($parts) >= 3) {
                $pollId   = (int) $parts[1];
                $answerId = (int) $parts[2];

                // Подключаем наш новый класс
                $handlerClass = \App\Controllers\Telegram\Commands\Actions\Poll\PollVoteAction::class;
                if (class_exists($handlerClass)) {
                    $handler = new $handlerClass($this->getCallbackQuery());
                    return $handler->handleVote($pollId, $answerId);
                }
            }
            return Request::emptyResponse();
        }

        // В методе execute() или getActionHandler():
        if (strpos($callbackData, 'upgrade_building_') === 0) {
            // Шаг 1: показать требования и спросить подтверждение
            $handlerClass = \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction::class;
            if (class_exists($handlerClass)) {
                $handler = new $handlerClass($this->getCallbackQuery());
                return $handler->askForUpgrade(); // Вызовем отдельный метод
            }
        }

        if (strpos($callbackData, 'confirm_upgrade_building_') === 0) {
            // Шаг 2: действительно списываем и повышаем уровень
            $handlerClass = \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction::class;
            if (class_exists($handlerClass)) {
                $handler = new $handlerClass($this->getCallbackQuery());
                return $handler->confirmUpgrade();
            }
        }

        if (strpos($callbackData, 'StartRelocationConfirm_') === 0) {
            // 1) Закроем "часики"
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
            ]);

            $cmd = new \App\Controllers\Telegram\Commands\BaseShiftingCommand(
                $this->telegram,
                null // можно и $this->getUpdate()
            );
            // вызываем метод
            return $cmd->handleCallback($callbackQuery);

        }

        // Иначе — прочие action
        $handlerClass = $this->getActionHandler($action);
        if ($handlerClass && class_exists($handlerClass)) {
            $handler = new $handlerClass($callbackQuery);
            return $handler->handle();
        } else {
            return Request::emptyResponse();
        }
    }

    protected function getActionHandler($action)
    {
        $mapping = [
            'withoutTrainingStart' =>   \App\Controllers\Telegram\Commands\Actions\StartGame\WithoutTrainingStartAction::class,
            'setCharacterName' =>       \App\Controllers\Telegram\Commands\Actions\StartGame\SetCharacterNameAction::class,
            'autoGenerateName' =>       \App\Controllers\Telegram\Commands\Actions\StartGame\AutoGenerateNameAction::class,
            'setName' =>                \App\Controllers\Telegram\Commands\Actions\StartGame\SetNameAction::class,
            'getTrainedStart' =>        \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStartAction::class,
            'getTrainedStart2' =>        \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart2Action::class,
            'getTrainedStart3' =>        \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart3Action::class,
            'getTrainedStart4' =>        \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart4Action::class,
            'startAdventure' =>          \App\Controllers\Telegram\Commands\Actions\StartGame\StartAdventureAction::class,
            'exploreAreaTips' =>        \App\Controllers\Telegram\Commands\Actions\StartGame\ExploreAreaTipsAction::class,
            'moveNorthEastTips' =>      \App\Controllers\Telegram\Commands\Actions\StartGame\MoveNorthEastTips::class,
            'gatherTips' =>             \App\Controllers\Telegram\Commands\Actions\StartGame\GatherTips::class,
            'explore' =>                \App\Controllers\Telegram\Commands\Actions\ExploreAction::class,
            'characterActions' =>       \App\Controllers\Telegram\Commands\Actions\CharacterGoActions::class,
            'cancelExploration' =>      \App\Controllers\Telegram\Commands\Actions\CancelExplorationAction::class,
            'move' =>                   \App\Controllers\Telegram\Commands\Actions\MoveCharacterAction::class,
            'gather' =>                 \App\Controllers\Telegram\Commands\Actions\GatherAction::class,
            'cancelGather' =>           \App\Controllers\Telegram\Commands\Actions\CancelGatherAction::class,
            'inventory' =>              \App\Controllers\Telegram\Commands\Actions\InventoryAction::class,
            'resourcesGathered' =>      \App\Controllers\Telegram\Commands\Actions\ResourcesGatheredAction::class,
            'character' =>              \App\Controllers\Telegram\Commands\Actions\CharacterAction::class,
            'shop' =>                   \App\Controllers\Telegram\Commands\Actions\ShopAction::class,
            'sell' =>                   \App\Controllers\Telegram\Commands\Actions\Sell\SellAction::class,
            'sellSelectRarity' =>       \App\Controllers\Telegram\Commands\Actions\Sell\SellResourceAction::class,
            'buy' =>                    \App\Controllers\Telegram\Commands\Actions\Sell\BuyResourceAction::class,
            'sellCraft' =>              \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftAction::class,
            'sellCraftList' =>          \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftItemListAction::class,
            'sellCraftItem' =>          \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftItemAction::class,
            'sellCraftConfirm' =>       \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftConfirmAction::class,
            'buyCraft' =>               \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftAction::class,
            'buyCraftList' =>           \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftItemListAction::class,
            'buyCraftItem' =>           \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftItemAction::class,
            'buyCraftConfirm' =>        \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftConfirmAction::class,

            'entertainment' =>          \App\Controllers\Telegram\Commands\Actions\EntertaimentAction::class,
            'WheelOfFortune' =>         \App\Controllers\Telegram\Commands\Actions\Games\FortuneWheelAction::class,
            'GuessNumber' =>            \App\Controllers\Telegram\Commands\Actions\Games\GuessNumberAction::class,
            'RockPaperScissors' =>      \App\Controllers\Telegram\Commands\Actions\Games\RockPaperScissorsAction::class,
            'events' =>                 \App\Controllers\Telegram\Commands\Actions\EventAction::class,
            'finishAllTasks' =>         \App\Controllers\Telegram\Commands\Actions\FinishTaskAction::class,
            'standardCraft' =>          \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\StandardCraftingAction::class,
            'robotsCraft2' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotsCraft2Select::class,
            'robotExplorer' =>          \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotExplorer2Action::class,
            'craftRobotExplorer2' =>    \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\StartCraftRobotExplorer2Action::class,
            'robotGatherer' =>          \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotGatherer2Action::class,
            'craftRobotGatherer2' =>    \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\StartCraftRobotGatherer2Action::class,
            'teleportBeaconCraft2' =>    \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\TeleportBeaconCraft2Select::class,
            'teleportBeaconBasic2' =>    \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\TeleportBeaconBasic2Action::class,
            'startCraftTeleportBeaconBasic2' =>    \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\StartCraftTeleportBeaconBasic2Action::class,
            'teleportBackpack2' =>      \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\TeleportBackpack2Action::class,
            'startCraftTeleportBackpack2' =>      \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\StartCraftTeleportBackpack2Action::class,
            'armorCraft2' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorCraft2Select::class,
            'weaponsCraft2' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponsCraft2Select::class,
            'armorRaggedShirt' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorRaggedShirt2Action::class,
            'startCraftRaggedShirt2' =>     \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\StartCraftArmorRaggedShirt2Action::class,
            'armorDrifterClothes' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorDrifterClothes2Action::class,
            'startCraftDrifterClothes2' =>     \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\StartCraftArmorDrifterClothes2Action::class,
            'armorLeatherJacket' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorLeatherJacket2Action::class,
            'startCraftLeatherJacket2' =>     \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\StartCraftLeatherJacket2Action::class,
            'armorReinforcedLeather' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorReinforcedLeather2Action::class,
            'startCraftReinforcedLeather2' =>     \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\StartCraftReinforcedLeather2Action::class,
            'craftMetalSpear' =>            \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponMetalSpear2Action::class,
            'startCraftMetalSpear' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\StartCraftMetalSpear2Action::class,
            'craftPipeGun' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponPipeGun2Action::class,
            'startCraftPipeGun' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\StartCraftPipeGun2Action::class,
            'craftWiredBat' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponWiredBat2Action::class,
            'startCraftWiredBat' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\StartCraftWiredBat2Action::class,
            'craftCrossbowMk1' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponCrossbowMk1Action::class,
            'startCraftCrossbowMk1' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\StartCraftCrossbowMk1Action::class,
            'equipMenu' =>              \App\Controllers\Telegram\Commands\Profile\GearAction::class,
            'gearArmor' =>              \App\Controllers\Telegram\Commands\Profile\GearArmorAction::class,
            'gearWeapons' =>              \App\Controllers\Telegram\Commands\Profile\GearWeaponsAction::class,
            'gearWeaponDetail' =>              \App\Controllers\Telegram\Commands\Profile\GearWeaponDetailAction::class,
            'toggleEquipWeapon' =>              \App\Controllers\Telegram\Commands\Profile\ToggleEquipWeaponAction::class,
            'gearArmorDetail' =>              \App\Controllers\Telegram\Commands\Profile\GearArmorDetailAction::class,
            'toggleEquipArmor' =>              \App\Controllers\Telegram\Commands\Profile\ToggleEquipArmorAction::class,

            'AllRobots' =>              \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\AllRobotsHandler::class,
            'activateRobot' =>          \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\ActivateRobotHandler::class,
            'startRobotExplorer' =>     \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\StartRobotExplorationAction::class,
            'setCoordinatesRobotExplorer' =>     \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\SetCoordinatesRobotExplorerAction::class,
            'startRobotGatherer' =>     \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\StartRobotGatheringAction::class,

            'generalCraft' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\GeneralCraftingAction::class,
            'medicinesCraft1' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\MedicalCraft1Action::class,
            'strengtheningElixir' =>    \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\StrengthElixirCraft1Action::class,
            // F3.B5 (v0.21.0): action-start всех 8 medical крафтов (включая
            // craftStrengtheningElixir) мигрирован в GenericCraftActionStart
            // через callback `genericCraft_<RecipeKey>_<qty>`.
            // Старые callback'и (craftBandage, craftAntisepticCraft1, craftSedative,
            // craftStimulator, craftPainReliefPower, craftStrengtheningElixir,
            // craftRegenerator, craftBasicMedKit) удалены вместе с legacy *CraftActionStart.
            // Info-screen экраны (antiseptic, bandage, sedative, stimulator,
            // painReliefPowder, strengtheningElixir, regenerator, basicMedKit)
            // остаются — они показывают карточку предмета и кнопки крафта.
            'antiseptic' =>             \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\AntisepticCraft1Action::class,
            'bandage' =>                \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\BandageCraft1Action::class,
            'stimulator' =>             \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\StimulatorCraft1Action::class,
            'painReliefPowder' =>       \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\PainReliefPowerCraft1Action::class,
            'sedative' =>               \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\SedativeCraft1Action::class,
            'regenerator' =>            \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\RegeneratorCraft1Action::class,
            'basicMedKit' =>            \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\BasicMedKitCraft1Action::class,
            'resourcesCrafting' =>      \App\Controllers\Telegram\Commands\Actions\CraftedResourcesAction::class,
            'pharmacy' =>               \App\Controllers\Telegram\Commands\Actions\PharmacyAction::class,
            'usePharmacy' =>            \App\Controllers\Telegram\Commands\Actions\UsePharmacyAction::class,
            // F3.B6 (v0.22.0): action-start всех 10 components крафтов мигрирован
            // в GenericCraftActionStart через callback `genericCraft_<RecipeKey>_<qty>`.
            // Старые callback'и (craftWiring, craftFabric, craftSoil,
            // craftElectronicComponents, craftStoneBlocks, craftMetalFragments,
            // craftFertilizer, craftWoodMaterials, craftCharcoalBriquettes,
            // craftGlassBags) удалены вместе с legacy *CraftActionStart.
            // Info-screen экраны остаются — они показывают карточку предмета.
            'componentsCraft' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\ComponentsCraft1Select::class,
            'metalFragments' =>         \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\MetalFragmentsCraft1Action::class,
            'fabric' =>                 \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\FabricCraft1Action::class,
            'stoneBlocks' =>            \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\StoneBlocksCraft1Action::class,
            'fertilizer' =>             \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\FertilizerCraft1Action::class,
            'woodMaterials' =>          \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\WoodMaterialsCraft1Action::class,
            'soil' =>                   \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\SoilCraft1Action::class,
            'charcoalBriquettes' =>     \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\CharcoalBriquettes1Action::class,
            'WorkbenchChoice' =>        \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench\WorkbenchCraft1Select::class,
            'workbenchOne' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench\WorkbenchOneAction::class,
            'craftWorkbenchOne' =>      \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench\StartCraftWorkbenchOneAction::class,
            'glassBags' =>              \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\GlassBagsCraft1Action::class,
            'electronicComponents' =>   \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\ElectronicComponentsCraft1Action::class,
            'wiring' =>                 \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\WiringCraft1Action::class,

            // F3.B7 (v0.23.0): action-start всех 8 tools крафтов мигрирован
            // в GenericCraftActionStart через `genericCraft_<RecipeKey>_<qty>`.
            // Старые callback'и (craftStonePickaxe, craftIronShovel,
            // craftIronPickaxe, craftLumberjackAxeCraft1, craftFishingRod,
            // craftHoe, craftFoldingKnife, craftTireIron) удалены вместе
            // с legacy *CraftActionStart. Info-screens сохранены.
            'tools' =>                  \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\ToolsCraft1Action::class,
            'lumberjackAxe' =>          \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\LumberjackAxeCraft1Action::class,
            'stonePickaxe' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\StonePickaxeCraft1Action::class,
            'ironShovel' =>             \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\IronShovelCraft1Action::class,
            'fishingRod' =>             \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\FishingRodCraft1Action::class,
            'hoe' =>                    \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\HoeCraft1Action::class,
            'foldingKnife' =>           \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\FoldingKnifeCraft1Action::class,
            'ironPickaxe' =>            \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\IronPickaxeCraft1Action::class,
            'tireIron' =>               \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\TireIronCraft1Action::class,
            'questInfo' =>              \App\Controllers\Telegram\Commands\Actions\Quest\QuestsInfo::class,
            'questAndTask' =>          \App\Controllers\Telegram\Commands\Actions\Quest\QuestAndTaskAction::class,
            'availableQuests' =>          \App\Controllers\Telegram\Commands\Actions\Quest\AvailableQuests::class,
            'completedQuests' =>          \App\Controllers\Telegram\Commands\Actions\Quest\CompletedQuests::class,
            'activeQuests' =>          \App\Controllers\Telegram\Commands\Actions\Quest\ActiveQuests::class,
            'questStartExplore30Cells' => \App\Controllers\Telegram\Commands\Actions\Quest\QuestStartExplore30Cells::class,
            'questStartExplore300Cells' => \App\Controllers\Telegram\Commands\Actions\Quest\QuestStartExplore300Cells::class,
            'questStartExploreAllBiomes' => \App\Controllers\Telegram\Commands\Actions\Quest\QuestStartExploreAllBiomes::class,
            'questStartFirstAidkitBasic' => \App\Controllers\Telegram\Commands\Actions\Quest\QuestStartFirstAidkitBasic::class,

            'chooseFaction' =>              \App\Controllers\Telegram\Commands\Actions\Faction\ChooseFaction::class,
            'entrench' =>                   \App\Controllers\Telegram\Commands\Actions\Camp\EntrenchAction::class,
            'Camp'                          => \App\Controllers\Telegram\Commands\Actions\Camp\CampShowCreationAction::class,
            'Base' =>                       \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\ShowBaseInfoAction::class,
            'CampCreateConfirm'             => \App\Controllers\Telegram\Commands\Actions\Camp\CampCreateConfirmAction::class,
            'CancelCamp' =>                 \App\Controllers\Telegram\Commands\Actions\Camp\CampCancelAction::class,
            'construction' =>               \App\Controllers\Telegram\Commands\Actions\Camp\DetailedBaseInfoAction::class,
            'TeleportToCamp' =>             \App\Controllers\Telegram\Commands\Actions\Camp\TeleportAction::class,
            'TeleportUse' =>                \App\Controllers\Telegram\Commands\Actions\Camp\TeleportUseAction::class,
            'DeleteBase' =>                 \App\Controllers\Telegram\Commands\Actions\Camp\DeleteBaseAction::class,
            'Build' =>                      \App\Controllers\Telegram\Commands\Actions\Camp\BuildListAction::class,
            'buildHandPump' =>              \App\Controllers\Telegram\Commands\Actions\Camp\HandPumpConstruction::class,
            // F3.B2 (v0.18.0): startBuildHandPump → genericStartBuild_HandPump
            'buildBlastFurnace' =>          \App\Controllers\Telegram\Commands\Actions\Camp\BlastFurnaceConstruction::class,
            // F3.B1 (v0.17.0): startBuildBlastFurnace → genericStartBuild_BlastFurnace (Buildings.php config)
            'buildWorkshop' =>              \App\Controllers\Telegram\Commands\Actions\Camp\WorkshopConstruction::class,
            // F3.B1 (v0.17.0): startBuildWorkshop → genericStartBuild_Workshop
            'buildWarehouse' =>             \App\Controllers\Telegram\Commands\Actions\Camp\BuildWarehouseConstruction::class,
            // F3.B1 (v0.17.0): startBuildWarehouse → genericStartBuild_Warehouse
            'buildSolarStation' =>          \App\Controllers\Telegram\Commands\Actions\Camp\BuildSolarStationConstruction::class,
            // F3.B1 (v0.17.0): startBuildSolarStation → genericStartBuild_SolarStation
            'buildGreenhouse' =>            \App\Controllers\Telegram\Commands\Actions\Camp\BuildGreenHouseConstruction::class,
            // F3.B2 (v0.18.0): startBuildGreenhouse → genericStartBuild_Greenhouse
            'buildGym' =>                   \App\Controllers\Telegram\Commands\Actions\Camp\BuildGymConstruction::class,
            // F3.B2 (v0.18.0): startBuildGym → genericStartBuild_Gym
            'buildLaboratory' =>            \App\Controllers\Telegram\Commands\Actions\Camp\BuildLabConstruction::class,
            // F3.B1 (v0.17.0): startBuildLab → genericStartBuild_Laboratory
            'buildRoboticsWorkshop' =>      \App\Controllers\Telegram\Commands\Actions\Camp\BuildRoboticsWorkshopConstruction::class,
            // F3.B2 (v0.18.0): startBuildRoboticsWorkshop → genericStartBuild_RoboticsWorkshop
            'building' =>                   \App\Controllers\Telegram\Commands\Actions\Camp\BuildingHandlerAction::class,
            'buildTeleportationCenter' =>   \App\Controllers\Telegram\Commands\Actions\Camp\BuildTeleportationCenterConstruction::class,
            // F3.B2 (v0.18.0): startBuildTeleportCenter → genericStartBuild_TeleportationCenter
            'actionNameForArsenal' =>       \App\Controllers\Telegram\Commands\Actions\Camp\BuildArsenalConstruction::class,
            // F2.1 cutover (v0.3.0): Arsenal confirm-кнопка теперь шлёт
            // 'genericStartBuild_Arsenal' и попадает сюда. Legacy
            // StartBuildArsenalConstruction.php удалён — на любой rollback
            // через deploy/rollback.sh старая версия вернётся за <1 сек.
            'genericStartBuild'    =>       \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingAction::class,
            // F3.B5 (v0.21.0): generic action-start для крафтов
            // (callback `genericCraft_<RecipeKey>_<qty>`).
            'genericCraft'         =>       \App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart::class,
            'actionNameForCommunicationTower' => \App\Controllers\Telegram\Commands\Actions\Camp\BuildCommunicationTowerConstruction::class,
            // F3.B3-mini (v0.19.0): startBuildCommunicationTower → genericStartBuild_CommunicationTower

            'objectActionClosedWarehouse' =>  \App\Controllers\Telegram\Commands\Actions\Objects\ObjectCloseWarehouseAction::class,

            'PersonalInsurance' =>          \App\Controllers\Telegram\Commands\Profile\PersonalInsurance::class,
            'toggleInsurance' =>            \App\Controllers\Telegram\Commands\Profile\ToggleInsuranceAction::class,
            'calculateInsurance' =>         \App\Controllers\Telegram\Commands\Profile\CalculateInsuranceAction::class,

            'runAway' =>                    \App\Controllers\Telegram\Commands\Actions\PVP\RunAwayAction::class,
            'attackPlayer' =>               \App\Controllers\Telegram\Commands\Actions\PVP\AttackPlayerAction::class,
            'teleportBeacon' =>               \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeacon::class,
            'teleportBeaconSet' =>           \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeaconSetAction::class,
            'teleportBeaconMove' =>           \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeaconMoveAction::class,
            'teleportBeaconMoveGo' =>           \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeaconMoveConfirmAction::class,
        ];

        // Проверяем, начинается ли $action с 'sellResource'
        if (strpos($action, 'sellResource') === 0) {
            return \App\Controllers\Telegram\Commands\Actions\Sell\SellResourceAction::class;
        }

        return $mapping[$action] ?? null;
    }

}
