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
            // S13a (v0.51.195): Laboratory L2+ ускоряет медичний крафт (stack з Workshop).
            'boost_building_time'  => 'Laboratory',
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
            'boost_building_time'  => 'Laboratory',  // S13a
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
            'boost_building_time'  => 'Laboratory',  // S13a
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
            'boost_building_time'  => 'Laboratory',  // S13a
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
            'boost_building_time'  => 'Laboratory',  // S13a
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
            'boost_building_time'  => 'Laboratory',  // S13a
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
            'boost_building_time'  => 'Laboratory',  // S13a
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
            'boost_building_time'  => 'Laboratory',  // S13a
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
            // S12-ext (доменная печь): металл-компонент → yield multiplier від
            // BlastFurnace L2+ (1.15/1.35 cascade). Pure bonus; char'и без печі
            // продовжують крафтити з baseline qty. Зеркалить MetalFragments.
            'boost_building'       => 'BlastFurnace',
            // ADR-086 Фаза 1a (dormant): Солнечная станция ускоряет craft-TIME электроники
            // (множитель, стак с Workshop). Активно ТОЛЬКО при killswitch
            // building.solarstation.boost.enabled=true (OFF на ship). Зеркалит ElectronicComponents.
            'boost_building_time'  => 'SolarStation',
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
            // S12-ext (доменная печь): металл-компонент → yield multiplier від
            // BlastFurnace L2+ (1.15/1.35 cascade). Pure bonus; char'и без печі
            // продовжують крафтити з baseline qty. Зеркалить MetalFragments.
            'boost_building'       => 'BlastFurnace',
            // ADR-086 Фаза 1a (dormant): Солнечная станция ускоряет craft-TIME электроники
            // (множитель, стак с Workshop). Активно ТОЛЬКО при killswitch
            // building.solarstation.boost.enabled=true (OFF на ship). Зеркалит Wiring.
            'boost_building_time'  => 'SolarStation',
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

        // v0.51.127 (community idea #2) — defense craft проти MeteorImpact event.
        // -50% resource loss якщо є у інвентарі під час падіння метеорита.
        'MeteorShelter' => [
            'task_name'            => 'craftMeteorShelter',
            'resources'            => [
                'Камни'         => 20,
                'Железная руда' => 10,
                'Древесина'     => 15,
            ],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'start_caption_name'   => '🛡 *Метеоритное укрытие*',
            'info_callback'        => 'meteorShelter',

            // 12.08.2026: гейт «с 3 уровня» жил только в строке `crafted_items` и
            // потому НЕ применялся — GenericCraftActionStart читает уровень отсюда,
            // а здесь ключа не было. Два источника правды у гейта, где решает код
            // (урок `feedback_two_sources_of_truth_for_gates`): дублируем в рецепт.
            'required_level'       => 3,
            'item_name_eng'        => 'MeteorShelter',
            'item_name_rus'        => 'Метеоритное укрытие',
            'icon_emoji'           => '🛡',
            'zone_emoji'           => '🛡',
            'zone_name'            => 'защита',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'craft_again_callback' => 'genericCraft_MeteorShelter_1',
        ],

        'MetalFragments' => [
            'task_name'            => 'craftMetalFragments',
            'resources'            => [
                'Железная руда' => 100,
                'Древесина'     => 10,
                'Песок'         => 1,
            ],
            'crafted_items'        => [],
            // S12 (v0.51.194): pure bonus, без breaking. BlastFurnace L2+ дає
            // yield multiplier (1.15 / 1.35 cascade) через BuildingEffectsService.
            // Char'и без BlastFurnace продовжують крафтити з baseline qty.
            'boost_building'       => 'BlastFurnace',
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

        // ── V6 (ADR-033) — семена для активного земледелия. ──────────────────
        // output_type='resource' → семя начисляется в character_resources
        // (resource_name_en). Низкий гейт (только база). Лор: «отложил посевной
        // материал с урожая». Урожай 1:1 (BerrySeeds→Berries и т.д.).
        // Картинки — V6 image-tail закрыт: uploads/telegram/craft/seeds/*.jpg (loot.seeds, V4).
        'BerrySeeds' => [
            'task_name'            => 'craftBerrySeeds',
            'resources'            => [
                'Ягоды' => 2,
                'Вода'  => 1,
            ],
            'crafted_items'        => [],
            'requires_base'        => true,
            'output_type'          => 'resource',
            'resource_name_en'     => 'BerrySeeds',
            'image_in_progress'    => 'uploads/telegram/craft/seeds/berry_seeds.jpg',
            'start_caption_name'   => '🫐 *Семена ягод*',
            // 12.08.2026: было 'berrySeeds' — callback, не зарегистрированный нигде.
            // Семена крафтятся кнопкой прямо из экрана выбора семян, отдельного
            // info-экрана у них нет, но `info_callback` живёт в рантайме как цель
            // «⬅️ Назад» на экране нехватки ресурсов (CraftShortageService) — то есть
            // возврат отдавал игроку «⚠️ Эта кнопка устарела». Ведём назад в теплицу,
            // откуда игрок и пришёл. Тем же лечатся Mushroom/Fruit/Crop ниже.
            'info_callback'        => 'plantSeedMenu',

            'item_name_eng'        => 'BerrySeeds',
            'item_name_rus'        => 'Семена ягод',
            'icon_emoji'           => '🫐',
            'zone_emoji'           => '🌱',
            'zone_name'            => 'земледелие',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seeds/berry_seeds.jpg',
            'craft_again_callback' => 'genericCraft_BerrySeeds_1',
        ],

        'MushroomSeeds' => [
            'task_name'            => 'craftMushroomSeeds',
            'resources'            => [
                'Грибы' => 2,
                'Вода'  => 1,
            ],
            'crafted_items'        => [],
            'requires_base'        => true,
            'output_type'          => 'resource',
            'resource_name_en'     => 'MushroomSeeds',
            'image_in_progress'    => 'uploads/telegram/craft/seeds/mushroom_seeds.jpg',
            'start_caption_name'   => '🍄 *Семена грибов*',
            'info_callback'        => 'plantSeedMenu',

            'item_name_eng'        => 'MushroomSeeds',
            'item_name_rus'        => 'Семена грибов',
            'icon_emoji'           => '🍄',
            'zone_emoji'           => '🌱',
            'zone_name'            => 'земледелие',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seeds/mushroom_seeds.jpg',
            'craft_again_callback' => 'genericCraft_MushroomSeeds_1',
        ],

        'FruitSeeds' => [
            'task_name'            => 'craftFruitSeeds',
            'resources'            => [
                'Фрукты' => 2,
                'Вода'   => 1,
            ],
            'crafted_items'        => [],
            'requires_base'        => true,
            'output_type'          => 'resource',
            'resource_name_en'     => 'FruitSeeds',
            'image_in_progress'    => 'uploads/telegram/craft/seeds/fruit_seeds.jpg',
            'start_caption_name'   => '🍎 *Семена фруктов*',
            'info_callback'        => 'plantSeedMenu',

            'item_name_eng'        => 'FruitSeeds',
            'item_name_rus'        => 'Семена фруктов',
            'icon_emoji'           => '🍎',
            'zone_emoji'           => '🌱',
            'zone_name'            => 'земледелие',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seeds/fruit_seeds.jpg',
            'craft_again_callback' => 'genericCraft_FruitSeeds_1',
        ],

        'CropSeeds' => [
            'task_name'            => 'craftCropSeeds',
            'resources'            => [
                'Зерновые культуры' => 2,
                'Вода'              => 1,
            ],
            'crafted_items'        => [],
            'requires_base'        => true,
            'output_type'          => 'resource',
            'resource_name_en'     => 'CropSeeds',
            'image_in_progress'    => 'uploads/telegram/craft/seeds/crop_seeds.jpg',
            'start_caption_name'   => '🌾 *Семена овощей*',
            'info_callback'        => 'plantSeedMenu',

            'item_name_eng'        => 'CropSeeds',
            'item_name_rus'        => 'Семена овощей',
            'icon_emoji'           => '🌾',
            'zone_emoji'           => '🌱',
            'zone_name'            => 'земледелие',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seeds/crop_seeds.jpg',
            'craft_again_callback' => 'genericCraft_CropSeeds_1',
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
            'start_caption_name'   => '🔬 *Верстак 1*',
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

            // S14 (v0.51.196) — RoboticsWorkshop L2/L3 stacks з Workshop у
            // BuildingEffectsService::getCraftTimeMultiplier(). L2 → -10%, L3 → -25%,
            // stack Workshop L3 + Robotics L3 = 0.5625 (-44%). L4-L10 cascade.
            'boost_building_time'  => 'RoboticsWorkshop',
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

            // S14 (v0.51.196) — див. RobotExplorer вище. Recipe вже декларує
            // `required_buildings => ['RoboticsWorkshop', 'Workshop']`, тож stacking
            // природний для гравців, що мають обидва на L2+.
            'boost_building_time'  => 'RoboticsWorkshop',
        ],

        // ============================================================
        // V18 (ADR-049) — Robotics T2: специализированные роботы.
        // Гейт RoboticsWorkshop L2 через `required_building_levels` (S16/ADR-026).
        // output_type не задан → default crafted_item → crafted_items_log type=robots
        // (как T1). Рантайм-различие — RobotService по name_eng (Scout/Industrial).
        // ============================================================
        'RobotScout' => [
            'task_name'                => 'craftRobotScout',
            'resources'                => [
                'Янтарь'           => 12,
                'Смола деревьев'   => 70,
                'Солнечные камни'  => 55,
            ],
            'crafted_items'            => [
                'GlassBags'      => 4,
                'Fabric'         => 22,
                'metalFragments' => 70,
            ],
            'gold_required'            => 40000,
            'requires_base'            => true,
            'required_buildings'       => ['RoboticsWorkshop'],
            'required_building_levels' => ['RoboticsWorkshop' => 2],
            'image_in_progress'        => 'uploads/telegram/craft/standard/standard_craft_area.jpg',
            'start_caption_name'       => 'робота 🔭 *Разведчика!*',
            'info_callback'            => 'robotScout',
            'item_name_eng'            => 'RobotScout',
            'item_name_rus'            => 'Робот-разведчик',
            'icon_emoji'               => '🔭',
            'zone_emoji'               => '🌳',
            'zone_name'                => 'биом',
            'agility_bonus'            => 0.06,
            'intellect_bonus'          => 0.06,
            'image_completed'          => 'uploads/telegram/craft/standard/robot_scout.jpg',
            'craft_again_callback'     => 'genericCraft_RobotScout_1',
            'boost_building_time'      => 'RoboticsWorkshop',
        ],

        'RobotIndustrial' => [
            'task_name'                => 'craftRobotIndustrial',
            'resources'                => [
                'Янтарь'           => 12,
                'Смола деревьев'   => 70,
                'Солнечные камни'  => 55,
            ],
            'crafted_items'            => [
                'GlassBags'      => 5,
                'Fabric'         => 26,
                'metalFragments' => 80,
            ],
            'gold_required'            => 48000,
            'requires_base'            => true,
            'required_buildings'       => ['RoboticsWorkshop', 'Workshop'],
            'required_building_levels' => ['RoboticsWorkshop' => 2],
            'image_in_progress'        => 'uploads/telegram/craft/standard/standard_craft_area.jpg',
            'start_caption_name'       => 'робота 🏭 *Промышленника!*',
            'info_callback'            => 'robotIndustrial',
            'item_name_eng'            => 'RobotIndustrial',
            'item_name_rus'            => 'Робот-промышленник',
            'icon_emoji'               => '🏭',
            'zone_emoji'               => '🌳',
            'zone_name'                => 'биом',
            'agility_bonus'            => 0.06,
            'intellect_bonus'          => 0.06,
            'image_completed'          => 'uploads/telegram/craft/standard/robot_industrial.jpg',
            'craft_again_callback'     => 'genericCraft_RobotIndustrial_1',
            'boost_building_time'      => 'RoboticsWorkshop',
        ],

        // ============================================================
        // W1 (ADR-058) — Drone-recon foundation (старт Фазы 1 vNext2).
        // Ручной разведывательный квадрокоптер. Per-instance battery через
        // crafted_items_log.durability_count (V18 паттерн). Gate Workshop L1
        // (низкий MVP-вход; W2-W4 Cargo/Repair/Combat могут гейтить L2/L3).
        // Recipe — для GenericCraftActionStart (write на crafted_items_log).
        // Action запуска (RecceDroneAction) — W2 build.
        // ============================================================
        'DroneScout' => [
            'task_name'                => 'craftDroneScout',
            'resources'                => [
                'Янтарь'         => 4,
                'Смола деревьев' => 25,
            ],
            'crafted_items'            => [
                'GlassBags'      => 2,
                'Fabric'         => 8,
                'metalFragments' => 30,
            ],
            'gold_required'            => 8000,
            'requires_base'            => true,
            'required_buildings'       => ['RoboticsWorkshop'],
            'required_building_levels' => ['RoboticsWorkshop' => 1],
            'image_in_progress'        => 'uploads/telegram/craft/standard/standard_craft_area.jpg',
            'start_caption_name'       => 'дрон 🚁 *Разведчик!*',
            'info_callback'            => 'droneScout',
            'item_name_eng'            => 'DroneScout',
            'item_name_rus'            => 'Дрон-разведчик',
            'icon_emoji'               => '🚁',
            'zone_emoji'               => '🌳',
            'zone_name'                => 'разведка',
            'agility_bonus'            => 0.03,
            'intellect_bonus'          => 0.04,
            'image_completed'          => 'uploads/telegram/craft/standard/drone_scout.jpg',
            'craft_again_callback'     => 'genericCraft_DroneScout_1',
            'boost_building_time'      => 'RoboticsWorkshop',
        ],

        // ============================================================
        // W3b (ADR-060) — Cargo drone (Фаза 1 vNext2). Грузовой
        // квадрокоптер, переносит до 30 кг ресурсов с любой клетки в
        // base_storage. Gate RoboticsWorkshop L2. Per-instance battery
        // через crafted_items_log.durability_count (зеркало W1).
        // ============================================================
        'DroneCargo' => [
            'task_name'                => 'craftDroneCargo',
            'resources'                => [
                'Янтарь'         => 6,
                'Смола деревьев' => 40,
            ],
            'crafted_items'            => [
                'GlassBags'      => 3,
                'Fabric'         => 14,
                'metalFragments' => 50,
            ],
            'gold_required'            => 12000,
            'requires_base'            => true,
            'required_buildings'       => ['RoboticsWorkshop'],
            'required_building_levels' => ['RoboticsWorkshop' => 2],
            'image_in_progress'        => 'uploads/telegram/craft/standard/standard_craft_area.jpg',
            'start_caption_name'       => 'карго-дрон 🚚 *Грузовой!*',
            'info_callback'            => 'droneCargo',
            'item_name_eng'            => 'DroneCargo',
            'item_name_rus'            => 'Карго-дрон',
            'icon_emoji'               => '🚚',
            'zone_emoji'               => '🌳',
            'zone_name'                => 'логистика',
            'agility_bonus'            => 0.02,
            'intellect_bonus'          => 0.05,
            'image_completed'          => 'uploads/telegram/craft/standard/drone_cargo.jpg',
            'craft_again_callback'     => 'genericCraft_DroneCargo_1',
            'boost_building_time'      => 'RoboticsWorkshop',
        ],

        // ============================================================
        // W4 (ADR-063) — Repair drone (Фаза 1 vNext2). Полевой дрон с
        // паяльной станцией и запчастями: gold-only batch ремонтник
        // всех роботов чара одним кликом. Gate RoboticsWorkshop L3.
        // V19 RobotRepair остаётся живым параллельным путём (дешёвый,
        // ручной, мгновенный per-robot). Distinct-value: DroneRepair =
        // конвертация накопленного gold (V21 dashboard 108M на проде).
        // ============================================================
        'DroneRepair' => [
            'task_name'                => 'craftDroneRepair',
            'resources'                => [
                'Янтарь'         => 8,
                'Смола деревьев' => 55,
            ],
            'crafted_items'            => [
                'GlassBags'            => 4,
                'Fabric'               => 18,
                'metalFragments'       => 80,
                'electronicComponents' => 6,
            ],
            'gold_required'            => 18000,
            'requires_base'            => true,
            'required_buildings'       => ['RoboticsWorkshop'],
            'required_building_levels' => ['RoboticsWorkshop' => 3],
            'image_in_progress'        => 'uploads/telegram/craft/standard/standard_craft_area.jpg',
            'start_caption_name'       => 'дрон-ремонтник 🔧 *Полевой техник!*',
            'info_callback'            => 'droneRepair',
            'item_name_eng'            => 'DroneRepair',
            'item_name_rus'            => 'Дрон-ремонтник',
            'icon_emoji'               => '🚁',
            'zone_emoji'               => '🌳',
            'zone_name'                => 'ремонт',
            'agility_bonus'            => 0.02,
            'intellect_bonus'          => 0.06,
            'image_completed'          => 'uploads/telegram/craft/standard/drone_repair.jpg',
            'craft_again_callback'     => 'genericCraft_DroneRepair_1',
            'boost_building_time'      => 'RoboticsWorkshop',
        ],

        // ============================================================
        // W5 (ADR-064) — Combat drone (capstone Фазы 1 vNext2).
        // Бронированный квадрокоптер с slung tear-gas + spinning blade.
        // Defensive time-window: активация даёт +12% инициативы защитнику
        // на 30 мин (cap 25% combined с WatchTower). RNG-fence safe.
        // Gate RoboticsWorkshop L4 (capstone L1→L2→L3→L4 Drone-family).
        // ============================================================
        'DroneCombat' => [
            'task_name'                => 'craftDroneCombat',
            'resources'                => [
                'Янтарь'         => 10,
                'Смола деревьев' => 70,
            ],
            'crafted_items'            => [
                'GlassBags'            => 6,
                'Fabric'               => 22,
                'metalFragments'       => 110,
                'electronicComponents' => 10,
            ],
            'gold_required'            => 27000,
            'requires_base'            => true,
            'required_buildings'       => ['RoboticsWorkshop'],
            'required_building_levels' => ['RoboticsWorkshop' => 4],
            'image_in_progress'        => 'uploads/telegram/craft/standard/standard_craft_area.jpg',
            'start_caption_name'       => 'боевой дрон 🛡 *Защитный кружок!*',
            'info_callback'            => 'droneCombat',
            'item_name_eng'            => 'DroneCombat',
            'item_name_rus'            => 'Боевой дрон',
            'icon_emoji'               => '🛡',
            'zone_emoji'               => '🌳',
            'zone_name'                => 'оборона',
            'agility_bonus'            => 0.03,
            'intellect_bonus'          => 0.05,
            'image_completed'          => 'uploads/telegram/craft/standard/drone_combat.jpg',
            'craft_again_callback'     => 'genericCraft_DroneCombat_1',
            'boost_building_time'      => 'RoboticsWorkshop',
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

        // ============================================================
        // S16 (v0.51.198) — Tier 3 Professional Workbench (ADR-026).
        // Открывает T3-крафты (S17-S20). Gate: L20, BlastFurnace L3, Lab L3.
        // Длительность и required_level — live-tunable через GameSettings:
        //   - tier3.workbench.character_level_required → required_level_setting_key
        //   - tier3.workbench.craft_duration_hours    → duration_override_setting_key
        // GenericCraftActionStart читает оба ключа (ADR-026 wire-up).
        // ============================================================
        'ProfessionalWorkbench' => [
            'task_name'                       => 'craftProfessionalWorkbench',
            'resources'                       => [
                'Редкие металлы'           => 30,
                'Доколлапсная электроника' => 3,
                'Промышленный пластик'     => 5,
            ],
            'crafted_items'                   => [
                'wiring' => 20,
            ],
            'gold_required'                   => 50000,
            'requires_base'                   => true,
            'required_buildings'              => ['BlastFurnace', 'Laboratory'],
            // S16 (ADR-026): расширение схемы — level-aware building requirement.
            // GenericCraftActionStart валидирует character_buildings.level >= N.
            'required_building_levels'        => [
                'BlastFurnace' => 3,
                'Laboratory'   => 3,
            ],
            // S16 (ADR-026): live-tunable level gate через GameSettings.
            // Fallback на static required_level если ключ отсутствует в БД.
            'required_level'                  => 20,
            'required_level_setting_key'      => 'tier3.workbench.character_level_required',
            // S16 (ADR-026): live-tunable duration override через GameSettings.
            // GenericCraftActionStart::calculateCraftingDuration читает ключ
            // (часы), конвертирует в минуты (N*60), использует как min=max
            // вместо tasks.min_duration/max_duration.
            'duration_override_setting_key'   => 'tier3.workbench.craft_duration_hours',
            'image_in_progress'               => 'uploads/telegram/craft/professional_workbench.jpg',
            'start_caption_name'              => '🛠️ *Профессиональный верстак (цех)*',
            'info_callback'                   => 'workbenchProfessional',

            'item_name_eng'                   => 'ProfessionalWorkbench',
            'item_name_rus'                   => 'Профессиональный верстак',
            'icon_emoji'                      => '🛠️',
            'zone_emoji'                      => '🏚️',
            'zone_name'                       => 'база',
            'agility_bonus'                   => 0.05,
            'intellect_bonus'                 => 0.05,
            'image_completed'                 => 'uploads/telegram/craft/professional_workbench.jpg',
            'craft_again_callback'            => 'genericCraft_ProfessionalWorkbench_1',
        ],

        // ============================================================
        // S17 (v0.51.199) — T3 weapons (ADR-026, ROADMAP-CRAFT Фаза 4 2/5).
        //
        // 5 craft-рецептов под существующие weapons (audit-finding: 20 weapons
        // L1-L26 уже в БД; реальный gap — 16 lootable без craft option, не «нет
        // L20+ контента»). Gating через recipe.required_crafted_items —
        // ProfessionalWorkbench у чара в crafted_items_log (S17 schema extension).
        //
        // Live-tunable длительности через GameSettings:
        //   tier3.weapons.<key>.craft_duration_hours
        // (см. миграция S17SeedT3WeaponGameSettings).
        //
        // Reuse: 4 T2 weapons (MetalSpear/PipeGun/EnhancedBat/CrossbowMk1)
        // pattern; weapon_name_en MATCHES existing weapons.name_en — completion
        // handler пишет в characters_weapons, добавляя качество.
        // ============================================================

        'GaussPistol' => [
            'task_name'                     => 'craftGaussPistol',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'GaussPistol',
            'weapon_slot'                   => 'hand',
            'required_strength'             => 5,
            'required_level'                => 16,
            'gold_required'                 => 5000,
            'resources'                     => [
                'Редкие металлы'           => 8,
                'Доколлапсная электроника' => 1,
                'Промышленный пластик'     => 2,
            ],
            'crafted_items'                 => [
                'wiring' => 3,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.weapons.gauss_pistol.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/gauss_pistol.jpg',
            'start_caption_name'            => '🔫 *Гаусс-пистолет (T3)*',
            'info_callback'                 => 'craftPreviewT3_GaussPistol',
            'item_name_eng'                 => 'GaussPistol',
            'item_name_rus'                 => 'Гаусс-пистолет',
            'icon_emoji'                    => '🔫',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.03,
            'agility_bonus'                 => 0.02,
            'image_completed'               => 'uploads/telegram/craft/professional/gauss_pistol.jpg',
            'craft_again_callback'          => 'genericCraft_GaussPistol_1',
        ],

        'RailCarbineVikhr' => [
            'task_name'                     => 'craftRailCarbineVikhr',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'RailCarbineVikhr',
            'weapon_slot'                   => 'twohand',
            'required_strength'             => 8,
            'required_level'                => 18,
            'gold_required'                 => 10000,
            'resources'                     => [
                'Редкие металлы'           => 12,
                'Доколлапсная электроника' => 2,
                'Промышленный пластик'     => 3,
            ],
            'crafted_items'                 => [
                'wiring' => 5,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.weapons.rail_carbine_vikhr.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/rail_carbine_vikhr.jpg',
            'start_caption_name'            => '🎯 *Рельсотрон-карабин «Вихрь» (T3)*',
            'info_callback'                 => 'craftPreviewT3_RailCarbineVikhr',
            'item_name_eng'                 => 'RailCarbineVikhr',
            'item_name_rus'                 => 'Рельсотрон-карабин «Вихрь»',
            'icon_emoji'                    => '🎯',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.04,
            'agility_bonus'                 => 0.03,
            'image_completed'               => 'uploads/telegram/craft/professional/rail_carbine_vikhr.jpg',
            'craft_again_callback'          => 'genericCraft_RailCarbineVikhr_1',
        ],

        'IonDestabilizer' => [
            'task_name'                     => 'craftIonDestabilizer',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'IonDestabilizer',
            'weapon_slot'                   => 'twohand',
            'required_strength'             => 6,
            'required_level'                => 20,
            'gold_required'                 => 20000,
            'resources'                     => [
                'Редкие металлы'           => 15,
                'Доколлапсная электроника' => 4,
                'Промышленный пластик'     => 4,
            ],
            'crafted_items'                 => [
                'wiring' => 8,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.weapons.ion_destabilizer.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/ion_destabilizer.jpg',
            'start_caption_name'            => '⚡ *Ион-дестабилизатор (T3)*',
            'info_callback'                 => 'craftPreviewT3_IonDestabilizer',
            'item_name_eng'                 => 'IonDestabilizer',
            'item_name_rus'                 => 'Ион-дестабилизатор',
            'icon_emoji'                    => '⚡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.04,
            'agility_bonus'                 => 0.04,
            'image_completed'               => 'uploads/telegram/craft/professional/ion_destabilizer.jpg',
            'craft_again_callback'          => 'genericCraft_IonDestabilizer_1',
        ],

        'FlamethrowerAid' => [
            'task_name'                     => 'craftFlamethrowerAid',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'FlamethrowerAid',
            'weapon_slot'                   => 'twohand',
            'required_strength'             => 10,
            'required_level'                => 20,
            'gold_required'                 => 15000,
            'resources'                     => [
                'Редкие металлы'           => 18,
                'Доколлапсная электроника' => 3,
                'Промышленный пластик'     => 6,
            ],
            'crafted_items'                 => [
                'wiring' => 6,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.weapons.flamethrower_aid.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/flamethrower_aid.jpg',
            'start_caption_name'            => '🔥 *Огнемёт-«Помощь» (T3)*',
            'info_callback'                 => 'craftPreviewT3_FlamethrowerAid',
            'item_name_eng'                 => 'FlamethrowerAid',
            'item_name_rus'                 => 'Огнемёт-«Помощь»',
            'icon_emoji'                    => '🔥',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.05,
            'agility_bonus'                 => 0.02,
            'image_completed'               => 'uploads/telegram/craft/professional/flamethrower_aid.jpg',
            'craft_again_callback'          => 'genericCraft_FlamethrowerAid_1',
        ],

        'ExoRailgunBehemoth' => [
            'task_name'                     => 'craftExoRailgunBehemoth',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'ExoRailgunBehemoth',
            'weapon_slot'                   => 'twohand',
            'required_strength'             => 15,
            'required_level'                => 24,
            'gold_required'                 => 35000,
            'resources'                     => [
                'Редкие металлы'           => 25,
                'Доколлапсная электроника' => 6,
                'Промышленный пластик'     => 8,
            ],
            'crafted_items'                 => [
                'wiring' => 12,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.weapons.exo_railgun_behemoth.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/exo_railgun_behemoth.jpg',
            'start_caption_name'            => '🚀 *Экзо-рельсотрон «Бегемот» (T3)*',
            'info_callback'                 => 'craftPreviewT3_ExoRailgunBehemoth',
            'item_name_eng'                 => 'ExoRailgunBehemoth',
            'item_name_rus'                 => 'Экзо-рельсотрон «Бегемот»',
            'icon_emoji'                    => '🚀',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.06,
            'agility_bonus'                 => 0.03,
            'image_completed'               => 'uploads/telegram/craft/professional/exo_railgun_behemoth.jpg',
            'craft_again_callback'          => 'genericCraft_ExoRailgunBehemoth_1',
        ],

        // ============================================================
        // E16 Ф2 (ROADMAP-100, ADR-116/121) — ГРАНДМАСТЕР-РЕЦЕПТЫ (гейт L40, синк севера).
        //
        // Замыкают сквозную нить: охота на элиток (E15 → Древние реликвии) + добыча пояса
        // Y300-600 (E16 → Пепел Предтеч / Кристалл Разлома) → грандмастер-крафт двух
        // ЛЕГЕНДАРОК, у которых раньше НЕ было рецепта (audit: HydraPlasmaCannon L26 +
        // JuggernautBattleArmor L22 — lootable-only). Реюз существующих weapon/outfit-строк
        // (как S17/S18) → новых предметов нет, completion-путь идентичен.
        //
        // Контент, НЕ механика → без killswitch (как E10 квесты): натурально-dormant by
        // prerequisite — крафт требует L40 + ProfessionalWorkbench + дорогие СЕВЕРНЫЕ
        // ингредиенты (Пепел/Кристалл/реликвии), которых у новичков нет. Эконом-инвариант П8:
        // не faucet — это СИНК поясных ресурсов и реликвий (у L40+ копятся «на вырост»).
        // ============================================================

        'HydraPlasmaCannon' => [
            'task_name'                     => 'craftHydraPlasmaCannon',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'HydraPlasmaCannon',
            'weapon_slot'                   => 'twohand',
            'required_strength'             => 18,
            'required_level'                => 40,
            'gold_required'                 => 60000,
            'resources'                     => [
                'Пепел Предтеч'    => 15,
                'Кристалл Разлома' => 8,
                'Древние реликвии' => 5,
                'Редкие металлы'   => 30,
            ],
            'crafted_items'                 => [
                'wiring' => 16,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.weapons.hydra_plasma_cannon.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/hydra_plasma_cannon.jpg',
            'start_caption_name'            => '🐍 *«Гидра» Плазмопушка (Грандмастер)*',
            'info_callback'                 => 'craftPreviewT3_HydraPlasmaCannon',
            'item_name_eng'                 => 'HydraPlasmaCannon',
            'item_name_rus'                 => '«Гидра» Плазмопушка',
            'icon_emoji'                    => '🐍',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.07,
            'agility_bonus'                 => 0.03,
            'image_completed'               => 'uploads/telegram/craft/professional/hydra_plasma_cannon.jpg',
            'craft_again_callback'          => 'genericCraft_HydraPlasmaCannon_1',
        ],

        // ============================================================
        // S25 (v0.51.205) — Faction-unique weapons (ADR-029, ROADMAP-CRAFT Фаза 5 — ЗАКРЫВАЕТ ФАЗУ).
        //
        // 4 net-new Legendary weapons, по одному на фракцию. True faction-exclusive:
        //   - НОВЫЕ recipe-поля `required_quest` (quests.title_en) + `required_faction`
        //     (character_factions.faction_id) — gate в GenericCraftActionStart.
        //   - Завершают 1:1 цепочку: фракция → strategic-объект → signature weapon.
        // output_type='weapon' → characters_weapons. Требуют ProfessionalWorkbench.
        // ============================================================
        'BunkerRifle' => [
            'task_name'                     => 'craftBunkerRifle',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'BunkerRifle',
            'weapon_slot'                   => 'twohand',
            'required_strength'             => 6,
            'required_level'                => 20,
            'gold_required'                 => 15000,
            'resources'                     => [
                'Редкие металлы'           => 18,
                'Доколлапсная электроника' => 3,
                'Промышленный пластик'     => 5,
            ],
            'crafted_items'                 => ['wiring' => 6],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'required_quest'                => 'StrategicCaptureBunker',
            'required_faction'              => 1, // Военные
            'duration_override_setting_key' => 'tier3.weapons.bunker_rifle.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/bunker_rifle.jpg',
            'image_completed'               => 'uploads/telegram/craft/professional/bunker_rifle.jpg',
            'start_caption_name'            => '🪖 *Бункерная винтовка (фракц.)*',
            'info_callback'                 => 'craftPreviewT3_BunkerRifle',
            'back_callback'                 => 'craftFactionWeaponsSelect',
            'item_name_eng'                 => 'BunkerRifle',
            'item_name_rus'                 => 'Бункерная винтовка',
            'icon_emoji'                    => '🪖',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.06,
            'agility_bonus'                 => 0.02,
            'craft_again_callback'          => 'genericCraft_BunkerRifle_1',
        ],
        'TechnoBeamShotgun' => [
            'task_name'                     => 'craftTechnoBeamShotgun',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'TechnoBeamShotgun',
            'weapon_slot'                   => 'twohand',
            'required_strength'             => 4,
            'required_level'                => 22,
            'gold_required'                 => 18000,
            'resources'                     => [
                'Редкие металлы'           => 16,
                'Доколлапсная электроника' => 6,
                'Промышленный пластик'     => 6,
            ],
            'crafted_items'                 => ['wiring' => 10],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'required_quest'                => 'StrategicCaptureTechnopark',
            'required_faction'              => 3, // Инженеры
            'duration_override_setting_key' => 'tier3.weapons.techno_beam_shotgun.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/techno_beam_shotgun.jpg',
            'image_completed'               => 'uploads/telegram/craft/professional/techno_beam_shotgun.jpg',
            'start_caption_name'            => '🔱 *Технолучевой дробовик (фракц.)*',
            'info_callback'                 => 'craftPreviewT3_TechnoBeamShotgun',
            'back_callback'                 => 'craftFactionWeaponsSelect',
            'item_name_eng'                 => 'TechnoBeamShotgun',
            'item_name_rus'                 => 'Технолучевой дробовик',
            'icon_emoji'                    => '🔱',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.04,
            'agility_bonus'                 => 0.03,
            'craft_again_callback'          => 'genericCraft_TechnoBeamShotgun_1',
        ],
        'GhostCityKnife' => [
            'task_name'                     => 'craftGhostCityKnife',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'GhostCityKnife',
            'weapon_slot'                   => 'hand',
            'required_strength'             => 3,
            'required_agility'              => 7,
            'required_level'                => 14,
            'gold_required'                 => 8000,
            'resources'                     => [
                'Редкие металлы'           => 10,
                'Доколлапсная электроника' => 1,
                'Промышленный пластик'     => 2,
            ],
            'crafted_items'                 => ['wiring' => 2],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'required_quest'                => 'StrategicCaptureGhostCity',
            'required_faction'              => 2, // Партизаны
            'duration_override_setting_key' => 'tier3.weapons.ghost_city_knife.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/ghost_city_knife.jpg',
            'image_completed'               => 'uploads/telegram/craft/professional/ghost_city_knife.jpg',
            'start_caption_name'            => '🗡 *Нож Города-призрака (фракц.)*',
            'info_callback'                 => 'craftPreviewT3_GhostCityKnife',
            'back_callback'                 => 'craftFactionWeaponsSelect',
            'item_name_eng'                 => 'GhostCityKnife',
            'item_name_rus'                 => 'Нож Города-призрака',
            'icon_emoji'                    => '🗡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.02,
            'agility_bonus'                 => 0.06,
            'craft_again_callback'          => 'genericCraft_GhostCityKnife_1',
        ],
        'FarmersHarvestScythe' => [
            'task_name'                     => 'craftFarmersHarvestScythe',
            'output_type'                   => 'weapon',
            'weapon_name_en'                => 'FarmersHarvestScythe',
            'weapon_slot'                   => 'twohand',
            'required_strength'             => 6,
            'required_agility'              => 2,
            'required_level'                => 16,
            'gold_required'                 => 10000,
            'resources'                     => [
                'Редкие металлы'           => 14,
                'Доколлапсная электроника' => 2,
                'Промышленный пластик'     => 4,
            ],
            'crafted_items'                 => ['wiring' => 4],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'required_quest'                => 'StrategicCaptureIslandFarm',
            'required_faction'              => 4, // Фермеры
            'duration_override_setting_key' => 'tier3.weapons.farmers_harvest_scythe.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/farmers_harvest_scythe.jpg',
            'image_completed'               => 'uploads/telegram/craft/professional/farmers_harvest_scythe.jpg',
            'start_caption_name'            => '🌾 *Коса Жатвы (фракц.)*',
            'info_callback'                 => 'craftPreviewT3_FarmersHarvestScythe',
            'back_callback'                 => 'craftFactionWeaponsSelect',
            'item_name_eng'                 => 'FarmersHarvestScythe',
            'item_name_rus'                 => 'Коса Жатвы',
            'icon_emoji'                    => '🌾',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.05,
            'agility_bonus'                 => 0.03,
            'craft_again_callback'          => 'genericCraft_FarmersHarvestScythe_1',
        ],

        // ============================================================
        // V14 (ROADMAP-vNext Фаза 3, ADR-046) — Faction-unique armor.
        //
        // Сиблинг S25 weapons (ADR-029): по одной signature-броне на фракцию,
        // симметрично signature-оружию. Каждая фракция — полный лут (оружие+броня).
        // output_type='outfit' → characters_outfits (путь S18). Gate (reuse ADR-029):
        //   required_faction + required_quest + required_crafted_items=ProfessionalWorkbench.
        // outfit_name_en совпадает с outfits.name_en (handler lookup).
        // Live-tunable длительность: tier3.faction_armor.<key>.craft_duration_hours.
        // ============================================================

        'BunkerPlateArmor' => [
            'task_name'                     => 'craftBunkerPlateArmor',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'BunkerPlateArmor',
            'outfit_slot'                   => 'body',
            'required_strength'             => 8,
            'required_level'                => 20,
            'gold_required'                 => 15000,
            'resources'                     => [
                'Редкие металлы'           => 18,
                'Доколлапсная электроника' => 3,
                'Промышленный пластик'     => 5,
            ],
            'crafted_items'                 => ['wiring' => 6],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'required_quest'                => 'StrategicCaptureBunker',
            'required_faction'              => 1, // Военные
            'duration_override_setting_key' => 'tier3.faction_armor.bunker_plate.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/bunker_plate_armor.jpg',
            'image_completed'               => 'uploads/telegram/craft/professional/bunker_plate_armor.jpg',
            'start_caption_name'            => '🛡 *Бункерная бронеплита (фракц.)*',
            'info_callback'                 => 'craftPreviewT3Armor_BunkerPlateArmor',
            'back_callback'                 => 'craftFactionArmorSelect',
            'item_name_eng'                 => 'BunkerPlateArmor',
            'item_name_rus'                 => 'Бункерная бронеплита',
            'icon_emoji'                    => '🛡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.05,
            'intellect_bonus'               => 0.01,
            'craft_again_callback'          => 'genericCraft_BunkerPlateArmor_1',
        ],
        'TechnoShieldSuit' => [
            'task_name'                     => 'craftTechnoShieldSuit',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'TechnoShieldSuit',
            'outfit_slot'                   => 'body',
            'required_strength'             => 5,
            'required_level'                => 22,
            'gold_required'                 => 18000,
            'resources'                     => [
                'Редкие металлы'           => 16,
                'Доколлапсная электроника' => 6,
                'Промышленный пластик'     => 6,
            ],
            'crafted_items'                 => ['wiring' => 10],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'required_quest'                => 'StrategicCaptureTechnopark',
            'required_faction'              => 3, // Инженеры
            'duration_override_setting_key' => 'tier3.faction_armor.techno_shield.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/techno_shield_suit.jpg',
            'image_completed'               => 'uploads/telegram/craft/professional/techno_shield_suit.jpg',
            'start_caption_name'            => '🛡 *Техно-щитовой костюм (фракц.)*',
            'info_callback'                 => 'craftPreviewT3Armor_TechnoShieldSuit',
            'back_callback'                 => 'craftFactionArmorSelect',
            'item_name_eng'                 => 'TechnoShieldSuit',
            'item_name_rus'                 => 'Техно-щитовой костюм',
            'icon_emoji'                    => '🛡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'intellect_bonus'               => 0.05,
            'agility_bonus'                 => 0.01,
            'craft_again_callback'          => 'genericCraft_TechnoShieldSuit_1',
        ],
        'GhostCityCloak' => [
            'task_name'                     => 'craftGhostCityCloak',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'GhostCityCloak',
            'outfit_slot'                   => 'body',
            'required_strength'             => 2,
            'required_agility'              => 7,
            'required_level'                => 14,
            'gold_required'                 => 8000,
            'resources'                     => [
                'Редкие металлы'           => 8,
                'Доколлапсная электроника' => 1,
                'Промышленный пластик'     => 3,
            ],
            'crafted_items'                 => ['wiring' => 2],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'required_quest'                => 'StrategicCaptureGhostCity',
            'required_faction'              => 2, // Партизаны
            'duration_override_setting_key' => 'tier3.faction_armor.ghost_city_cloak.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/ghost_city_cloak.jpg',
            'image_completed'               => 'uploads/telegram/craft/professional/ghost_city_cloak.jpg',
            'start_caption_name'            => '🛡 *Плащ Города-призрака (фракц.)*',
            'info_callback'                 => 'craftPreviewT3Armor_GhostCityCloak',
            'back_callback'                 => 'craftFactionArmorSelect',
            'item_name_eng'                 => 'GhostCityCloak',
            'item_name_rus'                 => 'Плащ Города-призрака',
            'icon_emoji'                    => '🛡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'agility_bonus'                 => 0.06,
            'intellect_bonus'               => 0.01,
            'craft_again_callback'          => 'genericCraft_GhostCityCloak_1',
        ],
        'FarmsteadGuardArmor' => [
            'task_name'                     => 'craftFarmsteadGuardArmor',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'FarmsteadGuardArmor',
            'outfit_slot'                   => 'body',
            'required_strength'             => 5,
            'required_level'                => 16,
            'gold_required'                 => 10000,
            'resources'                     => [
                'Редкие металлы'           => 14,
                'Доколлапсная электроника' => 2,
                'Промышленный пластик'     => 4,
            ],
            'crafted_items'                 => ['wiring' => 4],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'required_quest'                => 'StrategicCaptureIslandFarm',
            'required_faction'              => 4, // Фермеры
            'duration_override_setting_key' => 'tier3.faction_armor.farmstead_guard.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/farmstead_guard_armor.jpg',
            'image_completed'               => 'uploads/telegram/craft/professional/farmstead_guard_armor.jpg',
            'start_caption_name'            => '🛡 *Броня хуторской стражи (фракц.)*',
            'info_callback'                 => 'craftPreviewT3Armor_FarmsteadGuardArmor',
            'back_callback'                 => 'craftFactionArmorSelect',
            'item_name_eng'                 => 'FarmsteadGuardArmor',
            'item_name_rus'                 => 'Броня хуторской стражи',
            'icon_emoji'                    => '🛡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.04,
            'agility_bonus'                 => 0.02,
            'craft_again_callback'          => 'genericCraft_FarmsteadGuardArmor_1',
        ],

        // ============================================================
        // S18 (v0.51.200) — T3 armor (ADR-026 reusable, ROADMAP-CRAFT Фаза 4 3/5).
        //
        // 4 craft-рецепта под существующие outfits (audit-finding: 20 outfits
        // L0-L25 уже в БД на проде; реальный gap — 16 outfits без craft option).
        // Gating через recipe.required_crafted_items (ProfessionalWorkbench × 1) —
        // pattern S17 reuse. output_type='outfit' — НОВЫЙ dispatch path в
        // GenericCraftCompletionHandler::handleOutfitOutput → пишет в
        // characters_outfits (зеркало characters_weapons для S17).
        //
        // Live-tunable durations через GameSettings:
        //   tier3.armor.<key>.craft_duration_hours
        // (см. миграция S18SeedT3ArmorGameSettings).
        //
        // outfit_name_en должен совпадать с outfits.name_en в БД (handler lookup).
        // ============================================================

        'TacticalArmorSuit' => [
            'task_name'                     => 'craftTacticalArmorSuit',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'TacticalArmorSuit',
            'outfit_slot'                   => 'body',
            'required_strength'             => 8,
            'required_level'                => 16,
            'gold_required'                 => 5000,
            'resources'                     => [
                'Редкие металлы'           => 10,
                'Доколлапсная электроника' => 1,
                'Промышленный пластик'     => 4,
            ],
            'crafted_items'                 => [
                'wiring' => 3,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.armor.tactical_armor_suit.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/tactical_armor_suit.jpg',
            'start_caption_name'            => '🛡 *Тактический бронекостюм (T3)*',
            'info_callback'                 => 'craftPreviewT3Armor_TacticalArmorSuit',
            'item_name_eng'                 => 'TacticalArmorSuit',
            'item_name_rus'                 => 'Тактический бронекостюм',
            'icon_emoji'                    => '🛡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'agility_bonus'                 => 0.03,
            'intellect_bonus'               => 0.02,
            'image_completed'               => 'uploads/telegram/craft/professional/tactical_armor_suit.jpg',
            'craft_again_callback'          => 'genericCraft_TacticalArmorSuit_1',
        ],

        'ExoskeletonStrekoza' => [
            'task_name'                     => 'craftExoskeletonStrekoza',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'ExoskeletonStrekoza',
            'outfit_slot'                   => 'body',
            'required_strength'             => 6,
            'required_level'                => 16,
            'gold_required'                 => 5000,
            'resources'                     => [
                'Редкие металлы'           => 6,
                'Доколлапсная электроника' => 2,
                'Промышленный пластик'     => 4,
            ],
            'crafted_items'                 => [
                'wiring' => 5,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.armor.exoskeleton_strekoza.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/exoskeleton_strekoza.jpg',
            'start_caption_name'            => '🦋 *Экзоскелет «Стрекоза» (T3)*',
            'info_callback'                 => 'craftPreviewT3Armor_ExoskeletonStrekoza',
            'item_name_eng'                 => 'ExoskeletonStrekoza',
            'item_name_rus'                 => 'Экзоскелет «Стрекоза»',
            'icon_emoji'                    => '🦋',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'agility_bonus'                 => 0.05,
            'intellect_bonus'               => 0.02,
            'image_completed'               => 'uploads/telegram/craft/professional/exoskeleton_strekoza.jpg',
            'craft_again_callback'          => 'genericCraft_ExoskeletonStrekoza_1',
        ],

        'TitanPowerArmor' => [
            'task_name'                     => 'craftTitanPowerArmor',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'TitanPowerArmor',
            'outfit_slot'                   => 'body',
            'required_strength'             => 15,
            'required_level'                => 20,
            'gold_required'                 => 20000,
            'resources'                     => [
                'Редкие металлы'           => 20,
                'Доколлапсная электроника' => 3,
                'Промышленный пластик'     => 5,
            ],
            'crafted_items'                 => [
                'wiring' => 8,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.armor.titan_power_armor.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/titan_power_armor.jpg',
            'start_caption_name'            => '⚙️ *Силовая броня «Титан» (T3)*',
            'info_callback'                 => 'craftPreviewT3Armor_TitanPowerArmor',
            'item_name_eng'                 => 'TitanPowerArmor',
            'item_name_rus'                 => 'Силовая броня «Титан»',
            'icon_emoji'                    => '⚙️',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'agility_bonus'                 => 0.02,
            'intellect_bonus'               => 0.04,
            'image_completed'               => 'uploads/telegram/craft/professional/titan_power_armor.jpg',
            'craft_again_callback'          => 'genericCraft_TitanPowerArmor_1',
        ],

        'TeslaShardArmor' => [
            'task_name'                     => 'craftTeslaShardArmor',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'TeslaShardArmor',
            'outfit_slot'                   => 'body',
            'required_strength'             => 12,
            'required_level'                => 25,
            'gold_required'                 => 35000,
            'resources'                     => [
                'Редкие металлы'           => 22,
                'Доколлапсная электроника' => 6,
                'Промышленный пластик'     => 6,
            ],
            'crafted_items'                 => [
                'wiring' => 12,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.armor.tesla_shard_armor.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/tesla_shard_armor.jpg',
            'start_caption_name'            => '⚡ *Осколочный доспех «Тесла» (T3)*',
            'info_callback'                 => 'craftPreviewT3Armor_TeslaShardArmor',
            'item_name_eng'                 => 'TeslaShardArmor',
            'item_name_rus'                 => 'Осколочный доспех «Тесла»',
            'icon_emoji'                    => '⚡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'agility_bonus'                 => 0.04,
            'intellect_bonus'               => 0.04,
            'image_completed'               => 'uploads/telegram/craft/professional/tesla_shard_armor.jpg',
            'craft_again_callback'          => 'genericCraft_TeslaShardArmor_1',
        ],

        // E16 Ф2 (ADR-116/121) — грандмастер-броня (гейт L40, синк севера; см. блок выше у HydraPlasmaCannon).
        'JuggernautBattleArmor' => [
            'task_name'                     => 'craftJuggernautBattleArmor',
            'output_type'                   => 'outfit',
            'outfit_name_en'                => 'JuggernautBattleArmor',
            'outfit_slot'                   => 'body',
            'required_strength'             => 16,
            'required_level'                => 40,
            'gold_required'                 => 55000,
            'resources'                     => [
                'Пепел Предтеч'    => 12,
                'Кристалл Разлома' => 6,
                'Древние реликвии' => 4,
                'Редкие металлы'   => 28,
            ],
            'crafted_items'                 => [
                'wiring' => 14,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.armor.juggernaut_battle_armor.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/juggernaut_battle_armor.jpg',
            'start_caption_name'            => '🛡 *Боевая броня «Джаггернаут» (Грандмастер)*',
            'info_callback'                 => 'craftPreviewT3Armor_JuggernautBattleArmor',
            'item_name_eng'                 => 'JuggernautBattleArmor',
            'item_name_rus'                 => 'Боевая броня «Джаггернаут»',
            'icon_emoji'                    => '🛡',
            'zone_emoji'                    => '🏚️',
            'zone_name'                     => 'база',
            'strength_bonus'                => 0.05,
            'intellect_bonus'               => 0.03,
            'image_completed'               => 'uploads/telegram/craft/professional/juggernaut_battle_armor.jpg',
            'craft_again_callback'          => 'genericCraft_JuggernautBattleArmor_1',
        ],

        // ============================================================
        // S19 (v0.51.201) — T3 medical (ADR-026 reusable, ROADMAP-CRAFT Фаза 4 4/5).
        //
        // 3 НОВЫХ drug-предмета (audit-finding: в отличие от S17/S18, этих
        // предметов НЕ было в БД — genuine new content). output_type НЕ задан →
        // default 'crafted_item' path в GenericCraftCompletionHandler (пишет в
        // crafted_items_log, type='drug'). Gating через required_crafted_items
        // (ProfessionalWorkbench × 1) — pattern S17/S18.
        //
        // Фактический heal-эффект применяется в UsePharmacyAction через
        // GameSettings (medical.<key>.heal_health / .heal_tired, category=combat),
        // НЕ через recipe — admin-tunable (constitutional). agility/intellect_bonus
        // тут — пассивный bump при крафте (как у всех crafted_item рецептов).
        //
        // Длительность — live-tunable: tier3.medical.<key>.craft_duration_hours.
        // ============================================================

        'SyntheticMedicine' => [
            'task_name'                     => 'craftSyntheticMedicine',
            'required_level'                => 20,
            'gold_required'                 => 6000,
            'resources'                     => [
                'Редкие металлы'           => 4,
                'Доколлапсная электроника' => 2,
                'Промышленный пластик'     => 2,
            ],
            'crafted_items'                 => [
                'wiring' => 2,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.medical.synthetic_medicine.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/synthetic_medicine.jpg',
            'start_caption_name'            => '💉 *Синтетическое лекарство (T3)*',
            'info_callback'                 => 'craftPreviewT3Medical_SyntheticMedicine',
            'item_name_eng'                 => 'SyntheticMedicine',
            'item_name_rus'                 => 'Синтетическое лекарство',
            'icon_emoji'                    => '💉',
            'zone_emoji'                    => '💊',
            'zone_name'                     => 'медицина',
            'agility_bonus'                 => 0.02,
            'intellect_bonus'               => 0.04,
            'image_completed'               => 'uploads/telegram/craft/professional/synthetic_medicine.jpg',
            'craft_again_callback'          => 'genericCraft_SyntheticMedicine_1',
        ],

        'EmergencyTransfusion' => [
            'task_name'                     => 'craftEmergencyTransfusion',
            'required_level'                => 18,
            'gold_required'                 => 3000,
            'resources'                     => [
                'Редкие металлы'           => 2,
                'Доколлапсная электроника' => 1,
            ],
            'crafted_items'                 => [
                'wiring' => 1,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.medical.emergency_transfusion.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/emergency_transfusion.jpg',
            'start_caption_name'            => '🩸 *Экстренное переливание (T3)*',
            'info_callback'                 => 'craftPreviewT3Medical_EmergencyTransfusion',
            'item_name_eng'                 => 'EmergencyTransfusion',
            'item_name_rus'                 => 'Экстренное переливание',
            'icon_emoji'                    => '🩸',
            'zone_emoji'                    => '💊',
            'zone_name'                     => 'медицина',
            'agility_bonus'                 => 0.02,
            'intellect_bonus'               => 0.03,
            'image_completed'               => 'uploads/telegram/craft/professional/emergency_transfusion.jpg',
            'craft_again_callback'          => 'genericCraft_EmergencyTransfusion_1',
        ],

        'SurgicalKit' => [
            'task_name'                     => 'craftSurgicalKit',
            'required_level'                => 22,
            'gold_required'                 => 9000,
            'resources'                     => [
                'Редкие металлы'           => 6,
                'Доколлапсная электроника' => 3,
                'Промышленный пластик'     => 4,
            ],
            'crafted_items'                 => [
                'wiring' => 4,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.medical.surgical_kit.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/surgical_kit.jpg',
            'start_caption_name'            => '🧰 *Хирургический набор (T3)*',
            'info_callback'                 => 'craftPreviewT3Medical_SurgicalKit',
            'item_name_eng'                 => 'SurgicalKit',
            'item_name_rus'                 => 'Хирургический набор',
            'icon_emoji'                    => '🧰',
            'zone_emoji'                    => '💊',
            'zone_name'                     => 'медицина',
            'agility_bonus'                 => 0.03,
            'intellect_bonus'               => 0.05,
            'image_completed'               => 'uploads/telegram/craft/professional/surgical_kit.jpg',
            'craft_again_callback'          => 'genericCraft_SurgicalKit_1',
        ],

        // ============================================================
        // S20 (v0.51.202) — T3 utility tools (ADR-026 reusable, ROADMAP-CRAFT Фаза 4 5/5 — закрывает фазу).
        //
        // 3 craft-рецепта под existing tool-предметы (audit: в отличие от ROADMAP
        // CombinationTool/PortableForge/AdvancedFishingNet — их нет; реальный gap =
        // existing tools без craft):
        //   - DiamondPickaxe — УЖЕ в ToolManager bonus-map (работает, owned), без рецепта.
        //   - Sapper Shovel / Golden Hoe — в БД, но НЕ в map → оживлены через
        //     GameSettings overlay в ToolManager (S20SeedToolBonusGameSettings).
        //
        // output_type НЕ задан → default crafted_item path (type='tool' → crafted_items_log).
        // item_name_eng совпадает с crafted_items.name_eng (С ПРОБЕЛАМИ для 2 из 3).
        // Gating: required_crafted_items=ProfessionalWorkbench (pattern S17-S19).
        // Длительность: tier3.utility.<key>.craft_duration_hours (live-tunable).
        // ============================================================

        'DiamondPickaxe' => [
            'task_name'                     => 'craftDiamondPickaxe',
            'required_level'                => 20,
            'gold_required'                 => 12000,
            'resources'                     => [
                'Редкие металлы'           => 12,
                'Доколлапсная электроника' => 1,
                'Промышленный пластик'     => 2,
            ],
            'crafted_items'                 => [
                'wiring' => 3,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.utility.diamond_pickaxe.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/diamond_pickaxe.jpg',
            'start_caption_name'            => '⛏️ *Алмазная кирка (T3)*',
            'info_callback'                 => 'craftPreviewT3Utility_DiamondPickaxe',
            'item_name_eng'                 => 'DiamondPickaxe',
            'item_name_rus'                 => 'Алмазная кирка',
            'icon_emoji'                    => '⛏️',
            'zone_emoji'                    => '🛠️',
            'zone_name'                     => 'инструменты',
            'agility_bonus'                 => 0.03,
            'intellect_bonus'               => 0.02,
            'image_completed'               => 'uploads/telegram/craft/professional/diamond_pickaxe.jpg',
            'craft_again_callback'          => 'genericCraft_DiamondPickaxe_1',
        ],

        'SapperShovel' => [
            'task_name'                     => 'craftSapperShovel',
            'required_level'                => 16,
            'gold_required'                 => 6000,
            'resources'                     => [
                'Редкие металлы'       => 8,
                'Промышленный пластик' => 3,
            ],
            'crafted_items'                 => [
                'wiring' => 2,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.utility.sapper_shovel.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/sapper_shovel.jpg',
            'start_caption_name'            => '🪏 *Сапёрная лопата (T3)*',
            'info_callback'                 => 'craftPreviewT3Utility_SapperShovel',
            // ВНИМАНИЕ: crafted_items.name_eng = 'Sapper Shovel' (С ПРОБЕЛОМ).
            'item_name_eng'                 => 'Sapper Shovel',
            'item_name_rus'                 => 'Сапёрная лопата',
            'icon_emoji'                    => '🪏',
            'zone_emoji'                    => '🛠️',
            'zone_name'                     => 'инструменты',
            'agility_bonus'                 => 0.02,
            'intellect_bonus'               => 0.02,
            'image_completed'               => 'uploads/telegram/craft/professional/sapper_shovel.jpg',
            'craft_again_callback'          => 'genericCraft_SapperShovel_1',
        ],

        'GoldenHoe' => [
            'task_name'                     => 'craftGoldenHoe',
            'required_level'                => 16,
            'gold_required'                 => 5000,
            'resources'                     => [
                'Редкие металлы'       => 6,
                'Промышленный пластик' => 2,
            ],
            'crafted_items'                 => [
                'wiring' => 2,
            ],
            'requires_base'                 => true,
            'required_crafted_items'        => ['ProfessionalWorkbench' => 1],
            'duration_override_setting_key' => 'tier3.utility.golden_hoe.craft_duration_hours',
            'image_in_progress'             => 'uploads/telegram/craft/professional/golden_hoe.jpg',
            'start_caption_name'            => '🌾 *Золотая мотыга (T3)*',
            'info_callback'                 => 'craftPreviewT3Utility_GoldenHoe',
            // ВНИМАНИЕ: crafted_items.name_eng = 'Golden Hoe' (С ПРОБЕЛОМ).
            'item_name_eng'                 => 'Golden Hoe',
            'item_name_rus'                 => 'Золотая мотыга',
            'icon_emoji'                    => '🌾',
            'zone_emoji'                    => '🛠️',
            'zone_name'                     => 'инструменты',
            'agility_bonus'                 => 0.02,
            'intellect_bonus'               => 0.02,
            'image_completed'               => 'uploads/telegram/craft/professional/golden_hoe.jpg',
            'craft_again_callback'          => 'genericCraft_GoldenHoe_1',
        ],

        // === S28 (ADR-032) — Зимний сезонный pilot (5 warming consumables) ===
        // required_season='winter' → gate в GenericCraftActionStart + меню SeasonalCraftSelect.
        // Heal через UsePharmacyAction (GameSettings medical.<snake>.heal_*). Низкий гейт.
        'WinterHerbalBrew' => [
            'task_name'            => 'craftWinterHerbalBrew',
            'required_season'      => 'winter',
            'resources'            => ['Древесина' => 20, 'Травы' => 5],
            'crafted_items'        => [],
            'gold_required'        => 50,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/winter_herbal_brew.jpg',
            'start_caption_name'   => '🍵 *Горячий травяной отвар*',
            'info_callback'        => 'craftPreviewSeasonal_WinterHerbalBrew',
            'item_name_eng'        => 'WinterHerbalBrew',
            'item_name_rus'        => 'Горячий травяной отвар',
            'icon_emoji'           => '🍵',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/winter_herbal_brew.jpg',
            'craft_again_callback' => 'genericCraft_WinterHerbalBrew_1',
        ],
        'WinterHoneyMead' => [
            'task_name'            => 'craftWinterHoneyMead',
            'required_season'      => 'winter',
            'resources'            => ['Древесина' => 25, 'Травы' => 4],
            'crafted_items'        => [],
            'gold_required'        => 60,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/winter_honey_mead.jpg',
            'start_caption_name'   => '🍯 *Медовый сбитень*',
            'info_callback'        => 'craftPreviewSeasonal_WinterHoneyMead',
            'item_name_eng'        => 'WinterHoneyMead',
            'item_name_rus'        => 'Медовый сбитень',
            'icon_emoji'           => '🍯',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/winter_honey_mead.jpg',
            'craft_again_callback' => 'genericCraft_WinterHoneyMead_1',
        ],
        'WinterWarmingBalm' => [
            'task_name'            => 'craftWinterWarmingBalm',
            'required_season'      => 'winter',
            'resources'            => ['Древесина' => 15, 'Травы' => 6],
            'crafted_items'        => [],
            'gold_required'        => 80,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/winter_warming_balm.jpg',
            'start_caption_name'   => '🧴 *Согревающую мазь*',
            'info_callback'        => 'craftPreviewSeasonal_WinterWarmingBalm',
            'item_name_eng'        => 'WinterWarmingBalm',
            'item_name_rus'        => 'Согревающая мазь',
            'icon_emoji'           => '🧴',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/winter_warming_balm.jpg',
            'craft_again_callback' => 'genericCraft_WinterWarmingBalm_1',
        ],
        'WinterCampStew' => [
            'task_name'            => 'craftWinterCampStew',
            'required_season'      => 'winter',
            'resources'            => ['Древесина' => 25, 'Грибы' => 4],
            'crafted_items'        => [],
            'gold_required'        => 70,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/winter_camp_stew.jpg',
            'start_caption_name'   => '🥘 *Походную похлёбку*',
            'info_callback'        => 'craftPreviewSeasonal_WinterCampStew',
            'item_name_eng'        => 'WinterCampStew',
            'item_name_rus'        => 'Походная похлёбка',
            'icon_emoji'           => '🥘',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/winter_camp_stew.jpg',
            'craft_again_callback' => 'genericCraft_WinterCampStew_1',
        ],
        'WinterPreserves' => [
            'task_name'            => 'craftWinterPreserves',
            'required_season'      => 'winter',
            'resources'            => ['Древесина' => 20, 'Глина' => 8],
            'crafted_items'        => [],
            'gold_required'        => 75,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/winter_preserves.jpg',
            'start_caption_name'   => '🫙 *Зимние заготовки*',
            'info_callback'        => 'craftPreviewSeasonal_WinterPreserves',
            'item_name_eng'        => 'WinterPreserves',
            'item_name_rus'        => 'Зимние заготовки',
            'icon_emoji'           => '🫙',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/winter_preserves.jpg',
            'craft_again_callback' => 'genericCraft_WinterPreserves_1',
        ],

        // === V1 (ADR-032, vNext) — Весенний сезон «Весеннее пробуждение» (5 освежающих consumable) ===
        // required_season='spring' → gate в GenericCraftActionStart + меню SeasonalCraftSelect.
        // Heal через UsePharmacyAction (GameSettings medical.<snake>.heal_*). Низкий гейт.
        // Активен ~10 июня 2026 (anchor 2026-05-20 + 21д Зима). Картинки — батч V5 (text-fallback до тех пор).
        'SpringFirstHerbTea' => [
            'task_name'            => 'craftSpringFirstHerbTea',
            'required_season'      => 'spring',
            'resources'            => ['Травы' => 8, 'Древесина' => 10],
            'crafted_items'        => [],
            'gold_required'        => 50,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/spring_first_herb_tea.jpg',
            'start_caption_name'   => '🌿 *Чай из первой травы*',
            'info_callback'        => 'craftPreviewSeasonal_SpringFirstHerbTea',
            'item_name_eng'        => 'SpringFirstHerbTea',
            'item_name_rus'        => 'Чай из первой травы',
            'icon_emoji'           => '🌿',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/spring_first_herb_tea.jpg',
            'craft_again_callback' => 'genericCraft_SpringFirstHerbTea_1',
        ],
        'SpringBirchSap' => [
            'task_name'            => 'craftSpringBirchSap',
            'required_season'      => 'spring',
            'resources'            => ['Древесина' => 25, 'Травы' => 4],
            'crafted_items'        => [],
            'gold_required'        => 60,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/spring_birch_sap.jpg',
            'start_caption_name'   => '🍯 *Берёзовый сок*',
            'info_callback'        => 'craftPreviewSeasonal_SpringBirchSap',
            'item_name_eng'        => 'SpringBirchSap',
            'item_name_rus'        => 'Берёзовый сок',
            'icon_emoji'           => '🍯',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/spring_birch_sap.jpg',
            'craft_again_callback' => 'genericCraft_SpringBirchSap_1',
        ],
        'SpringPrimroseInfusion' => [
            'task_name'            => 'craftSpringPrimroseInfusion',
            'required_season'      => 'spring',
            'resources'            => ['Травы' => 10, 'Древесина' => 8],
            'crafted_items'        => [],
            'gold_required'        => 80,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/spring_primrose_infusion.jpg',
            'start_caption_name'   => '🌸 *Настой первоцвета*',
            'info_callback'        => 'craftPreviewSeasonal_SpringPrimroseInfusion',
            'item_name_eng'        => 'SpringPrimroseInfusion',
            'item_name_rus'        => 'Настой первоцвета',
            'icon_emoji'           => '🌸',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/spring_primrose_infusion.jpg',
            'craft_again_callback' => 'genericCraft_SpringPrimroseInfusion_1',
        ],
        'SpringWildGreens' => [
            'task_name'            => 'craftSpringWildGreens',
            'required_season'      => 'spring',
            'resources'            => ['Травы' => 6, 'Грибы' => 4],
            'crafted_items'        => [],
            'gold_required'        => 60,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/spring_wild_greens.jpg',
            'start_caption_name'   => '🥗 *Весеннюю зелень*',
            'info_callback'        => 'craftPreviewSeasonal_SpringWildGreens',
            'item_name_eng'        => 'SpringWildGreens',
            'item_name_rus'        => 'Весенняя зелень',
            'icon_emoji'           => '🥗',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/spring_wild_greens.jpg',
            'craft_again_callback' => 'genericCraft_SpringWildGreens_1',
        ],
        'SpringShootsDecoction' => [
            'task_name'            => 'craftSpringShootsDecoction',
            'required_season'      => 'spring',
            'resources'            => ['Травы' => 7, 'Древесина' => 12],
            'crafted_items'        => [],
            'gold_required'        => 70,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/spring_shoots_decoction.jpg',
            'start_caption_name'   => '🌱 *Отвар молодых побегов*',
            'info_callback'        => 'craftPreviewSeasonal_SpringShootsDecoction',
            'item_name_eng'        => 'SpringShootsDecoction',
            'item_name_rus'        => 'Отвар молодых побегов',
            'icon_emoji'           => '🌱',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/spring_shoots_decoction.jpg',
            'craft_again_callback' => 'genericCraft_SpringShootsDecoction_1',
        ],

        // === V2 (ADR-032, vNext) — Летний сезон «Летняя жара» (5 прохладительных consumable) ===
        // required_season='summer' → gate в GenericCraftActionStart + меню SeasonalCraftSelect.
        // Heal через UsePharmacyAction (GameSettings medical.<snake>.heal_*). Низкий гейт.
        // Активен ~1 июля 2026 (anchor 2026-05-20 + 42д Зима+Весна). Картинки — батч V5 (text-fallback).
        'SummerColdKvass' => [
            'task_name'            => 'craftSummerColdKvass',
            'required_season'      => 'summer',
            'resources'            => ['Зерновые культуры' => 8, 'Вода' => 10],
            'crafted_items'        => [],
            'gold_required'        => 50,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/summer_cold_kvass.jpg',
            'start_caption_name'   => '🧊 *Холодный квас*',
            'info_callback'        => 'craftPreviewSeasonal_SummerColdKvass',
            'item_name_eng'        => 'SummerColdKvass',
            'item_name_rus'        => 'Холодный квас',
            'icon_emoji'           => '🧊',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/summer_cold_kvass.jpg',
            'craft_again_callback' => 'genericCraft_SummerColdKvass_1',
        ],
        'SummerBerryMors' => [
            'task_name'            => 'craftSummerBerryMors',
            'required_season'      => 'summer',
            'resources'            => ['Ягоды' => 8, 'Вода' => 10],
            'crafted_items'        => [],
            'gold_required'        => 60,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/summer_berry_mors.jpg',
            'start_caption_name'   => '🥤 *Лесной морс*',
            'info_callback'        => 'craftPreviewSeasonal_SummerBerryMors',
            'item_name_eng'        => 'SummerBerryMors',
            'item_name_rus'        => 'Лесной морс',
            'icon_emoji'           => '🥤',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/summer_berry_mors.jpg',
            'craft_again_callback' => 'genericCraft_SummerBerryMors_1',
        ],
        'SummerFruitWater' => [
            'task_name'            => 'craftSummerFruitWater',
            'required_season'      => 'summer',
            'resources'            => ['Фрукты' => 6, 'Вода' => 12],
            'crafted_items'        => [],
            'gold_required'        => 60,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/summer_fruit_water.jpg',
            'start_caption_name'   => '🍉 *Фруктовую воду*',
            'info_callback'        => 'craftPreviewSeasonal_SummerFruitWater',
            'item_name_eng'        => 'SummerFruitWater',
            'item_name_rus'        => 'Фруктовая вода',
            'icon_emoji'           => '🍉',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/summer_fruit_water.jpg',
            'craft_again_callback' => 'genericCraft_SummerFruitWater_1',
        ],
        'SummerMintTea' => [
            'task_name'            => 'craftSummerMintTea',
            'required_season'      => 'summer',
            'resources'            => ['Травы' => 8, 'Вода' => 10],
            'crafted_items'        => [],
            'gold_required'        => 70,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/summer_mint_tea.jpg',
            'start_caption_name'   => '🌿 *Мятный отвар*',
            'info_callback'        => 'craftPreviewSeasonal_SummerMintTea',
            'item_name_eng'        => 'SummerMintTea',
            'item_name_rus'        => 'Мятный отвар',
            'icon_emoji'           => '🌿',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/summer_mint_tea.jpg',
            'craft_again_callback' => 'genericCraft_SummerMintTea_1',
        ],
        'SummerAloeBalm' => [
            'task_name'            => 'craftSummerAloeBalm',
            'required_season'      => 'summer',
            'resources'            => ['Алоэ' => 5, 'Вода' => 8],
            'crafted_items'        => [],
            'gold_required'        => 80,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/summer_aloe_balm.jpg',
            'start_caption_name'   => '🧴 *Алоэ-бальзам*',
            'info_callback'        => 'craftPreviewSeasonal_SummerAloeBalm',
            'item_name_eng'        => 'SummerAloeBalm',
            'item_name_rus'        => 'Алоэ-бальзам',
            'icon_emoji'           => '🧴',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/summer_aloe_balm.jpg',
            'craft_again_callback' => 'genericCraft_SummerAloeBalm_1',
        ],

        // === V3 (ADR-032, vNext) — Осенний сезон «Осенняя жатва» (5 урожайных consumable) ===
        // required_season='autumn' → gate в GenericCraftActionStart + меню SeasonalCraftSelect.
        // Heal через UsePharmacyAction (GameSettings medical.<snake>.heal_*). Низкий гейт.
        // Активен ~22 июля 2026 (anchor 2026-05-20 + 63д Зима+Весна+Лето). Картинки — батч V5.
        'AutumnBerryJam' => [
            'task_name'            => 'craftAutumnBerryJam',
            'required_season'      => 'autumn',
            'resources'            => ['Ягоды' => 8, 'Мед' => 4],
            'crafted_items'        => [],
            'gold_required'        => 50,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/autumn_berry_jam.jpg',
            'start_caption_name'   => '🍯 *Ягодное варенье*',
            'info_callback'        => 'craftPreviewSeasonal_AutumnBerryJam',
            'item_name_eng'        => 'AutumnBerryJam',
            'item_name_rus'        => 'Ягодное варенье',
            'icon_emoji'           => '🍯',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/autumn_berry_jam.jpg',
            'craft_again_callback' => 'genericCraft_AutumnBerryJam_1',
        ],
        'AutumnMushroomStew' => [
            'task_name'            => 'craftAutumnMushroomStew',
            'required_season'      => 'autumn',
            'resources'            => ['Грибы' => 8, 'Овощи' => 5],
            'crafted_items'        => [],
            'gold_required'        => 60,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/autumn_mushroom_stew.jpg',
            'start_caption_name'   => '🍄 *Грибное рагу*',
            'info_callback'        => 'craftPreviewSeasonal_AutumnMushroomStew',
            'item_name_eng'        => 'AutumnMushroomStew',
            'item_name_rus'        => 'Грибное рагу',
            'icon_emoji'           => '🍄',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/autumn_mushroom_stew.jpg',
            'craft_again_callback' => 'genericCraft_AutumnMushroomStew_1',
        ],
        'AutumnNutMix' => [
            'task_name'            => 'craftAutumnNutMix',
            'required_season'      => 'autumn',
            'resources'            => ['Орехи' => 8, 'Мед' => 4],
            'crafted_items'        => [],
            'gold_required'        => 60,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/autumn_nut_mix.jpg',
            'start_caption_name'   => '🌰 *Ореховую смесь*',
            'info_callback'        => 'craftPreviewSeasonal_AutumnNutMix',
            'item_name_eng'        => 'AutumnNutMix',
            'item_name_rus'        => 'Ореховая смесь',
            'icon_emoji'           => '🌰',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/autumn_nut_mix.jpg',
            'craft_again_callback' => 'genericCraft_AutumnNutMix_1',
        ],
        'AutumnCider' => [
            'task_name'            => 'craftAutumnCider',
            'required_season'      => 'autumn',
            'resources'            => ['Фрукты' => 6, 'Вода' => 10],
            'crafted_items'        => [],
            'gold_required'        => 70,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/autumn_cider.jpg',
            'start_caption_name'   => '🍎 *Сидр*',
            'info_callback'        => 'craftPreviewSeasonal_AutumnCider',
            'item_name_eng'        => 'AutumnCider',
            'item_name_rus'        => 'Сидр',
            'icon_emoji'           => '🍎',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/autumn_cider.jpg',
            'craft_again_callback' => 'genericCraft_AutumnCider_1',
        ],
        'AutumnVegPreserves' => [
            'task_name'            => 'craftAutumnVegPreserves',
            'required_season'      => 'autumn',
            'resources'            => ['Овощи' => 8, 'Глина' => 6],
            'crafted_items'        => [],
            'gold_required'        => 75,
            'required_level'       => 1,
            'image_in_progress'    => 'uploads/telegram/craft/seasonal/autumn_veg_preserves.jpg',
            'start_caption_name'   => '🥫 *Овощные консервы*',
            'info_callback'        => 'craftPreviewSeasonal_AutumnVegPreserves',
            'item_name_eng'        => 'AutumnVegPreserves',
            'item_name_rus'        => 'Овощные консервы',
            'icon_emoji'           => '🥫',
            'zone_emoji'           => '🗓',
            'zone_name'            => 'сезонное',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/seasonal/autumn_veg_preserves.jpg',
            'craft_again_callback' => 'genericCraft_AutumnVegPreserves_1',
        ],

        // ── V8 (vNext, Фаза 2) — Campfire cooking: блюда из фарм-урожая. ──────
        // type='drug' (heal через UsePharmacyAction + medical.<snake>.heal_*),
        // crafting_location='Campfire'. Низкий гейт (готовить где угодно).
        // Ингредиенты = урожай V6 (Ягоды/Грибы/Фрукты/Зерновые) + Вода.
        'MushroomSoup' => [
            'task_name'            => 'craftMushroomSoup',
            'perishable'           => true,
            'resources'            => ['Грибы' => 4, 'Вода' => 2],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/mushroom_soup.jpg',
            'start_caption_name'   => '🍄 *Грибная похлёбка*',
            'info_callback'        => 'cook',
            'item_name_eng'        => 'MushroomSoup',
            'item_name_rus'        => 'Грибная похлёбка',
            'icon_emoji'           => '🍄',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'готовка',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/cooking/mushroom_soup.jpg',
            'craft_again_callback' => 'genericCraft_MushroomSoup_1',
        ],
        'BerryBrew' => [
            'task_name'            => 'craftBerryBrew',
            'perishable'           => true,
            'resources'            => ['Ягоды' => 4, 'Вода' => 2],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/berry_brew.jpg',
            'start_caption_name'   => '🫐 *Ягодный взвар*',
            'info_callback'        => 'cook',
            'item_name_eng'        => 'BerryBrew',
            'item_name_rus'        => 'Ягодный взвар',
            'icon_emoji'           => '🫐',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'готовка',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/cooking/berry_brew.jpg',
            'craft_again_callback' => 'genericCraft_BerryBrew_1',
        ],
        'BakedFruit' => [
            'task_name'            => 'craftBakedFruit',
            'perishable'           => true,
            'resources'            => ['Фрукты' => 5],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/baked_fruit.jpg',
            'start_caption_name'   => '🍎 *Печёные фрукты*',
            'info_callback'        => 'cook',
            'item_name_eng'        => 'BakedFruit',
            'item_name_rus'        => 'Печёные фрукты',
            'icon_emoji'           => '🍎',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'готовка',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/cooking/baked_fruit.jpg',
            'craft_again_callback' => 'genericCraft_BakedFruit_1',
        ],
        'GrainPorridge' => [
            'task_name'            => 'craftGrainPorridge',
            'perishable'           => true,
            'resources'            => ['Зерновые культуры' => 4, 'Вода' => 2],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/grain_porridge.jpg',
            'start_caption_name'   => '🥣 *Зерновая каша*',
            'info_callback'        => 'cook',
            'item_name_eng'        => 'GrainPorridge',
            'item_name_rus'        => 'Зерновая каша',
            'icon_emoji'           => '🥣',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'готовка',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/cooking/grain_porridge.jpg',
            'craft_again_callback' => 'genericCraft_GrainPorridge_1',
        ],
        'HeartyStew' => [
            'task_name'            => 'craftHeartyStew',
            'perishable'           => true,
            'resources'            => ['Грибы' => 3, 'Зерновые культуры' => 3, 'Фрукты' => 2, 'Вода' => 2],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/hearty_stew.jpg',
            'start_caption_name'   => '🍲 *Сытное рагу*',
            'info_callback'        => 'cook',
            'item_name_eng'        => 'HeartyStew',
            'item_name_rus'        => 'Сытное рагу',
            'icon_emoji'           => '🍲',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'готовка',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/cooking/hearty_stew.jpg',
            'craft_again_callback' => 'genericCraft_HeartyStew_1',
        ],

        // ── V10 (vNext, Фаза 2 закрытие) — консервы: shelf-stable заготовки. ──
        // preserved=true → НЕ черствеют (GenericCraftCompletionHandler не ставит
        // durability_time). Дольше «Сытость», дороже ингредиенты. crafting_location='Campfire'.
        'StewPreserve' => [
            'task_name'            => 'craftStewPreserve',
            'preserved'            => true,
            // Мясо — обязательное (имя, описание «Мясо-овощная тушёнка» и арт с банками
            // мясного рагу обещают его). До этого рецепт был грибы+зерно+вода — игрок
            // поймал drift шуткой «автор веган?». Заодно «Мясо диких животных» получает
            // второе применение (было одно — Регенератор). Anti-drift: CookingRecipesTest.
            'resources'            => ['Мясо диких животных' => 2, 'Грибы' => 2, 'Зерновые культуры' => 3, 'Вода' => 2],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/stew_preserve.jpg',
            'start_caption_name'   => '🥫 *Тушёнка*',
            'info_callback'        => 'cookPreserves',
            'item_name_eng'        => 'StewPreserve',
            'item_name_rus'        => 'Тушёнка',
            'icon_emoji'           => '🥫',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'консервация',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/cooking/stew_preserve.jpg',
            'craft_again_callback' => 'genericCraft_StewPreserve_1',
        ],
        'DryRation' => [
            'task_name'            => 'craftDryRation',
            'preserved'            => true,
            'resources'            => ['Фрукты' => 4, 'Ягоды' => 3],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/dry_ration.jpg',
            'start_caption_name'   => '🎒 *Сухпаёк*',
            'info_callback'        => 'cookPreserves',
            'item_name_eng'        => 'DryRation',
            'item_name_rus'        => 'Сухпаёк',
            'icon_emoji'           => '🎒',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'консервация',
            'agility_bonus'        => 0.01,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/cooking/dry_ration.jpg',
            'craft_again_callback' => 'genericCraft_DryRation_1',
        ],

        // ── W23 (ADR-078) — рыбные блюда: дают «Рыбе» (давно добываемой в Реках)
        // кулинарное применение. Гейтятся killswitch cooking.fish_dishes.enabled
        // (CampfireCookingSelect). Картинки — art-tail при активации (placeholder сейчас). ──
        'FishSoup' => [
            'task_name'            => 'craftFishSoup',
            'perishable'           => true,
            'resources'            => ['Рыба' => 3, 'Вода' => 2, 'Травы' => 1],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/fish_soup.jpg',
            'start_caption_name'   => '🐟 *Уха*',
            'info_callback'        => 'cook',
            'item_name_eng'        => 'FishSoup',
            'item_name_rus'        => 'Уха',
            'icon_emoji'           => '🐟',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'готовка',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/cooking/fish_soup.jpg',
            'craft_again_callback' => 'genericCraft_FishSoup_1',
        ],
        'GrilledFish' => [
            'task_name'            => 'craftGrilledFish',
            'perishable'           => true,
            'resources'            => ['Рыба' => 4, 'Травы' => 1],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/grilled_fish.jpg',
            'start_caption_name'   => '🔥 *Жареная рыба*',
            'info_callback'        => 'cook',
            'item_name_eng'        => 'GrilledFish',
            'item_name_rus'        => 'Жареная рыба',
            'icon_emoji'           => '🔥',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'готовка',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/cooking/grilled_fish.jpg',
            'craft_again_callback' => 'genericCraft_GrilledFish_1',
        ],
        'FishPreserve' => [
            'task_name'            => 'craftFishPreserve',
            'preserved'            => true,
            'resources'            => ['Рыба' => 5, 'Вода' => 2],
            'crafted_items'        => [],
            'image_in_progress'    => 'uploads/telegram/craft/cooking/fish_preserve.jpg',
            'start_caption_name'   => '🥫 *Рыбные консервы*',
            'info_callback'        => 'cookPreserves',
            'item_name_eng'        => 'FishPreserve',
            'item_name_rus'        => 'Рыбные консервы',
            'icon_emoji'           => '🥫',
            'zone_emoji'           => '🔥',
            'zone_name'            => 'консервация',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/cooking/fish_preserve.jpg',
            'craft_again_callback' => 'genericCraft_FishPreserve_1',
        ],

        // ============================================================
        // transport-06 (ADR-174, docs/specs/transport-system/) — пять машин.
        // Числа бонусов (скорость/усталость/груз) НЕ живут здесь — они в
        // GameSettings `world.vehicle.*` (story 02, ключи cart/mtb/snowmobile/
        // draft_cart/drone_auto). Здесь только вход рецепта, время, гейты, цена.
        // `item_name_eng` зафиксирован контрактом плана 1:1 с ключом рецепта —
        // story 04 строит по нему карту профилей машин.
        // Вход у каждой сверен с ADR-157 (price × 1.10 ≤ gold + сырьё):
        // проверяется тестом tests/unit/Transport/VehicleRecipesTest.php.
        // Картинки сгенерены story 07 (Config\ImageRegistry, craft/vehicles/*,
        // status=generated) — у каждой машины своя, плейсхолдер верстака снят.
        // ============================================================

        'LightCart' => [
            'task_name'            => 'craftLightCart',
            'resources'            => [
                'Древесина' => 30,
            ],
            'crafted_items'        => [
                'Fabric' => 3,
            ],
            'required_level'       => 6,
            'image_in_progress'    => 'uploads/telegram/craft/vehicles/light_cart.jpg',
            'start_caption_name'   => '🛒 *Лёгкую повозку*',

            'item_name_eng'        => 'LightCart',
            'item_name_rus'        => 'Лёгкая повозка',
            'icon_emoji'           => '🛒',
            'zone_emoji'           => '🚚',
            'zone_name'            => 'транспорт',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/vehicles/light_cart.jpg',
            'craft_again_callback' => 'genericCraft_LightCart_1',
        ],

        'MountainBike' => [
            'task_name'            => 'craftMountainBike',
            'resources'            => [
                'Древесина'      => 70,
                'Шкура животных' => 60,
            ],
            'crafted_items'        => [],
            'required_level'       => 12,
            'required_faction'     => 2, // Партизаны
            'image_in_progress'    => 'uploads/telegram/craft/vehicles/mountain_bike.jpg',
            'start_caption_name'   => '🚲 *Горный велосипед*',

            'item_name_eng'        => 'MountainBike',
            'item_name_rus'        => 'Горный велосипед',
            'icon_emoji'           => '🚲',
            'zone_emoji'           => '🚚',
            'zone_name'            => 'транспорт',
            'agility_bonus'        => 0.04,
            'intellect_bonus'      => 0.01,
            'image_completed'      => 'uploads/telegram/craft/vehicles/mountain_bike.jpg',
            'craft_again_callback' => 'genericCraft_MountainBike_1',
        ],

        'Snowmobile' => [
            'task_name'            => 'craftSnowmobile',
            'resources'            => [
                'Нефть' => 10,
            ],
            'crafted_items'        => [
                'metalFragments' => 2,
            ],
            'required_level'       => 14,
            'required_faction'     => 1, // Милитари
            'image_in_progress'    => 'uploads/telegram/craft/vehicles/snowmobile.jpg',
            'start_caption_name'   => '🛻 *Снегоход*',

            'item_name_eng'        => 'Snowmobile',
            'item_name_rus'        => 'Снегоход',
            'icon_emoji'           => '🛻',
            'zone_emoji'           => '🚚',
            'zone_name'            => 'транспорт',
            'agility_bonus'        => 0.03,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/vehicles/snowmobile.jpg',
            'craft_again_callback' => 'genericCraft_Snowmobile_1',
        ],

        // Легенда узаконила тягловый скот у Фермеров (concept-final.md §1) — своя
        // кожа/шкура вместо покупной. Каталожная строка переименована той же
        // миграцией: «Конная повозка»/«Horse Cart» → «Тягловая повозка»/«DraftCart».
        'DraftCart' => [
            'task_name'            => 'craftDraftCart',
            'resources'            => [
                'Древесина'    => 80,
                'Кожа животных' => 50,
            ],
            'crafted_items'        => [
                'WoodMaterials' => 2,
            ],
            'required_level'       => 14,
            'required_faction'     => 4, // Фермеры
            'image_in_progress'    => 'uploads/telegram/craft/vehicles/draft_cart.jpg',
            'start_caption_name'   => '🐎 *Тягловую повозку*',

            'item_name_eng'        => 'DraftCart',
            'item_name_rus'        => 'Тягловая повозка',
            'icon_emoji'           => '🐎',
            'zone_emoji'           => '🚚',
            'zone_name'            => 'транспорт',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.02,
            'image_completed'      => 'uploads/telegram/craft/vehicles/draft_cart.jpg',
            'craft_again_callback' => 'genericCraft_DraftCart_1',
        ],

        'AutonomousDrone' => [
            'task_name'            => 'craftAutonomousDrone',
            'resources'            => [
                'Солнечные камни' => 10,
            ],
            'crafted_items'        => [
                'wiring'                => 1,
                'electronicComponents'  => 2,
            ],
            'required_level'       => 16,
            'required_faction'     => 3, // Инженеры
            'image_in_progress'    => 'uploads/telegram/craft/vehicles/autonomous_drone.jpg',
            'start_caption_name'   => '🛸 *Автономный дрон*',

            'item_name_eng'        => 'AutonomousDrone',
            'item_name_rus'        => 'Автономный дрон',
            'icon_emoji'           => '🛸',
            'zone_emoji'           => '🚚',
            'zone_name'            => 'транспорт',
            'agility_bonus'        => 0.02,
            'intellect_bonus'      => 0.04,
            'image_completed'      => 'uploads/telegram/craft/vehicles/autonomous_drone.jpg',
            'craft_again_callback' => 'genericCraft_AutonomousDrone_1',
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
     * Резолв рецепта по `crafted_items.name_eng` (а НЕ по ключу рецепта).
     *
     * Ключ рецепта и `item_name_eng` совпадают не всегда: 'SapperShovel' →
     * 'Sapper Shovel' (с пробелом), 'GoldenHoe' → 'Golden Hoe', 'BasicMedKit' →
     * 'FirstAidKit'. Всё, что стартует от строки в БД (ремонт инструмента /
     * робота / дрона), обязано ходить сюда, иначе рецепт «не найдётся» и фича
     * умирает для части предметов молча.
     *
     * @return array<string,mixed>|null
     */
    public function findByItemNameEng(string $itemNameEng): ?array
    {
        if ($itemNameEng === '') {
            return null;
        }

        if ($this->itemNameIndex === null) {
            $index = [];
            foreach ($this->recipes as $recipeKey => $recipe) {
                if (! is_array($recipe)) {
                    continue;
                }
                $name = $recipe['item_name_eng'] ?? null;
                // Первое вхождение выигрывает — порядок объявления детерминирован.
                if (is_string($name) && $name !== '' && ! isset($index[$name])) {
                    $index[$name] = (string) $recipeKey;
                }
            }
            $this->itemNameIndex = $index;
        }

        $key = $this->itemNameIndex[$itemNameEng] ?? null;
        if ($key !== null) {
            return $this->get($key);
        }

        // Фолбэк: у рецепта нет item_name_eng, но ключ совпадает с name_eng.
        return $this->get($itemNameEng);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->recipes);
    }

    /**
     * Ленивая карта `item_name_eng` → recipe key. Private — CI4 BaseConfig
     * не трогает private-свойства при env-override.
     *
     * @var array<string,string>|null
     */
    private ?array $itemNameIndex = null;
}
