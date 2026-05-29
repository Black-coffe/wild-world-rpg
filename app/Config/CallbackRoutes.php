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
        // W7a (ADR-065): расширение Robi-chain 4 → 7 шагов под killswitch onboarding.robi_extended.enabled.
        'getTrainedStart5'                => \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart5Action::class,
        'getTrainedStart6'                => \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart6Action::class,
        'getTrainedStart7'                => \App\Controllers\Telegram\Commands\Actions\StartGame\GetTrainingStart7Action::class,
        'startAdventure'                  => \App\Controllers\Telegram\Commands\Actions\StartGame\StartAdventureAction::class,
        // W7b (ADR-065 Part 2) — каталог «📚 Что нового». Вход с экрана Перс + промо в Шаге 7/7.
        // Топики — через prefixRoute `whatsNew` (callback `whatsNew_<topic_key>`).
        'whatsNewCatalog'                 => \App\Controllers\Telegram\Commands\Actions\WhatsNew\WhatsNewCatalogAction::class,
        // W10 (ADR-066) — экран «🏅 Достижения» (вход с карточки Перс при killswitch on).
        'achievements'                    => \App\Controllers\Telegram\Commands\Actions\Achievements\AchievementsAction::class,
        'exploreAreaTips'                 => \App\Controllers\Telegram\Commands\Actions\StartGame\ExploreAreaTipsAction::class,
        'moveNorthEastTips'               => \App\Controllers\Telegram\Commands\Actions\StartGame\MoveNorthEastTips::class,
        'gatherTips'                      => \App\Controllers\Telegram\Commands\Actions\StartGame\GatherTips::class,

        // === Core actions ===
        // ADR-019 cleanup-тег: стоячий «explore» (ExploreAction-шим + ExploreTheArea-задача +
        // CancelExplorationAction) удалён после дренажа in-flight задач. Вход в разведку = «Поход»
        // (callback `march`); кнопки, ранее звавшие `explore`, переведены на `march`.
        'characterActions'                => \App\Controllers\Telegram\Commands\Actions\CharacterGoActions::class,
        // V16 (ADR-047) — крафт-специализация: меню выбора ветки.
        'specialization'                  => \App\Controllers\Telegram\Commands\Actions\Specialization\SpecializationAction::class,
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
        // ADR-038 Фаза C — тумблер «Совет дня»
        'dailyTipsOn'                     => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class,
        'dailyTipsOff'                    => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class,
        // W17 (ADR-071) — тумблер «открыт к дуэлям».
        'duelsOpenOn'                     => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class,
        'duelsOpenOff'                    => \App\Controllers\Telegram\Commands\Actions\SettingsAction::class,
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
        // S27 — сводный экран очереди крафта (active + queued).
        'craftQueue'                      => \App\Controllers\Telegram\Commands\Actions\Craft\ShowCraftQueueAction::class,
        // S28 — меню сезонного крафта (рецепты текущего сезона).
        'seasonalCraft'                   => \App\Controllers\Telegram\Commands\Actions\Craft\Seasonal\SeasonalCraftSelect::class,
        // V8 — меню готовки на костре (блюда из фарм-урожая).
        'cook'                            => \App\Controllers\Telegram\Commands\Actions\Craft\Cooking\CampfireCookingSelect::class,
        // V6 (ADR-033) — активное земледелие (грядки теплицы). Семена craftable
        // через genericCraft_<SeedKey>; посадка — отдельная цепочка.
        'plantSeedMenu'                   => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Greenhouse\SeedSelectAction::class,
        'plantSeedPreview'                => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Greenhouse\SeedPlantPreviewAction::class,
        'plantSeedStart'                  => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Greenhouse\PlantCropActionStart::class,
        'robotsCraft2'                    => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotsCraft2Select::class,
        'robotExplorer'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotExplorer2Action::class,
        'robotGatherer'                   => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotGatherer2Action::class,
        'robotScout'                      => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotScout2Action::class,
        'robotIndustrial'                 => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots\RobotIndustrial2Action::class,
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
        // V19 (ADR-050) — ремонт роботов (восстановление durability). Ключ = сегмент до 1-го `_`,
        // поэтому robotRepair / robotRepairConfirm не коллизят.
        'robotRepair'                     => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\RobotRepairAction::class,
        'robotRepairConfirm'              => \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\RobotRepairConfirmAction::class,

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
        'workbenchProfessional'           => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Workbench\WorkbenchProfessionalAction::class, // S16 (v0.51.198) — T3 verstack info screen; S17 dual-mode: post-build → T3 craft menu
        // S17 (v0.51.199) — T3 weapons (5 рецептов, ADR-026 Фаза 4 2/5)
        'craftWeaponsT3Select'            => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\WeaponsCraftT3Select::class,
        // S18 (v0.51.200) — T3 armor (4 рецепта, ADR-026 Фаза 4 3/5)
        'craftArmorT3Select'              => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\ArmorCraftT3Select::class,
        // S19 (v0.51.201) — T3 medical (3 рецепта, ADR-026 Фаза 4 4/5)
        'craftMedicalT3Select'            => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\MedicalCraftT3Select::class,
        // S20 (v0.51.202) — T3 utility tools (3 рецепта, ADR-026 Фаза 4 5/5 — закрывает фазу)
        'craftUtilityT3Select'            => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\UtilityCraftT3Select::class,
        // S25 (v0.51.205) — faction-unique weapons (4, ADR-029, Фаза 5 — закрывает фазу)
        'craftFactionWeaponsSelect'       => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\FactionWeaponsCraftSelect::class,
        // V14 (ADR-046) — faction-unique armor (4, сиблинг S25; reuse craftPreviewT3Armor_ prefix)
        'craftFactionArmorSelect'         => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\FactionArmorCraftSelect::class,
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
        // V20 (ADR-051) — фракц-проект (общий вклад → фракц-buff).
        'factionProject'                  => \App\Controllers\Telegram\Commands\Actions\Faction\FactionProjectAction::class,
        'factionProjectLocked'            => \App\Controllers\Telegram\Commands\Actions\Faction\FactionProjectLockedAction::class,
        // W1+W2 (ADR-058) — Drone-recon. info-callback из CraftRecipes['DroneScout']
        // → DroneScoutCraftInfoAction (preview-экран с чек-листом ✅/❌ требований +
        // «🛠 Крафтить» когда всё доступно). CLAUDE.md §🎮 UX-DISCOVERABILITY fix:
        // ранее этот ключ был указан в recipe info_callback, но не зарегистрирован
        // здесь, и в StandardCraftingAction не было кнопки → BUILT-BUT-DEAD.
        'droneScout'                      => \App\Controllers\Telegram\Commands\Actions\Drone\DroneScoutCraftInfoAction::class,
        'factionDeposit'                  => \App\Controllers\Telegram\Commands\Actions\Faction\FactionProjectDepositConfirmAction::class,

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
        // V23 (ADR-055): NPC-мастер на базе — gold-only мгновенный ремонт (через PrefixDispatcher).
        // - npc_repair_<log_id>: расчёт gold + Confirm
        // - confirm_npc_repair_<log_id>: списать gold + instant restore durability
        'repairToolsList'                 => \App\Controllers\Telegram\Commands\Actions\Craft\Repair\RepairToolsListAction::class,

        // === Objects ===
        'objectActionClosedWarehouse'     => \App\Controllers\Telegram\Commands\Actions\Objects\ObjectCloseWarehouseAction::class,

        // === Profile / Insurance ===
        'PersonalInsurance'               => \App\Controllers\Telegram\Commands\Profile\PersonalInsurance::class,
        'toggleInsurance'                 => \App\Controllers\Telegram\Commands\Profile\ToggleInsuranceAction::class,
        'calculateInsurance'              => \App\Controllers\Telegram\Commands\Profile\CalculateInsuranceAction::class,
        // V24 (ADR-056) — NPC-страховой агент для селективной защиты крафтовых
        // предметов (robots/workbench/transport). Pre-paid вечный полис.
        // - craftInsuranceList: список eligible нестрахованных предметов
        // - craftInsure_<log_id>: ask (через PrefixDispatcher) — расчёт gold + Confirm
        // - confirm_craft_insure_<log_id>: списать gold + insured=1
        'craftInsuranceList'              => \App\Controllers\Telegram\Commands\Actions\Craft\Insurance\CraftInsuranceListAction::class,

        // V25 (ADR-057) — странствующие NPC-караваны на карте.
        // - caravanLook: показать караван(ы) на текущей клетке игрока
        // - caravanBuyAll_<id>: купить весь offer (через PrefixDispatcher)
        'caravanLook'                     => \App\Controllers\Telegram\Commands\Actions\Caravan\CaravanLookAction::class,
        // W14b (ADR-068) — bargained-режим осмотра каравана (договорная цена от trading_karma).
        'caravanLookBargain'              => \App\Controllers\Telegram\Commands\Actions\Caravan\CaravanLookAction::class,

        // W2 (ADR-058) — Drone-recon. Список дрон-инстансов чара с charge-bar.
        // - droneScoutList: показать все DroneScout с qty>0 + кнопки запуска
        // - recceDrone_<log_id>: launch action (через PrefixDispatcher)
        'droneScoutList'                  => \App\Controllers\Telegram\Commands\Actions\Drone\DroneScoutCraftedListAction::class,
        // W3b (ADR-060) — Cargo drone. Доставка ресурсов на base_storage.
        // - cargoDroneList: charge-bar + список ресурсов для отправки
        // - cargoDroneSend_<log_id>_<res_id>: atomic send (через PrefixDispatcher)
        // - cargoDroneLocked: alert prerequisite (lock-state кнопки)
        // - droneCargo: preview-экран чек-листа крафта
        // - baseStorageList / baseStorageList_all: retrieve UI склада (Q5 ADR-059)
        'cargoDroneList'                  => \App\Controllers\Telegram\Commands\Actions\Drone\CargoDroneSelectAction::class,
        'cargoDroneLocked'                => \App\Controllers\Telegram\Commands\Actions\Drone\CargoDroneLockedAction::class,
        'droneCargo'                      => \App\Controllers\Telegram\Commands\Actions\Drone\DroneCargoCraftInfoAction::class,
        'baseStorageList'                 => \App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageListAction::class,
        'baseStorageList_all'             => \App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageListAction::class,
        // W4 (ADR-063) — Repair drone. Gold-only batch ремонтник всех роботов чара.
        // - repairDrone: preview-экран (charge-bar + список роботов + cumulative cost)
        // - repairDroneRun: atomic batch commit (decrement gold, restore all durability)
        // - droneRepair: preview-экран чек-листа крафта (info-callback из CraftRecipes)
        'repairDrone'                     => \App\Controllers\Telegram\Commands\Actions\Drone\RepairDroneInfoAction::class,
        'repairDroneRun'                  => \App\Controllers\Telegram\Commands\Actions\Drone\RepairDroneRunAction::class,
        'droneRepair'                     => \App\Controllers\Telegram\Commands\Actions\Drone\DroneRepairCraftInfoAction::class,
        // W5 (ADR-064) — Combat drone. Defensive time-window initiative-buff.
        // - combatDroneList: список инстансов + activate buttons (либо «active X мин» статус)
        // - droneCombat: preview-чек-лист крафта (info-callback)
        // (combatDroneActivate_<log_id> — prefix через CallbackPrefixDispatcher)
        'combatDroneList'                 => \App\Controllers\Telegram\Commands\Actions\Drone\CombatDroneListAction::class,
        'droneCombat'                     => \App\Controllers\Telegram\Commands\Actions\Drone\DroneCombatCraftInfoAction::class,

        // === PvP ===
        'runAway'                         => \App\Controllers\Telegram\Commands\Actions\PVP\RunAwayAction::class,
        'attackPlayer'                    => \App\Controllers\Telegram\Commands\Actions\PVP\AttackPlayerAction::class,
        // W17 (ADR-071) — PvP-дуэль (opt-in честный бой). Callback `duel_<defenderId>` (первый сегмент `duel`).
        'duel'                            => \App\Controllers\Telegram\Commands\Actions\PVP\DuelAction::class,
        // W18 (ADR-072) — PvP-ладдер. Callback `pvpLadder` / `pvpLadder_global` / `pvpLadder_faction_<id>`
        // (первый сегмент `pvpLadder` → этот handler; вкладка парсится из полного callback_data).
        'pvpLadder'                       => \App\Controllers\Telegram\Commands\Actions\PVP\PvpLadderAction::class,
        // W19/W20 (ADR-074/075) — модернизация (player-facing «Модернизация»; callback — техн. legacy-имя).
        // `enchant` = листинг предметов (exact); выбор/применение — prefix enchantSel_/enchantApply_ ниже.
        'enchant'                         => \App\Controllers\Telegram\Commands\Actions\Craft\EnchantAction::class,

        // W21 (ADR-076) — Housing customisation: декор базы (имя + флаг). Exact routes для overview/palette;
        // prefix campSetName_/campSetFlag_ зарегистрированы ниже в prefixRoutes.
        'campDecor'                       => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        'campDecorName'                   => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        'campDecorFlag'                   => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        // W22 (ADR-077) — Housing W2: interior items. Exact routes для палитр; prefix campSetHearth_/Furniture_/Pet_ ниже.
        'campDecorHearth'                 => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        'campDecorFurniture'              => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        'campDecorPet'                    => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,

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
        // V16 (ADR-047) — выбор/смена крафт-специализации. Callback: `specChoose_<branch>`.
        'specChoose' => \App\Controllers\Telegram\Commands\Actions\Specialization\SpecializationChooseAction::class,
        // v0.51.129 (community idea #1) — cancel queued craft з refund ресурсів
        'cancelQueued' => \App\Controllers\Telegram\Commands\Actions\Craft\CancelQueuedCraftAction::class,
        // S28 — generic preview сезонного рецепта. Callback: `craftPreviewSeasonal_<RecipeKey>`.
        'craftPreviewSeasonal' => \App\Controllers\Telegram\Commands\Actions\Craft\Seasonal\SeasonalRecipePreviewAction::class,
        // S17 (v0.51.199) — generic preview для T3 weapons. Callback: `craftPreviewT3_<RecipeKey>`.
        // 1 generic Action на 5 recipes (DRY pattern, recipe lookup из CraftRecipes).
        // ⚠️ Порядок: длинные prefix'ы СНАЧАЛА (Armor/Medical), craftPreviewT3 потом (короткий fallback для weapons).
        'craftPreviewT3Armor' => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\ArmorRecipePreviewT3Action::class,
        'craftPreviewT3Medical' => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\MedicalRecipePreviewT3Action::class,
        'craftPreviewT3Utility' => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\UtilityRecipePreviewT3Action::class,
        'craftPreviewT3' => \App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchProfessional\WeaponRecipePreviewT3Action::class,
        // W7b (ADR-065 Part 2) — тема каталога «Что нового». Callback `whatsNew_<topic_key>`
        // (первый сегмент `whatsNew` после explode('_'); exact `whatsNewCatalog` ловится раньше).
        'whatsNew' => \App\Controllers\Telegram\Commands\Actions\WhatsNew\WhatsNewTopicAction::class,
        // W11 (ADR-067) — выбор ветки квест-развилки. Callback `questBranch_<quest_id>`.
        'questBranch' => \App\Controllers\Telegram\Commands\Actions\Quest\QuestBranchChooseAction::class,
        // W20 (ADR-075) — модернизация: выбор/применение по предмету. `enchantSel_<type>_<id>` / `enchantApply_<type>_<id>`.
        'enchantSel' => \App\Controllers\Telegram\Commands\Actions\Craft\EnchantAction::class,
        'enchantApply' => \App\Controllers\Telegram\Commands\Actions\Craft\EnchantAction::class,
        // W21 (ADR-076) — Housing: сохранение выбранного имени/флага лагеря. `campSetName_<idx>` / `campSetFlag_<idx>`.
        'campSetName' => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        'campSetFlag' => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        // W22 (ADR-077) — Housing W2: сохранение interior items. `campSetHearth_<idx>` / `campSetFurniture_<idx>` / `campSetPet_<idx>`.
        'campSetHearth' => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        'campSetFurniture' => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
        'campSetPet' => \App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction::class,
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
