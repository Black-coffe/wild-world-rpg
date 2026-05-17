<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * v0.51.77 (CallbackqueryCommand decomp Step 1) — extract giant action→class
 * mapping array з CallbackqueryCommand::getActionHandler() у dedicated config.
 *
 * Pattern: GameBalance F2.10 — централізована конфіг,
 * можна override через .env для test routing rebalance.
 *
 * Source of truth до v0.51.76: CallbackqueryCommand::$mapping (lines 144-357).
 */
class CallbackRoutes extends BaseConfig
{
    /**
     * Exact-match action mapping. Key = first segment of callback_data
     * (everything before first underscore). Value = action handler class.
     *
     * @var array<string, class-string>
     */
    public array $exactRoutes = [
        // === StartGame flow ===
        'withoutTrainingStart'            => \App\Controllers\Telegram\Commands\Actions\StartGame\WithoutTrainingStartAction::class,
        'setCharacterName'                => \App\Controllers\Telegram\Commands\Actions\StartGame\SetCharacterNameAction::class,
        'autoGenerateName'                => \App\Controllers\Telegram\Commands\Actions\StartGame\AutoGenerateNameAction::class,
        'setName'                         => \App\Controllers\Telegram\Commands\Actions\StartGame\SetNameAction::class,
        'getTrainedStart'                 => \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStartAction::class,
        'getTrainedStart2'                => \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart2Action::class,
        'getTrainedStart3'                => \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart3Action::class,
        'getTrainedStart4'                => \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart4Action::class,
        'startAdventure'                  => \App\Controllers\Telegram\Commands\Actions\StartGame\StartAdventureAction::class,
        'exploreAreaTips'                 => \App\Controllers\Telegram\Commands\Actions\StartGame\ExploreAreaTipsAction::class,
        'moveNorthEastTips'               => \App\Controllers\Telegram\Commands\Actions\StartGame\MoveNorthEastTips::class,
        'gatherTips'                      => \App\Controllers\Telegram\Commands\Actions\StartGame\GatherTips::class,

        // === Core actions ===
        // ADR-019 cleanup-тег: стоячий «explore» (ExploreAction-шим + ExploreTheArea-задача +
        // CancelExplorationAction) удалён после дренажа in-flight задач. Вход в разведку = «Поход»
        // (callback `march`); кнопки, ранее звавшие `explore`, переведены на `march`.
        'characterActions'                => \App\Controllers\Telegram\Commands\Actions\CharacterGoActions::class,
        'move'                            => \App\Controllers\Telegram\Commands\Actions\MoveCharacterAction::class,
        'march'                           => \App\Controllers\Telegram\Commands\Actions\MarchAction::class,          // ADR-019 — «Поход»
        'cancelMarch'                     => \App\Controllers\Telegram\Commands\Actions\CancelMarchAction::class,    // ADR-019 — остановить поход
        'gather'                          => \App\Controllers\Telegram\Commands\Actions\GatherAction::class,
        'cancelGather'                    => \App\Controllers\Telegram\Commands\Actions\CancelGatherAction::class,
        'inventory'                       => \App\Controllers\Telegram\Commands\Actions\InventoryAction::class,
        'resourcesGathered'               => \App\Controllers\Telegram\Commands\Actions\ResourcesGatheredAction::class,
        // === Настройки (идея #14 — тумблер картинок) ===
        'settings'                        => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class,
        'mediaOff'                        => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class,
        'mediaOn'                         => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class,
        // 'character' route handled by inline shortcut у CallbackqueryCommand
        // (calls CharacterService::showCharacterInfo з equipment info — НЕ
        // CharacterAction). CharacterAction.php був dead code, видалено v0.51.79.
        'shop'                            => \App\Controllers\Telegram\Commands\Actions\ShopAction::class,

        // === Sell/Buy flow ===
        'sell'                            => \App\Controllers\Telegram\Commands\Actions\Sell\SellAction::class,
        'sellSelectRarity'                => \App\Controllers\Telegram\Commands\Actions\Sell\SellResourceAction::class,
        'buy'                             => \App\Controllers\Telegram\Commands\Actions\Sell\BuyResourceAction::class,
        'sellCraft'                       => \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftAction::class,
        'sellCraftList'                   => \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftItemListAction::class,
        'sellCraftItem'                   => \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftItemAction::class,
        'sellCraftConfirm'                => \App\Controllers\Telegram\Commands\Actions\Sell\SellCraftConfirmAction::class,
        'buyCraft'                        => \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftAction::class,
        'buyCraftList'                    => \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftItemListAction::class,
        'buyCraftItem'                    => \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftItemAction::class,
        'buyCraftConfirm'                 => \App\Controllers\Telegram\Commands\Actions\Sell\BuyCraftConfirmAction::class,

        // === Entertainment / games ===
        'entertainment'                   => \App\Controllers\Telegram\Commands\Actions\EntertaimentAction::class,
        'WheelOfFortune'                  => \App\Controllers\Telegram\Commands\Actions\Games\FortuneWheelAction::class,
        'GuessNumber'                     => \App\Controllers\Telegram\Commands\Actions\Games\GuessNumberAction::class,
        'RockPaperScissors'               => \App\Controllers\Telegram\Commands\Actions\Games\RockPaperScissorsAction::class,
        'events'                          => \App\Controllers\Telegram\Commands\Actions\EventAction::class,
        'finishAllTasks'                  => \App\Controllers\Telegram\Commands\Actions\FinishTaskAction::class,

        // === Crafting (Workbench Standard — F3.B5..B9 generics) ===
        'standardCraft'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\StandardCraftingAction::class,
        'robotsCraft2'                    => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotsCraft2Select::class,
        'robotExplorer'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotExplorer2Action::class,
        'robotGatherer'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotGatherer2Action::class,
        'teleportBeaconCraft2'            => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\TeleportBeaconCraft2Select::class,
        'teleportBeaconBasic2'            => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\TeleportBeaconBasic2Action::class,
        'startCraftTeleportBeaconBasic2'  => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\StartCraftTeleportBeaconBasic2Action::class,
        'teleportBackpack2'               => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\TeleportBackpack2Action::class,
        'startCraftTeleportBackpack2'     => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon\StartCraftTeleportBackpack2Action::class,
        'armorCraft2'                     => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorCraft2Select::class,
        'weaponsCraft2'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponsCraft2Select::class,
        'armorRaggedShirt'                => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorRaggedShirt2Action::class,
        'startCraftRaggedShirt2'          => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\StartCraftArmorRaggedShirt2Action::class,
        'armorDrifterClothes'             => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorDrifterClothes2Action::class,
        'startCraftDrifterClothes2'       => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\StartCraftArmorDrifterClothes2Action::class,
        'armorLeatherJacket'              => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorLeatherJacket2Action::class,
        'startCraftLeatherJacket2'        => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\StartCraftLeatherJacket2Action::class,
        'armorReinforcedLeather'          => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\ArmorReinforcedLeather2Action::class,
        'startCraftReinforcedLeather2'    => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor\StartCraftReinforcedLeather2Action::class,
        'craftMetalSpear'                 => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponMetalSpear2Action::class,
        'craftPipeGun'                    => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponPipeGun2Action::class,
        'craftWiredBat'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponWiredBat2Action::class,
        'craftCrossbowMk1'                => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Weapons\WeaponCrossbowMk1Action::class,

        // === Equipment ===
        'equipMenu'                       => \App\Controllers\Telegram\Commands\Profile\GearAction::class,
        'gearArmor'                       => \App\Controllers\Telegram\Commands\Profile\GearArmorAction::class,
        'gearWeapons'                     => \App\Controllers\Telegram\Commands\Profile\GearWeaponsAction::class,
        'gearWeaponDetail'                => \App\Controllers\Telegram\Commands\Profile\GearWeaponDetailAction::class,
        'toggleEquipWeapon'               => \App\Controllers\Telegram\Commands\Profile\ToggleEquipWeaponAction::class,
        'gearArmorDetail'                 => \App\Controllers\Telegram\Commands\Profile\GearArmorDetailAction::class,
        'toggleEquipArmor'                => \App\Controllers\Telegram\Commands\Profile\ToggleEquipArmorAction::class,

        // === Robots ===
        'AllRobots'                       => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\AllRobotsHandler::class,
        'activateRobot'                   => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\ActivateRobotHandler::class,
        'startRobotExplorer'              => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\StartRobotExplorationAction::class,
        'setCoordinatesRobotExplorer'     => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\SetCoordinatesRobotExplorerAction::class,
        'startRobotGatherer'              => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\StartRobotGatheringAction::class,

        // === Crafting (Workbench General) ===
        'generalCraft'                    => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\GeneralCraftingAction::class,
        'medicinesCraft1'                 => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\MedicalCraft1Action::class,
        'strengtheningElixir'             => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\StrengthElixirCraft1Action::class,
        'antiseptic'                      => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\AntisepticCraft1Action::class,
        'bandage'                         => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\BandageCraft1Action::class,
        'stimulator'                      => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\StimulatorCraft1Action::class,
        'painReliefPowder'                => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\PainReliefPowerCraft1Action::class,
        'sedative'                        => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\SedativeCraft1Action::class,
        'regenerator'                     => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\RegeneratorCraft1Action::class,
        'basicMedKit'                     => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical\BasicMedKitCraft1Action::class,
        'resourcesCrafting'               => \App\Controllers\Telegram\Commands\Actions\CraftedResourcesAction::class,
        'pharmacy'                        => \App\Controllers\Telegram\Commands\Actions\PharmacyAction::class,
        'usePharmacy'                     => \App\Controllers\Telegram\Commands\Actions\UsePharmacyAction::class,
        'componentsCraft'                 => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\ComponentsCraft1Select::class,
        'metalFragments'                  => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\MetalFragmentsCraft1Action::class,
        'fabric'                          => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\FabricCraft1Action::class,
        'stoneBlocks'                     => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\StoneBlocksCraft1Action::class,
        'fertilizer'                      => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\FertilizerCraft1Action::class,
        'woodMaterials'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\WoodMaterialsCraft1Action::class,
        'soil'                            => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\SoilCraft1Action::class,
        'charcoalBriquettes'              => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\CharcoalBriquettes1Action::class,
        'WorkbenchChoice'                 => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench\WorkbenchCraft1Select::class,
        'workbenchOne'                    => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench\WorkbenchOneAction::class,
        'glassBags'                       => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\GlassBagsCraft1Action::class,
        'electronicComponents'            => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\ElectronicComponentsCraft1Action::class,
        'wiring'                          => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\WiringCraft1Action::class,
        'tools'                           => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\ToolsCraft1Action::class,
        'lumberjackAxe'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\LumberjackAxeCraft1Action::class,
        'stonePickaxe'                    => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\StonePickaxeCraft1Action::class,
        'ironShovel'                      => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\IronShovelCraft1Action::class,
        'fishingRod'                      => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\FishingRodCraft1Action::class,
        'hoe'                             => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\HoeCraft1Action::class,
        'foldingKnife'                    => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\FoldingKnifeCraft1Action::class,
        'ironPickaxe'                     => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\IronPickaxeCraft1Action::class,
        'tireIron'                        => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools\TireIronCraft1Action::class,

        // === Quests ===
        'questInfo'                       => \App\Controllers\Telegram\Commands\Actions\Quest\QuestsInfo::class,
        'questAndTask'                    => \App\Controllers\Telegram\Commands\Actions\Quest\QuestAndTaskAction::class,
        'availableQuests'                 => \App\Controllers\Telegram\Commands\Actions\Quest\AvailableQuests::class,
        'completedQuests'                 => \App\Controllers\Telegram\Commands\Actions\Quest\CompletedQuests::class,
        'activeQuests'                    => \App\Controllers\Telegram\Commands\Actions\Quest\ActiveQuests::class,
        'questStartExplore30Cells'        => \App\Controllers\Telegram\Commands\Actions\Quest\QuestStartExplore30Cells::class,
        'questStartExplore300Cells'       => \App\Controllers\Telegram\Commands\Actions\Quest\QuestStartExplore300Cells::class,
        'questStartExploreAllBiomes'      => \App\Controllers\Telegram\Commands\Actions\Quest\QuestStartExploreAllBiomes::class,
        'questStartFirstAidkitBasic'      => \App\Controllers\Telegram\Commands\Actions\Quest\QuestStartFirstAidkitBasic::class,

        // === Faction ===
        'chooseFaction'                   => \App\Controllers\Telegram\Commands\Actions\Faction\ChooseFaction::class,

        // === Camp / Base management ===
        'entrench'                        => \App\Controllers\Telegram\Commands\Actions\Camp\EntrenchAction::class,
        'Camp'                            => \App\Controllers\Telegram\Commands\Actions\Camp\CampShowCreationAction::class,
        'Base'                            => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\ShowBaseInfoAction::class,
        'CampCreateConfirm'               => \App\Controllers\Telegram\Commands\Actions\Camp\CampCreateConfirmAction::class,
        'CancelCamp'                      => \App\Controllers\Telegram\Commands\Actions\Camp\CampCancelAction::class,
        'construction'                    => \App\Controllers\Telegram\Commands\Actions\Camp\DetailedBaseInfoAction::class,
        'TeleportToCamp'                  => \App\Controllers\Telegram\Commands\Actions\Camp\TeleportAction::class,
        'TeleportUse'                     => \App\Controllers\Telegram\Commands\Actions\Camp\TeleportUseAction::class,
        'DeleteBase'                     => \App\Controllers\Telegram\Commands\Actions\Camp\DeleteBaseAction::class,
        'Build'                           => \App\Controllers\Telegram\Commands\Actions\Camp\BuildListAction::class,
        'building'                        => \App\Controllers\Telegram\Commands\Actions\Camp\BuildingHandlerAction::class,
        // S1 (v0.51.182+): 12 legacy `build<Name>` / `actionNameFor<Name>` routes удалены, заменены
        // на единый generic preview-handler `genericBuildInfo_<Key>` (читает Config\Buildings).
        // Final-action маршрут `genericStartBuild_<Key>` существует с F2.1.
        'genericBuildInfo'                => \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingInfoAction::class,
        'genericStartBuild'               => \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingAction::class,
        'genericCraft'                    => \App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart::class,

        // S5b (v0.51.188+): Ремонт изношенных инструментов через GameSettings (ADR-024).
        // - repairToolsList: список изношенных инструментов с кнопкой Ремонт per item
        // - repair_<log_id>: 2-step ask (показывает стоимость через repair.cost_fraction)
        // - confirm_repair_<log_id>: списывает ресурсы, создаёт `repair` task → RepairCompletionHandler
        'repairToolsList'                 => \App\Controllers\Telegram\Commands\Actions\Craft\Repair\RepairToolsListAction::class,

        // === Objects ===
        'objectActionClosedWarehouse'     => \App\Controllers\Telegram\Commands\Actions\Objects\ObjectCloseWarehouseAction::class,

        // === Profile / Insurance ===
        'PersonalInsurance'               => \App\Controllers\Telegram\Commands\Profile\PersonalInsurance::class,
        'toggleInsurance'                 => \App\Controllers\Telegram\Commands\Profile\ToggleInsuranceAction::class,
        'calculateInsurance'              => \App\Controllers\Telegram\Commands\Profile\CalculateInsuranceAction::class,

        // === PvP ===
        'runAway'                         => \App\Controllers\Telegram\Commands\Actions\PVP\RunAwayAction::class,
        'attackPlayer'                    => \App\Controllers\Telegram\Commands\Actions\PVP\AttackPlayerAction::class,

        // === Teleport beacons ===
        'teleportBeacon'                  => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeacon::class,
        'teleportBeaconSet'               => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeaconSetAction::class,
        'teleportBeaconMove'              => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeaconMoveAction::class,
        'teleportBeaconMoveGo'            => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeaconMoveConfirmAction::class,
    ];

