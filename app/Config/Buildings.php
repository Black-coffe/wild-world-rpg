<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * F2.1 — рецепты построек для GenericBuildingAction.
 *
 * Заменяет 23 копипастных файла `app/Controllers/Telegram/Commands/Actions/Camp/Build*Construction.php`
 * (~7980 строк дубля). Каждое здание = одна запись в `$recipes`.
 *
 * Сейчас (в этом коммите) описан **только Arsenal** — как PoC.
 * `GenericBuildingAction.php` использует этот конфиг и должен дать
 * 1:1 такое же поведение что и существующий `StartBuildArsenalConstruction`.
 *
 * После прод-validation (sprint после F2.1):
 *   1. Перенести Workshop/Lab/Greenhouse/etc. — каждое здание добавлять
 *      записью в `$recipes`.
 *   2. Подключить `genericStartBuild_<Key>` callback в
 *      `CallbackqueryCommand::getActionHandler()`.
 *   3. Удалить старые `Build*Construction.php` (по одному, после прод-теста).
 *
 * Структура одной записи:
 *   - `name_rus`           : строка для UI ("Арсенал", "Мастерская")
 *   - `level_required`     : мин. уровень персонажа для постройки
 *   - `task_name`          : `tasks.name` для записи в `character_tasks`
 *                            (handler завершения сейчас живёт отдельно
 *                             в `app/TaskHandlers/Built/BuiltCompletion*`)
 *   - `task_settings`      : что сохраняется в `character_tasks.task_settings`
 *   - `resources`          : карта `name_en` → нужное количество
 *   - `crafted_items`      : карта `name_eng` → нужное количество
 *   - `dependencies`       : массив `building.name_en` которые должны
 *                            быть уже построены (Arsenal требует
 *                            Workshop + BlastFurnace + SolarStation + Lab)
 *   - `image_in_progress`  : картинка для Telegram уведомления о начале
 *
 * Все числа взяты 1:1 из `StartBuildArsenalConstruction.php` v0.1.0.
 *
 * См. mmorpg-vault/lore/refactor/Architecture.md (P0 item 2).
 */
