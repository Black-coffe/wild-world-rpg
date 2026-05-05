<?php

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
