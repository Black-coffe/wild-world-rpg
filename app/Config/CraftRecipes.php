<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * F3.B5 (v0.21.0) — рецепты крафта для GenericCraftActionStart +
 * GenericCraftCompletionHandler. Расширяет F2.2-схему start-side полями
 * (task_name, resources, crafted_items, image_in_progress, info_callback).
 *
 * Каждый рецепт описывает обе стороны крафта:
 *   - Start (GenericCraftActionStart) — ресурсы, длительность, картинка процесса.
 *   - Completion (GenericCraftCompletionHandler) — name_eng для lookup,
 *     бонусы агилити/интеллекта, картинка результата, зона применения.
 *
 * Callback-конвенция: `genericCraft_<RecipeKey>_<qty>`.
 *   Пример: `genericCraft_Bandage_5` → recipe='Bandage', quantity=5.
 *
 * Worker `tasks.name` (legacy DB-id) маппится на handler через
 * `Worker::getHandlerClassName()`. Ключ recipe в `task_settings.recipe`
 * берётся из callback-данных и пишется action-стартером.
 *
 * Поля рецепта:
 *   - `task_name`            : `tasks.name` для lookup длительности крафта.
 *   - `resources`            : map name_rus → quantity на 1 шт.
 *   - `crafted_items`        : map name_eng → quantity на 1 шт. (для крафтов,
 *                              требующих другие крафты как материал).
 *   - `image_in_progress`    : картинка для action-start уведомления.
 *   - `start_caption_name`   : строка для "Ты создаёшь: <iconRus>" (включает
 *                              эмодзи, например "🩹 *Повязку*").
 *   - `info_callback`        : callback-data для возврата на info-screen
 *                              (необязательно, для будущей кнопки "Назад").
 *   - `item_name_eng`        : `crafted_items.name_eng` (lookup-ключ).
 *   - `item_name_rus`        : запасной name_rus.
 *   - `icon_emoji`           : строка с эмодзи для completion ("🩹").
 *   - `zone_emoji`           : контекстная зона ("💊").
 *   - `zone_name`            : "медицина".
 *   - `agility_bonus`        : float, прибавка к ловкости.
 *   - `intellect_bonus`      : float, прибавка к интеллекту.
 *   - `image_completed`      : картинка completion-уведомления.
 *   - `craft_again_callback` : callback для кнопки «Крафтить ещё»
 *                              (ставим `genericCraft_<X>_1`).
 */
