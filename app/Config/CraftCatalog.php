<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * W2.N3-03 (ADR-190) — индекс «верстак → категория → ключи {@see CraftRecipes}» для нативного экрана
 * «🔨 Крафт» на `/play`. Деревья повторяют кнопки нынешних экранов бота (хаб крафта → раздел →
 * категория → карточка), паритет держит `CraftCatalogTest`. Каталог бота этот файл не меняет.
 *
 * Не баланс: только навигация (подписи, порядок, callback'и экранов бота для моста). Числа карточки —
 * из `CraftOrderService::preview()`.
 *
 * Поля:
 * - `bot` — callback экрана бота (раздел/категория); по нему мост идёт от хаба `/craft` к экрану
 *   нехватки. `null` — у категории нет своего экрана (дроны стоят кнопками прямо в разделе).
 * - `item_cb` — callback карточки рецепта в боте, если это не его `info_callback` (`{key}` — ключ).
 * - `lock` — замок раздела, те же правила, что у бота: раздел открыт, пока у персонажа есть
 *   предмет `item` (`crafted_items.name_eng`); путь к требованию — карточка `recipe` в `bench/cat`.
 * - `seasonal` — категория показывает только рецепты активного сезона (`SeasonalCraftService`).
 *
 * @phpstan-type Category array{label:string, bot:?string, item_cb?:string, seasonal?:bool, recipes:list<string>}
 * @phpstan-type Lock array{item:string, need:string, bench:string, cat:string, recipe:string}
 * @phpstan-type Bench array{label:string, bot:string, lock:?Lock, categories:array<string, Category>}
 */
class CraftCatalog extends BaseConfig
{
    public const GENERAL  = 'general';
    public const STANDARD = 'standard';
    public const PRO      = 'pro';

    /** Вход в мост к экранам крафта бота — slash-команда хаба «🔨 Крафт». */
    public const BOT_ENTRY = '/craft';

    /**
     * @var array<string, Bench>
     */
    public array $benches = [
        self::GENERAL => [
            'label'      => '🔨 Общий крафт',
            'bot'        => 'generalCraft',
            'lock'       => null,
            'categories' => [
                'medicine' => [
                    'label'   => '💊 Лекарства',
                    'bot'     => 'medicinesCraft1',
                    'recipes' => ['Antiseptic', 'PainReliefPower', 'StrengthElixir', 'Bandage', 'Sedative', 'Stimulator', 'Regenerator', 'BasicMedKit'],
                ],
                'tools' => [
                    'label'   => '🛠️ Инструменты',
                    'bot'     => 'tools',
                    'recipes' => ['LumberjackAxe', 'StonePickaxe', 'IronShovel', 'FishingRod', 'Hoe', 'FoldingKnife', 'IronPickaxe', 'TireIron'],
                ],
                'components' => [
                    'label'   => '📐 Компоненты',
                    'bot'     => 'componentsCraft',
                    'recipes' => ['MetalFragments', 'Fabric', 'StoneBlocks', 'Fertilizer', 'WoodMaterials', 'Soil', 'CharcoalBriquettes', 'GlassBags', 'ElectronicComponents', 'Wiring'],
                ],
                'workbenches' => [
                    'label'   => '🔬 Верстаки',
                    'bot'     => 'WorkbenchChoice',
                    'recipes' => ['WorkbenchOne', 'ProfessionalWorkbench'],
                ],
                'seasonal' => [
                    'label'    => '🗓 Сезонный крафт',
                    'bot'      => 'seasonalCraft',
                    'seasonal' => true,
                    'recipes'  => [
                        'WinterHerbalBrew', 'WinterHoneyMead', 'WinterWarmingBalm', 'WinterCampStew', 'WinterPreserves',
                        'SpringFirstHerbTea', 'SpringBirchSap', 'SpringPrimroseInfusion', 'SpringWildGreens', 'SpringShootsDecoction',
                        'SummerColdKvass', 'SummerBerryMors', 'SummerFruitWater', 'SummerMintTea', 'SummerAloeBalm',
                        'AutumnBerryJam', 'AutumnMushroomStew', 'AutumnNutMix', 'AutumnCider', 'AutumnVegPreserves',
                    ],
                ],
                'cook' => [
                    'label'   => '🔥 Костёр',
                    'bot'     => 'cook',
                    'item_cb' => 'cook_qty_{key}',
                    'recipes' => ['MushroomSoup', 'BerryBrew', 'BakedFruit', 'GrainPorridge', 'HeartyStew', 'FishSoup', 'GrilledFish'],
                ],
                'preserves' => [
                    'label'   => '🥫 Консервы',
                    'bot'     => 'cookPreserves',
                    'item_cb' => 'cookPreserves_qty_{key}',
                    'recipes' => ['StewPreserve', 'DryRation', 'FishPreserve'],
                ],
                'defense' => [
                    'label'   => '🛡 Защита',
                    'bot'     => 'defenseCraft',
                    'recipes' => ['MeteorShelter'],
                ],
            ],
        ],
        self::STANDARD => [
            'label'      => '🔧 Стандартный крафт',
            'bot'        => 'standardCraft',
            'lock'       => null,
            'categories' => [
                'robots' => [
                    'label'   => '🤖 Роботы',
                    'bot'     => 'robotsCraft2',
                    'recipes' => ['RobotExplorer', 'RobotGatherer', 'RobotScout', 'RobotIndustrial'],
                ],
                'teleports' => [
                    'label'   => '🌀 Телепорты',
                    'bot'     => 'teleportBeaconCraft2',
                    'recipes' => ['TeleportBeaconBasic2', 'TeleportBackpack2', 'PortableTeleport2'],
                ],
                'armor' => [
                    'label'   => '🛡️ Броня',
                    'bot'     => 'armorCraft2',
                    'recipes' => ['RaggedShirt2', 'DrifterClothes2', 'LeatherJacket2', 'ReinforcedLeather2'],
                ],
                'weapons' => [
                    'label'   => '⚔️ Оружие',
                    'bot'     => 'weaponsCraft2',
                    'recipes' => ['MetalSpear', 'PipeGun', 'WiredBat', 'CrossbowMk1'],
                ],
                'drones' => [
                    'label'   => '🚁 Дроны',
                    'bot'     => null,
                    'recipes' => ['DroneScout', 'DroneCargo', 'DroneRepair', 'DroneCombat'],
                ],
            ],
        ],
        self::PRO => [
            'label'      => '🛠️ Профессиональный крафт',
            'bot'        => 'workbenchProfessional',
            'lock'       => [
                'item'   => 'ProfessionalWorkbench',
                'need'   => '🛠️ Профессиональный верстак',
                'bench'  => self::GENERAL,
                'cat'    => 'workbenches',
                'recipe' => 'ProfessionalWorkbench',
            ],
            'categories' => [
                'weapons' => [
                    'label'   => '⚔️ Оружие T3',
                    'bot'     => 'craftWeaponsT3Select',
                    'recipes' => ['GaussPistol', 'RailCarbineVikhr', 'IonDestabilizer', 'FlamethrowerAid', 'ExoRailgunBehemoth', 'HydraPlasmaCannon'],
                ],
                'armor' => [
                    'label'   => '🛡 Броня T3',
                    'bot'     => 'craftArmorT3Select',
                    'recipes' => ['TacticalArmorSuit', 'ExoskeletonStrekoza', 'TitanPowerArmor', 'TeslaShardArmor', 'JuggernautBattleArmor'],
                ],
                'medical' => [
                    'label'   => '💊 Медицина T3',
                    'bot'     => 'craftMedicalT3Select',
                    'recipes' => ['SyntheticMedicine', 'EmergencyTransfusion', 'SurgicalKit'],
                ],
                'utility' => [
                    'label'   => '🔧 Утилиты T3',
                    'bot'     => 'craftUtilityT3Select',
                    'recipes' => ['DiamondPickaxe', 'SapperShovel', 'GoldenHoe'],
                ],
                'faction_weapons' => [
                    'label'   => '🎖 Фракционное оружие',
                    'bot'     => 'craftFactionWeaponsSelect',
                    'recipes' => ['BunkerRifle', 'TechnoBeamShotgun', 'GhostCityKnife', 'FarmersHarvestScythe'],
                ],
                'faction_armor' => [
                    'label'   => '🛡 Фракционная броня',
                    'bot'     => 'craftFactionArmorSelect',
                    'recipes' => ['BunkerPlateArmor', 'TechnoShieldSuit', 'GhostCityCloak', 'FarmsteadGuardArmor'],
                ],
            ],
        ],
    ];

    /**
     * Где рецепт живёт в каталоге: первое вхождение (верстак, категория); null — рецепта в индексе нет.
     *
     * @return array{bench:string, cat:string}|null
     */
    public function locate(string $recipeKey): ?array
    {
        foreach ($this->benches as $benchKey => $bench) {
            foreach ($bench['categories'] as $catKey => $cat) {
                if (in_array($recipeKey, $cat['recipes'], true)) {
                    return ['bench' => $benchKey, 'cat' => $catKey];
                }
            }
        }

        return null;
    }

    /**
     * Путь бота от хаба `/craft` до карточки рецепта: раздел → категория → карточка.
     *
     * @return list<string>|null null — рецепта нет в индексе
     */
    public function botRoute(string $recipeKey, string $infoCallback): ?array
    {
        $at = $this->locate($recipeKey);
        if ($at === null) {
            return null;
        }
        $bench = $this->benches[$at['bench']];
        $cat   = $bench['categories'][$at['cat']];
        $card  = isset($cat['item_cb']) ? str_replace('{key}', $recipeKey, $cat['item_cb']) : $infoCallback;
        $route = [$bench['bot']];
        foreach ([$cat['bot'], $card] as $step) {
            if ($step !== null && $step !== '' && $step !== end($route)) {
                $route[] = $step;
            }
        }

        return $route;
    }
}