    /**
     * Wildcard routes — for callbacks matching `pattern*` semantic
     * (CallbackRouter syntax). Examples: 'move_dir_*' матчить move_dir_north,
     * 'eventPref_*' — eventPref_mute_1h.
     *
     * @var array<string, class-string>
     */
    public array $wildcardRoutes = [
        'move_dir_*'  => \App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction::class,
        'march_*'     => \App\Controllers\Telegram\Commands\Actions\MarchAction::class, // ADR-019: march_<dir>_<n>, march_go_*, march_more_*, march_resume
        'eventPref_*' => \App\Controllers\Telegram\Commands\Actions\EventPrefAction::class,
    ];

    /**
     * Prefix routes — for callbacks where action segment (first part) starts
     * with a known prefix (e.g. 'sellResource' → SellResourceAction для
     * 'sellResource', 'sellResourceCommon', 'sellResource_X' тощо).
     *
     * @var array<string, class-string>
     */
    public array $prefixRoutes = [
        'sellResource' => \App\Controllers\Telegram\Commands\Actions\Sell\SellResourceAction::class,
        // v0.51.129 (community idea #1) — cancel queued craft з refund ресурсів
        'cancelQueued' => \App\Controllers\Telegram\Commands\Actions\Craft\CancelQueuedCraftAction::class,
    ];

    /**
     * Resolve action handler class for given action name (first segment).
     * Falls back to prefix matching if no exact match.
     */
    public function resolve(string $action): ?string
    {
        if (isset($this->exactRoutes[$action])) {
            return $this->exactRoutes[$action];
        }

        foreach ($this->prefixRoutes as $prefix => $handler) {
            if (str_starts_with($action, $prefix)) {
                return $handler;
            }
        }

        return null;
    }
}