class CraftRecipes extends BaseConfig
{
    public array $recipes = [
        'Bandage' => [
            'task_name'            => 'craftBandage',
            'resources'            => [
                'Травы'         => 2,
                'Кора деревьев' => 2,
                'Водоросли'     => 3,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg',
            'start_caption_name'   => '🩹 *Повязку*',
            'info_callback'        => 'bandage',

            'item_name_eng'        => 'Bandage',
            'item_name_rus'        => 'Повязка',
            'icon_emoji'           => '🩹',
            'zone_emoji'           => '💊',
            'zone_name'            => 'медицина',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg',
            'craft_again_callback' => 'genericCraft_Bandage_1',
        ],

        'Antiseptic' => [
            'task_name'            => 'craftAntiseptic',
            'resources'            => [
                'Кактус' => 3,
                'Грибы'  => 1,
                'Вода'   => 10,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/antiseptic_craft.jpg',
            'start_caption_name'   => '🧴 *Антисептик*',
            'info_callback'        => 'antiseptic',

            'item_name_eng'        => 'Antiseptic',
            'item_name_rus'        => 'Антисептик',
            'icon_emoji'           => '🧴',
            'zone_emoji'           => '💊',
            'zone_name'            => 'медицина',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/antiseptic_craft.jpg',
            'craft_again_callback' => 'genericCraft_Antiseptic_1',
        ],

        'PainReliefPower' => [
            'task_name'            => 'craftPainReliefPower',
            'resources'            => [
                'Ядовитые растения'        => 1,
                'Кора деревьев'            => 3,
                'Береговая растительность' => 2,
                'Цветы орхидей'            => 1,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/analgesic_powder.jpg',
            'start_caption_name'   => '🌡️ *Обезболивающий порошок*',
            'info_callback'        => 'painReliefPowder',

            'item_name_eng'        => 'AnalgesicPowder',
            'item_name_rus'        => 'Обезболивающий порошок',
            'icon_emoji'           => '🌡️',
            'zone_emoji'           => '💊',
            'zone_name'            => 'медицина',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/analgesic_powder.jpg',
            'craft_again_callback' => 'genericCraft_PainReliefPower_1',
        ],

        'StrengthElixir' => [
            'task_name'            => 'craftStrengtheningElixir',
            'resources'            => [
                'Мед'   => 2,
                'Ягоды' => 3,
                'Вода'  => 20,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/tonic_elixir.jpg',
            'start_caption_name'   => '🧪 *Укрепляющий эликсир*',
            'info_callback'        => 'strengtheningElixir',

            'item_name_eng'        => 'TonicElixir',
            'item_name_rus'        => 'Укрепляющий эликсир',
            'icon_emoji'           => '🧪',
            'zone_emoji'           => '💊',
            'zone_name'            => 'медицина',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/tonic_elixir.jpg',
            'craft_again_callback' => 'genericCraft_StrengthElixir_1',
        ],

        'Sedative' => [
            'task_name'            => 'craftSedative',
            'resources'            => [
                'Цветы орхидей' => 1,
                'Травы'         => 2,
                'Вода'          => 25,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/dry_herb_tea.jpg',
            'start_caption_name'   => '🫖 *Успокоительное*',
            'info_callback'        => 'sedative',

            'item_name_eng'        => 'Sedative',
            'item_name_rus'        => 'Успокоительное',
            'icon_emoji'           => '🫖',
            'zone_emoji'           => '💊',
            'zone_name'            => 'медицина',
            'agility_bonus'        => 0.03,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/dry_herb_tea.jpg',
            'craft_again_callback' => 'genericCraft_Sedative_1',
        ],

        'Stimulator' => [
            'task_name'            => 'craftStimulator',
            'resources'            => [
                'Грибы' => 3,
                'Мед'   => 2,
                'Алоэ'  => 3,
                'Вода'  => 12,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/liquid_mixture_of_very_invigorating_acid-green_beverage.jpg',
            'start_caption_name'   => '💉 *Стимулятор*',
            'info_callback'        => 'stimulator',

            'item_name_eng'        => 'Stimulator',
            'item_name_rus'        => 'Стимулятор',
            'icon_emoji'           => '💉',
            'zone_emoji'           => '💊',
            'zone_name'            => 'медицина',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.03,
            'image_completed'      => 'uploads/telegram/craft/liquid_mixture_of_very_invigorating_acid-green_beverage.jpg',
            'craft_again_callback' => 'genericCraft_Stimulator_1',
        ],

        'Regenerator' => [
            'task_name'            => 'craftRegenerator',
            'resources'            => [
                'Мясо диких животных' => 2,
                'Водные растения'     => 2,
                'Травы'               => 6,
                'Вода'                => 30,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/health_and_strength_regenerator.jpg',
            'start_caption_name'   => '🔋 *Регенератор*',
            'info_callback'        => 'regenerator',

            'item_name_eng'        => 'Regenerator',
            'item_name_rus'        => 'Регенератор',
            'icon_emoji'           => '🔋',
            'zone_emoji'           => '💊',
            'zone_name'            => 'медицина',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.03,
            'image_completed'      => 'uploads/telegram/craft/health_and_strength_regenerator.jpg',
            'craft_again_callback' => 'genericCraft_Regenerator_1',
        ],

        'BasicMedKit' => [
            'task_name'            => 'craftBasicMedKit',
            'resources'            => [
                'Грибы' => 4,
                'Мед'   => 2,
                'Алоэ'  => 4,
                'Вода'  => 11,
            ],
            'crafted_items'        => [
                'Bandage' => 5,
            ],
            'image_in_progress'    => 'uploads/telegram/craft/simple_craft_kit.jpg',
            'start_caption_name'   => '🚑 *Базовая аптечка*',
            'info_callback'        => 'basicMedKit',

            'item_name_eng'        => 'FirstAidKit',
            'item_name_rus'        => 'Базовая аптечка',
            'icon_emoji'           => '🚑',
            'zone_emoji'           => '💊',
            'zone_name'            => 'медицина',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/simple_craft_kit.jpg',
            'craft_again_callback' => 'genericCraft_BasicMedKit_1',
        ],

        // ============================================================
        // F3.B6 (v0.22.0) — Components 10 крафтов
        // ============================================================

        'Wiring' => [
            'task_name'            => 'craftWiring',
            'resources'            => ['Мхи' => 2],
            'crafted_items'        => ['metalFragments' => 3],
            'image_in_progress'    => 'uploads/telegram/craft/components/wiring_craft.jpg',
            'start_caption_name'   => '🔌 *Проводку*',
            'info_callback'        => 'wiring',

            'item_name_eng'        => 'wiring',
            'item_name_rus'        => 'Проводка',
            'icon_emoji'           => '🔌',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.03,
            'image_completed'      => 'uploads/telegram/craft/components/wiring_craft.jpg',
            'craft_again_callback' => 'genericCraft_Wiring_1',
        ],

        'Fabric' => [
            'task_name'            => 'craftFabric',
            'resources'            => [
                'Шерсть животных'         => 10,
                'Шёлк пауков-пустынников' => 1,
                'Текстильные культуры'    => 10,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/components/craftFabric.jpg',
            'start_caption_name'   => '🧵 *Ткань*',
            'info_callback'        => 'fabric',

            'item_name_eng'        => 'Fabric',
            'item_name_rus'        => 'Ткань',
            'icon_emoji'           => '🧵',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/components/craftFabric.jpg',
            'craft_again_callback' => 'genericCraft_Fabric_1',
        ],

        'Soil' => [
            'task_name'            => 'craftSoil',
            'resources'            => [
                'Глина'     => 10,
                'Водоросли' => 5,
                'Песок'     => 26,
                'Ил'        => 15,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/components/craftSoil.jpg',
            'start_caption_name'   => '🌱 *Грунт*',
            'info_callback'        => 'soil',

            'item_name_eng'        => 'Soil',
            'item_name_rus'        => 'Грунт',
            'icon_emoji'           => '🌱',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/components/craftSoil.jpg',
            'craft_again_callback' => 'genericCraft_Soil_1',
        ],

        'ElectronicComponents' => [
            'task_name'            => 'craftElectronicComponents',
            'resources'            => ['Нефть' => 3],
            'crafted_items'        => ['metalFragments' => 5],
            'image_in_progress'    => 'uploads/telegram/craft/components/electronic_components.jpg',
            'start_caption_name'   => '💻 *Электронные компоненты*',
            'info_callback'        => 'electronicComponents',

            'item_name_eng'        => 'electronicComponents',
            'item_name_rus'        => 'Электронные компоненты',
            'icon_emoji'           => '💻',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/components/electronic_components.jpg',
            'craft_again_callback' => 'genericCraft_ElectronicComponents_1',
        ],

        'StoneBlocks' => [
            'task_name'            => 'craftStoneBlocks',
            'resources'            => [
                'Камни' => 36,
                'Вода'  => 10,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/components/craftStoneBlocks.jpg',
            'start_caption_name'   => '🧱 *Каменные блоки*',
            'info_callback'        => 'stoneBlocks',

            'item_name_eng'        => 'stoneBlocks',
            'item_name_rus'        => 'Каменные блоки',
            'icon_emoji'           => '🧱',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/components/craftStoneBlocks.jpg',
            'craft_again_callback' => 'genericCraft_StoneBlocks_1',
        ],

        'MetalFragments' => [
            'task_name'            => 'craftMetalFragments',
            'resources'            => [
                'Железная руда' => 100,
                'Древесина'     => 10,
                'Песок'         => 1,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/components/craftMetalFragments.jpg',
            'start_caption_name'   => '🔩 *Металл фрагменты*',
            'info_callback'        => 'metalFragments',

            'item_name_eng'        => 'metalFragments',
            'item_name_rus'        => 'Металл фрагменты',
            'icon_emoji'           => '🔩',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/components/craftMetalFragments.jpg',
            'craft_again_callback' => 'genericCraft_MetalFragments_1',
        ],

        'Fertilizer' => [
            'task_name'            => 'craftFertilizer',
            'resources'            => [
                'Кости животных' => 1,
                'Вода'           => 5,
                'Водоросли'      => 20,
                'Ил'             => 10,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/components/craftFertilizer.jpg',
            'start_caption_name'   => '🌿 *Удобрение*',
            'info_callback'        => 'fertilizer',

            'item_name_eng'        => 'Fertilizer',
            'item_name_rus'        => 'Удобрение',
            'icon_emoji'           => '🌿',
            'zone_emoji'           => '🌿',
            'zone_name'            => 'фермерство',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/components/craftFertilizer.jpg',
            'craft_again_callback' => 'genericCraft_Fertilizer_1',
        ],

        'WoodMaterials' => [
            'task_name'            => 'craftWoodMaterials',
            'resources'            => [
                'Древесина' => 50,
                'Вода'      => 5,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/components/craftWoodMaterials.jpg',
            'start_caption_name'   => '🪵 *Древесные материалы*',
            'info_callback'        => 'woodMaterials',

            'item_name_eng'        => 'WoodMaterials',
            'item_name_rus'        => 'Древесные материалы',
            'icon_emoji'           => '🪵',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.03,
            'image_completed'      => 'uploads/telegram/craft/components/craftWoodMaterials.jpg',
            'craft_again_callback' => 'genericCraft_WoodMaterials_1',
        ],

        'CharcoalBriquettes' => [
            'task_name'            => 'craftCharcoalBriquettes',
            'resources'            => [
                'Древесина'       => 10,
                'Глина'           => 2,
                'Вода'            => 2,
                'Угольная порода' => 20,
            ],
            'crafted_items'        => [],
            // Внимание: для CharcoalBriquettes картинка с расширением .png
            // (исторически так сложилось, остальные components — .jpg).
            'image_in_progress'    => 'uploads/telegram/craft/components/craftCharcoalBriquettes.png',
            'start_caption_name'   => '🪨 *Угольные брикеты*',
            'info_callback'        => 'charcoalBriquettes',

            'item_name_eng'        => 'CharcoalBriquettes',
            'item_name_rus'        => 'Угольные брикеты',
            'icon_emoji'           => '🪨',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/components/craftCharcoalBriquettes.png',
            'craft_again_callback' => 'genericCraft_CharcoalBriquettes_1',
        ],

        'GlassBags' => [
            'task_name'            => 'craftGlassBags',
            'resources'            => [
                'Древесина'      => 10,
                'Песок'          => 50,
                'Базальт'        => 10,
                'Лавовый камень' => 8,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/components/craftGlassBags.jpg',
            'start_caption_name'   => '🪟 *Стеклопакеты*',
            'info_callback'        => 'glassBags',

            'item_name_eng'        => 'GlassBags',
            'item_name_rus'        => 'Стеклопакеты',
            'icon_emoji'           => '🪟',
            'zone_emoji'           => '🏭',
            'zone_name'            => 'производство',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/components/craftGlassBags.jpg',
            'craft_again_callback' => 'genericCraft_GlassBags_1',
        ],

        // ============================================================
        // F3.B7 (v0.23.0) — Tools 8 крафтов
        // image_in_progress = единая картинка верстака для всех (FishingRod —
        // отдельная), image_completed — уникальная картинка инструмента.
        // ============================================================

        'StonePickaxe' => [
            'task_name'            => 'craftStonePickaxe',
            'resources'            => [
                'Древесина' => 50,
                'Базальт'   => 1,
                'Камни'     => 10,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/huge_mechanical_workbench.jpg',
            'start_caption_name'   => '⛏️ *Каменная кирка*',
            'info_callback'        => 'stonePickaxe',

            'item_name_eng'        => 'StonePickaxe',
            'item_name_rus'        => 'Каменная кирка',
            'icon_emoji'           => '⛏️',
            'zone_emoji'           => '🛠️',
            'zone_name'            => 'инструменты',
            'agility_bonus'        => 0.03,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/create-an-image-of-an-ancient-stone-pickaxe.jpg',
            'craft_again_callback' => 'genericCraft_StonePickaxe_1',
        ],

        'IronShovel' => [
            'task_name'            => 'craftIronShovel',
            'resources'            => [
                'Древесина'     => 50,
                'Железная руда' => 16,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/huge_mechanical_workbench.jpg',
            'start_caption_name'   => '🥄 *Железная лопата*',
            'info_callback'        => 'ironShovel',

            'item_name_eng'        => 'IronShovel',
            'item_name_rus'        => 'Железная лопата',
            'icon_emoji'           => '🥄',
            'zone_emoji'           => '🛠️',
            'zone_name'            => 'инструменты',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/image-of-a-typical-metal-shovel.jpg',
            'craft_again_callback' => 'genericCraft_IronShovel_1',
        ],

        'IronPickaxe' => [
            'task_name'            => 'craftIronPickaxe',
            'resources'            => [
                'Древесина'     => 50,
                'Железная руда' => 25,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/huge_mechanical_workbench.jpg',
            'start_caption_name'   => '⛏️ *Железная кирка*',
            'info_callback'        => 'ironPickaxe',

            'item_name_eng'        => 'IronPickaxe',
            'item_name_rus'        => 'Железная кирка',
            'icon_emoji'           => '⛏️',
            'zone_emoji'           => '🛠️',
            'zone_name'            => 'инструменты',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/robust-iron-pickaxe.jpg',
            'craft_again_callback' => 'genericCraft_IronPickaxe_1',
        ],

        'LumberjackAxe' => [
            'task_name'            => 'craftLumberjackAxe',
            'resources'            => [
                'Древесина' => 50,
                'Базальт'   => 1,
                'Камни'     => 10,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/huge_mechanical_workbench.jpg',
            'start_caption_name'   => '🪓 *Топор дровосека*',
            'info_callback'        => 'lumberjackAxe',

            'item_name_eng'        => 'LumberjackAxe',
            'item_name_rus'        => 'Топор дровосека',
            'icon_emoji'           => '🪓',
            'zone_emoji'           => '🛠️',
            'zone_name'            => 'инструменты',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/old-stone-primitive-axe-of-stone-and-logs.jpg',
            'craft_again_callback' => 'genericCraft_LumberjackAxe_1',
        ],

        'FishingRod' => [
            'task_name'            => 'craftFishingRod',
            'resources'            => [
                'Древесина'                => 10,
                'Кожа животных'            => 1,
                'Шёлк пауков-пустынников'  => 5,
                'Улитки и моллюски'        => 15,
                'Шерсть животных'          => 3,
                'Лианы'                    => 5,
            ],
            'crafted_items'        => [],
            // FishingRod единственный tool с собственной картинкой процесса
            // (остальные используют общий верстак).
            'image_in_progress'    => 'uploads/telegram/craft/high-quality-fishing-rod.jpg',
            'start_caption_name'   => '🎣 *Удочка*',
            'info_callback'        => 'fishingRod',

            'item_name_eng'        => 'FishingRod',
            'item_name_rus'        => 'Удочка',
            'icon_emoji'           => '🎣',
            'zone_emoji'           => '🛠️',
            'zone_name'            => 'инструменты',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/high-quality-fishing-rod.jpg',
            'craft_again_callback' => 'genericCraft_FishingRod_1',
        ],

        'Hoe' => [
            'task_name'            => 'craftHoe',
            'resources'            => [
                'Древесина'     => 50,
                'Железная руда' => 16,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/huge_mechanical_workbench.jpg',
            'start_caption_name'   => '🌾 *Мотыга*',
            'info_callback'        => 'hoe',

            'item_name_eng'        => 'Hoe',
            'item_name_rus'        => 'Мотыга',
            'icon_emoji'           => '🌾',
            'zone_emoji'           => '🛠️',
            'zone_name'            => 'инструменты',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/traditional-hoe.jpg',
            'craft_again_callback' => 'genericCraft_Hoe_1',
        ],

        'FoldingKnife' => [
            'task_name'            => 'craftFoldingKnife',
            'resources'            => [
                'Древесина'     => 2,
                'Железная руда' => 36,
                'Кожа животных' => 1,
                'Камни'         => 2,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/huge_mechanical_workbench.jpg',
            'start_caption_name'   => '🔪 *Складной нож*',
            'info_callback'        => 'foldingKnife',

            'item_name_eng'        => 'FoldingKnife',
            'item_name_rus'        => 'Складной нож',
            'icon_emoji'           => '🔪',
            'zone_emoji'           => '🛠️',
            'zone_name'            => 'инструменты',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/an-old-but-sharp-folding-knife.jpg',
            'craft_again_callback' => 'genericCraft_FoldingKnife_1',
        ],

        'TireIron' => [
            'task_name'            => 'craftTireIron',
            'resources'            => [
                'Железная руда' => 54,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/huge_mechanical_workbench.jpg',
            'start_caption_name'   => '🪛 *Монтировка*',
            'info_callback'        => 'tireIron',

            'item_name_eng'        => 'TireIron',
            'item_name_rus'        => 'Монтировка',
            'icon_emoji'           => '🪛',
            'zone_emoji'           => '🛠️',
            'zone_name'            => 'инструменты',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/craftTireIron.jpg',
            'craft_again_callback' => 'genericCraft_TireIron_1',
        ],

        // ============================================================
        // F3.B8 (v0.24.0) — Workbench + Robots (3 крафта).
        // Особенности относительно B5-B7:
        //   - `gold_required` (новое поле): крафт требует списания золота.
        //   - `required_buildings` (новое поле): крафт требует наличия
        //     перечисленных построек у персонажа (RoboticsWorkshop, Workshop).
        //   - `requires_base` (новое поле): крафт требует наличия лагеря.
        // Расширения GenericCraftActionStart B8.
        // ============================================================

        'WorkbenchOne' => [
            'task_name'            => 'craftWorkbenchOne',
            'resources'            => [
                'Древесина'      => 240,
                'Смола деревьев' => 40,
                'Базальт'        => 20,
                'Лавовый камень' => 10,
            ],
            // crafted_items используют `name_eng` (через CraftedItemsModel::getRowByName).
            'crafted_items'        => [
                'stoneBlocks'    => 14,
                'WoodMaterials'  => 24,
                'metalFragments' => 20,
            ],
            'gold_required'        => 20000,
            'requires_base'        => true,
            'required_buildings'   => [],
            'image_in_progress'    => 'uploads/telegram/craft/huge_mechanical_workbench.jpg',
            'start_caption_name'   => '🔬 *Верстак 1!*',
            'info_callback'        => 'workbenchOne',

            'item_name_eng'        => 'WorkbenchOne',
            'item_name_rus'        => 'Верстак 1',
            'icon_emoji'           => '🔬',
            'zone_emoji'           => '🏚️',
            'zone_name'            => 'база',
            'agility_bonus'        => 0.04,
            'intellect_bonus'      => 0.03,
            'image_completed'      => 'uploads/telegram/workbench/workbench_one.png',
            'craft_again_callback' => 'genericCraft_WorkbenchOne_1',
        ],

        'RobotExplorer' => [
            'task_name'            => 'craftRobotExplorer',
            'resources'            => [
                'Янтарь'           => 6,
                'Смола деревьев'   => 40,
                'Солнечные камни'  => 30,
            ],
            'crafted_items'        => [
                'GlassBags'      => 2,
                'Fabric'         => 12,
                'metalFragments' => 36,
            ],
            'gold_required'        => 15000,
            'requires_base'        => true,
            'required_buildings'   => ['RoboticsWorkshop'],
            'image_in_progress'    => 'uploads/telegram/craft/standard/standard_craft_area.jpg',
            'start_caption_name'   => 'робота 🔍 *Исследователя!*',
            'info_callback'        => 'robotExplorer',

            // ВНИМАНИЕ: name_eng в crafted_items = 'RobotExplorer'
            // (НЕ 'RobotExplorer2' — это callback-имя, исторически).
            'item_name_eng'        => 'RobotExplorer',
            'item_name_rus'        => 'Робот-Исследователь',
            'icon_emoji'           => '🔍',
            'zone_emoji'           => '🌳',
            'zone_name'            => 'биом',
            'agility_bonus'        => 0.05,
            'intellect_bonus'      => 0.05,
            'image_completed'      => 'uploads/telegram/craft/standard/robot_explorer.jpg',
            'craft_again_callback' => 'genericCraft_RobotExplorer_1',
        ],

        'RobotGatherer' => [
            'task_name'            => 'craftRobotGatherer',
            'resources'            => [
                'Янтарь'           => 6,
                'Смола деревьев'   => 40,
                'Солнечные камни'  => 30,
            ],
            'crafted_items'        => [
                'GlassBags'      => 3,
                'Fabric'         => 15,
                'metalFragments' => 42,
            ],
            'gold_required'        => 21000,
            'requires_base'        => true,
            'required_buildings'   => ['RoboticsWorkshop', 'Workshop'],
            'image_in_progress'    => 'uploads/telegram/craft/standard/standard_craft_area.jpg',
            'start_caption_name'   => 'робота ⛏️ *Добытчика!*',
            'info_callback'        => 'robotGatherer',

            'item_name_eng'        => 'RobotGatherer',
            'item_name_rus'        => 'Робот-Добытчик',
            'icon_emoji'           => '⛏️',
            'zone_emoji'           => '🌳',
            'zone_name'            => 'биом',
            'agility_bonus'        => 0.05,
            'intellect_bonus'      => 0.05,
            'image_completed'      => 'uploads/telegram/craft/standard/craftRobotGatherer.jpg',
            'craft_again_callback' => 'genericCraft_RobotGatherer_1',
        ],

        // ============================================================
        // F3.B9 (v0.25.0) — WorkbenchStandard Weapons (4 крафта).
        // Особенности относительно B5-B8:
        //   - `output_type` = 'weapon' (новое поле): результат пишется в
        //     `characters_weapons` через `WeaponModel` lookup, а не в
        //     `crafted_items_log`. Stat bump → `updateStrengthAndAgility`.
        //   - `weapon_name_en` (новое поле): ключ для `weapons.name_en`.
        //   - `weapon_slot` (новое поле): 'hand' | 'twohand'.
        //   - `strength_bonus` (новое поле): прибавка к силе при completion.
        //   - `required_strength`, `required_agility`, `required_level`
        //     (новые поля): stat-checks персонажа в action-start.
        // Все поля опциональны — B5-B8 рецепты работают без изменений.
        // ============================================================

        'MetalSpear' => [
            'task_name'            => 'craftMetalSpear',
            'output_type'          => 'weapon',
            'weapon_name_en'       => 'MetalSpear',
            'weapon_slot'          => 'hand',
            'required_strength'    => 3,
            'required_level'       => 1,
            'gold_required'        => 200,
            'requires_base'        => true,
            'resources'            => [
                'Древесина'      => 3,
                'Редкие металлы' => 2,
            ],
            'crafted_items'        => [
                // English keys (CraftedItemsModel::getRowByName ищет по name_eng).
                'TireIron'       => 1,  // "Монтировка" из B7
                'Fabric'         => 2,  // "Ткань" из B6
                'metalFragments' => 2,  // "Металл фрагменты" из B6
            ],
            'image_in_progress'    => 'uploads/telegram/craft/standard/metal_spear.jpg',
            'start_caption_name'   => '🗡 *Металлическое копьё*',
            'info_callback'        => 'craftMetalSpear',

            'item_name_eng'        => 'MetalSpear',
            'item_name_rus'        => 'Металлическое копьё',
            'icon_emoji'           => '🗡',
            'zone_emoji'           => '⚔',
            'zone_name'            => 'оружие',
            'strength_bonus'       => 0.05,
            'agility_bonus'        => 0.01,
            'image_completed'      => 'uploads/telegram/craft/standard/metal_spear.jpg',
            'craft_again_callback' => 'genericCraft_MetalSpear_1',
        ],

        'PipeGun' => [
            'task_name'            => 'craftPipeGun',
            'output_type'          => 'weapon',
            'weapon_name_en'       => 'PipeGun',
            'weapon_slot'          => 'hand',
            'required_strength'    => 0,  // weapons.required_strength dynamic, fallback 0
            'required_level'       => 1,
            'gold_required'        => 600,
            'requires_base'        => true,
            'resources'            => [
                'Древесина'      => 2,
                'Кожа животных'  => 2,
                'Смола деревьев' => 2,
            ],
            'crafted_items'        => [
                'metalFragments' => 4,
                'FoldingKnife'   => 1,  // "Складной нож" из B7
                'Fabric'         => 4,
            ],
            'image_in_progress'    => 'uploads/telegram/craft/standard/pipe_gun.jpg',
            'start_caption_name'   => '🔫 *Трубчатый пистолет*',
            'info_callback'        => 'craftPipeGun',

            'item_name_eng'        => 'PipeGun',
            'item_name_rus'        => 'Трубчатый пистолет',
            'icon_emoji'           => '🔫',
            'zone_emoji'           => '⚔',
            'zone_name'            => 'оружие',
            'strength_bonus'       => 0.03,
            'agility_bonus'        => 0.01,
            'image_completed'      => 'uploads/telegram/craft/standard/pipe_gun.jpg',
            'craft_again_callback' => 'genericCraft_PipeGun_1',
        ],

        'WiredBat' => [
            'task_name'            => 'craftWiredBat',
            'output_type'          => 'weapon',
            // ВНИМАНИЕ: callback и task name 'WiredBat', но в БД weapons.name_en = 'EnhancedBat'!
            // Это историческая особенность.
            'weapon_name_en'       => 'EnhancedBat',
            'weapon_slot'          => 'hand',
            'required_strength'    => 5,
            'required_level'       => 2,
            'gold_required'        => 250,
            'requires_base'        => true,
            'resources'            => [
                'Кости животных' => 2,
                'Кожа животных'  => 2,
                'Смола деревьев' => 2,
                'Базальт'        => 1,
                'Древесина'      => 16,
            ],
            'crafted_items'        => [
                'Fabric'         => 3,
                'metalFragments' => 3,
                'TireIron'       => 1,
            ],
            'image_in_progress'    => 'uploads/telegram/craft/standard/wired_bat.jpg',
            'start_caption_name'   => '🏏 *Усовершенствованная бита*',
            'info_callback'        => 'craftWiredBat',

            'item_name_eng'        => 'EnhancedBat',  // matches weapons.name_en
            'item_name_rus'        => 'Усовершенствованная бита',
            'icon_emoji'           => '🏏',
            'zone_emoji'           => '⚔',
            'zone_name'            => 'оружие',
            'strength_bonus'       => 0.04,
            'agility_bonus'        => 0.01,
            'image_completed'      => 'uploads/telegram/craft/standard/wired_bat.jpg',
            'craft_again_callback' => 'genericCraft_WiredBat_1',
        ],

        'CrossbowMk1' => [
            'task_name'            => 'craftCrossbowMk1',
            'output_type'          => 'weapon',
            'weapon_name_en'       => 'CrossbowMk1',
            'weapon_slot'          => 'twohand',
            'required_strength'    => 3,
            'required_agility'     => 10,  // единственный weapon с agility check
            'required_level'       => 2,
            'gold_required'        => 1800,
            'requires_base'        => true,
            'resources'            => [
                'Шкура животных'  => 2,
                'Водные растения' => 5,
                'Смола деревьев'  => 5,
                'Шерсть животных' => 7,
                'Лианы'           => 3,
                'Древесина'       => 3,
            ],
            'crafted_items'        => [
                'metalFragments' => 1,
                'Fabric'         => 4,
            ],
            'image_in_progress'    => 'uploads/telegram/craft/standard/crossbow_mk1.jpg',
            'start_caption_name'   => '🏹 *Арбалет Mk.I*',
            'info_callback'        => 'craftCrossbowMk1',

            'item_name_eng'        => 'CrossbowMk1',
            'item_name_rus'        => 'Арбалет Mk.I',
            'icon_emoji'           => '🏹',
            'zone_emoji'           => '⚔',
            'zone_name'            => 'оружие',
            'strength_bonus'       => 0.02,
            'agility_bonus'        => 0.02,
            'image_completed'      => 'uploads/telegram/craft/standard/crossbow_mk1.jpg',
            'craft_again_callback' => 'genericCraft_CrossbowMk1_1',
        ],
    ];

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $recipeKey): ?array
    {
        return $this->recipes[$recipeKey] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->recipes);
    }
}