class Buildings extends BaseConfig
{
    /**
     * @var array<string, array{
     *     name_rus: string,
     *     level_required: int,
     *     task_name: string,
     *     task_settings: array<string, mixed>,
     *     resources: array<string, int>,
     *     crafted_items: array<string, int>,
     *     dependencies: list<string>,
     *     image_in_progress: string,
     *     completion_image?: string,
     *     completion_text?: string,
     *     completion_bonus_agility?: float,
     *     completion_bonus_intellect?: float,
     *     completion_building_type?: string|null,
     * }>
     */
    public array $recipes = [
        'Arsenal' => [
            'name_rus'          => 'Арсенал',
            'level_required'    => 15,
            'task_name'         => 'startBuildArsenal',
            'task_settings'     => ['building' => 'Arsenal'],
            'resources'         => [
                'Ironstone'  => 200,
                'RareMetals' => 60,
                'Oil'        => 70,
                'Sulfur'     => 50,
            ],
            'crafted_items'     => [
                'metalFragments'       => 120,
                'wiring'               => 15,
                'electronicComponents' => 8,
            ],
            'dependencies'      => ['Workshop', 'BlastFurnace', 'SolarStation', 'Laboratory'],
            'image_in_progress' => 'uploads/telegram/camp/arsenal_in_progress.jpg',
            // F3.B4 completion fields
            'completion_image'           => 'uploads/telegram/camp/arsenal.png',
            'completion_text'            => "🎉 *Поздравляем!*\n\n"
                                          . "Вы успешно завершили строительство *⚔️ Арсенала*.\n"
                                          . "Теперь у вас есть здание для хранения и улучшения оружия, производства боеприпасов!\n"
                                          . "_Время действовать!_",
            'completion_bonus_agility'   => 0.05,
            'completion_bonus_intellect' => 0.05,
            'completion_building_type'   => null, // используем buildings.building_type
        ],

        // F3.B1 (v0.17.0) — 5 простых tier-1 построек.
        // level_required = `buildings.level` колонка (1:1 с legacy `$character['level'] < $building['level']`),
        // НЕ `min_character_level` (это отдельная колонка, использовалась по-разному в legacy).
        // Все используют generic image `Construction-by-improvised.jpg` — отличается только Arsenal.

        'Workshop' => [
            'name_rus'          => 'Мастерская',
            'level_required'    => 1,
            'task_name'         => 'buildWorkshop',
            'task_settings'     => ['building' => 'Workshop'],
            'resources'         => [
                'Wood'  => 1500,
                'Water' => 800,
                'Clay'  => 400,
            ],
            'crafted_items'     => [
                'metalFragments' => 15,
                'WoodMaterials'  => 14,
                'stoneBlocks'    => 10,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/WorkShop.png',
            'completion_text'            => "📌 Вы успешно построили:\n\n*🔧 Мастерскую*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.03,
            'completion_bonus_intellect' => 0.03,
            'completion_building_type'   => 'farming',
        ],

        'BlastFurnace' => [
            'name_rus'          => 'Доменная печь',
            'level_required'    => 1,
            'task_name'         => 'buildBlastFurnace',
            'task_settings'     => ['building' => 'BlastFurnace'],
            'resources'         => [
                'Wood'  => 160,
                'Water' => 800,
                'Clay'  => 300,
            ],
            'crafted_items'     => [
                'metalFragments'      => 10,
                'CharcoalBriquettes'  => 5,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/blast_furnace.png',
            'completion_text'            => "📌 Вы успешно построили:\n\n🔥 *Доменную печь*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.07,
            'completion_bonus_intellect' => 0.02,
            'completion_building_type'   => 'farming',
        ],

        'Warehouse' => [
            'name_rus'          => 'Склад',
            'level_required'    => 1,
            'task_name'         => 'startBuildWarehouse',
            'task_settings'     => ['building' => 'Warehouse'],
            'resources'         => [
                'Wood'          => 160,
                'Water'         => 1100,
                'Clay'          => 120,
                'Pebble'        => 300,
                'HideOfAnimals' => 100,
            ],
            'crafted_items'     => [
                'metalFragments' => 10,
                'WoodMaterials'  => 20,
                'stoneBlocks'    => 15,
                'Fabric'         => 15,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/Warehouse.png',
            'completion_text'            => "📌 Вы успешно построили:\n\n*🏚️ Склад*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.01,
            'completion_bonus_intellect' => 0.01,
            'completion_building_type'   => 'farming',
        ],

        'Laboratory' => [
            'name_rus'          => 'Лаборатория',
            'level_required'    => 5,
            'task_name'         => 'startBuildLab',
            'task_settings'     => ['building' => 'Laboratory'],
            'resources'         => [
                'Wood' => 10000,
            ],
            'crafted_items'     => [
                'GlassBags'       => 50,
                'metalFragments'  => 100,
                'stoneBlocks'     => 24,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/laboratory.jpg',
            'completion_text'            => "📌 Вы успешно построили:\n\n*🥼 Лабораторию*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.08,
            'completion_bonus_intellect' => 0.07,
            'completion_building_type'   => 'farming',
        ],

        'SolarStation' => [
            'name_rus'          => 'Солнечная станция',
            'level_required'    => 1,
            'task_name'         => 'startBuildSolarStation',
            'task_settings'     => ['building' => 'SolarStation'],
            'resources'         => [
                'VolcanicAsh' => 15,
            ],
            'crafted_items'     => [
                'GlassBags'       => 29,
                'metalFragments'  => 11,
                'stoneBlocks'     => 5,
                'WoodMaterials'   => 10,
                'WorkbenchOne'    => 1,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/solar_power_station.jpg',
            'completion_text'            => "📌 Вы успешно построили:\n\n*☀️ Солнечную станцию*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.04,
            'completion_bonus_intellect' => 0.03,
            'completion_building_type'   => 'farming',
        ],

        // F3.B2 (v0.18.0) — 5 medium-tier построек.
        // Greenhouse/Gym/HandPump имеют связанные production-handlers
        // (recurring tasks для производства ресурсов) — это отдельный
        // паттерн, остаётся legacy. F3.B2 мигрирует только START side.

        'Gym' => [
            'name_rus'          => 'Спортзал',
            'level_required'    => 5,
            'task_name'         => 'startBuildGym',
            'task_settings'     => ['building' => 'Gym'],
            'resources'         => [
                'Wood'          => 1400,
                'Water'         => 1000,
                'Clay'          => 120,
                'Pebble'        => 1600,
                'HideOfAnimals' => 50,
                'Amber'         => 12,
                'Minerals'      => 8,
            ],
            'crafted_items'     => [
                'GlassBags'       => 10,
                'metalFragments'  => 16,
                'stoneBlocks'     => 10,
                'WoodMaterials'   => 10,
                'WorkbenchOne'    => 1,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/Gym.png',
            'completion_text'            => "📌 Вы успешно построили:\n\n*🥊 Спортзал*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.08,
            'completion_bonus_intellect' => 0.07,
            'completion_building_type'   => 'farming',
        ],

        'Greenhouse' => [
            'name_rus'          => 'Теплица',
            'level_required'    => 1,
            'task_name'         => 'startBuildGreenhouse',
            'task_settings'     => ['building' => 'Greenhouse'],
            'resources'         => [
                'Wood' => 20000,
            ],
            'crafted_items'     => [
                'GlassBags'       => 55,
                'metalFragments'  => 28,
                'stoneBlocks'     => 15,
                'WoodMaterials'   => 17,
                'WorkbenchOne'    => 1,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/Greenhouse_craft.png',
            'completion_text'            => "📌 Вы успешно построили:\n\n*🌱 Теплицу*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.08,
            'completion_bonus_intellect' => 0.07,
            'completion_building_type'   => 'farming',
        ],

        'HandPump' => [
            'name_rus'          => 'Ручная скважина',
            'level_required'    => 1,
            'task_name'         => 'buildingManualPump',
            'task_settings'     => ['building' => 'HandPump'],
            'resources'         => [
                'Wood'  => 2000,
                'Water' => 1200,
                'Clay'  => 600,
            ],
            'crafted_items'     => [
                'metalFragments' => 10,
                'WoodMaterials'  => 5,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/hand_pump.jpg',
            'completion_text'            => "📌 Вы успешно построили:\n\n🚰 *Ручную скважину!*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.06,
            'completion_bonus_intellect' => 0.02,
            'completion_building_type'   => 'farming',
        ],

        'RoboticsWorkshop' => [
            'name_rus'          => 'Мастерская робототехники',
            'level_required'    => 10,
            'task_name'         => 'startBuildRoboticsWorkshop',
            'task_settings'     => ['building' => 'RoboticsWorkshop'],
            'resources'         => [
                'RareMetals' => 150,
                'RareOre'    => 150,
            ],
            'crafted_items'     => [
                'GlassBags'       => 40,
                'metalFragments'  => 80,
                'stoneBlocks'     => 10,
                'WoodMaterials'   => 10,
                'WorkbenchOne'    => 1,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/Robotics-Workshop.jpg',
            'completion_text'            => "📌 Вы успешно построили:\n\n*🤖 Мастерскую робототехники*\n\nЗона применения: *База* 🏚️",
            'completion_bonus_agility'   => 0.06,
            'completion_bonus_intellect' => 0.08,
            'completion_building_type'   => 'farming',
        ],

        'TeleportationCenter' => [
            'name_rus'          => 'Центр телепортации',
            'level_required'    => 12,
            'task_name'         => 'startBuildTeleportationCenter',
            'task_settings'     => ['building' => 'TeleportationCenter'],
            'resources'         => [
                'RareMetals' => 100,
                'RareOre'    => 50,
                'Amber'      => 24,
                'Oil'        => 48,
            ],
            'crafted_items'     => [
                'GlassBags'            => 40,
                'metalFragments'       => 80,
                'stoneBlocks'          => 10,
                'WoodMaterials'        => 10,
                'wiring'               => 10,
                'electronicComponents' => 6,
                'WorkbenchOne'         => 1,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/teleport_center.png',
            'completion_text'            => "📌 Вы успешно построили:\n\n*🌀 Центр телепортации*\n\nТеперь телепортация доступна по сниженной цене и с расширенным лимитом маяков!",
            'completion_bonus_agility'   => 0.05,
            'completion_bonus_intellect' => 0.05,
            'completion_building_type'   => 'engineering',
        ],

        // F3.B3-mini (v0.19.0) — последний building start-side.
        // После B3-mini все 12 buildings start полностью мигрированы.
        // Completion-side остаётся legacy до B4 (GenericBuildingCompletionHandler).

        'CommunicationTower' => [
            'name_rus'          => 'Вышка связи',
            'level_required'    => 1,
            'task_name'         => 'startBuildCommunicationTower',
            'task_settings'     => ['building' => 'CommunicationTower'],
            'resources'         => [
                'Ironstone'  => 100,
                'RareMetals' => 20,
                'Oil'        => 30,
                'Sulfur'     => 15,
            ],
            'crafted_items'     => [
                'metalFragments'       => 100,
                'electronicComponents' => 12,
                'wiring'               => 12,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/communication_tower_in_progress.jpg',
            'completion_image'           => 'uploads/telegram/camp/communication_tower.png',
            'completion_text'            => "🎉 *Поздравляем!*\n\n"
                                          . "Вы завершили строительство *📡Вышки связи*!\n"
                                          . "Теперь ваша база доступна для удалённого управления.\n"
                                          . "Каждый уровень — +100 радиус связи!\n\n"
                                          . "_Удачи в развитии вашей колонии!_",
            'completion_bonus_agility'   => 0.05,
            'completion_bonus_intellect' => 0.05,
            'completion_building_type'   => null, // используем buildings.building_type
        ],
    ];

    /**
     * @return array<string,mixed>|null Рецепт по ключу, или null если
     *                                  ключ не зарегистрирован.
     */
    public function get(string $buildingKey): ?array
    {
        return $this->recipes[$buildingKey] ?? null;
    }

    /**
     * @return list<string> Зарегистрированные ключи зданий.
     */
    public function keys(): array
    {
        return array_keys($this->recipes);
    }
}
