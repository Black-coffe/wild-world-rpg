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
 * S1 (v0.51.182+): все 12 зданий описаны через generic-recipe + удалены 12
 * legacy `Build*Construction.php` (~4187 LOC дубля). preview-handler =
 * {@see \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingInfoAction},
 * start-handler = {@see \App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingAction}.
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
 * Все числа изначально перенесены 1:1 из удалённых `Build*Construction.php`.
 *
 * См. mmorpg-vault/lore/refactor/Architecture.md (P0 item 2).
 */
class Buildings extends BaseConfig
{
    /**
     * @var array<string, array{
     *     name_rus: string,
     *     emoji: string,
     *     info_text: string,
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
     *
     * S1 (v0.51.181) — добавлены `emoji` + `info_text` для GenericBuildingInfoAction.
     * info_text — описательный абзац, который раньше зашит был в Build*Construction.php.
     * Используется в preview-экране "📋 Описание:".
     */
    public array $recipes = [
        'Arsenal' => [
            'name_rus'          => 'Арсенал',
            'emoji'             => '⚔️',
            'info_text'         => 'Здание для хранения и экипировки оружия и брони. Чтобы надеть снаряжение, на базе нужен Арсенал.',
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
                                          . "Теперь вы можете хранить и экипировать оружие и броню!\n"
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
            'emoji'             => '🔧',
            'info_text'         => 'Ускоряет изготовление всех предметов (со 2-го уровня; на 3-м — заметно сильнее). Нужна для постройки инженерных сооружений.',
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
            'emoji'             => '🔥',
            'info_text'         => 'Необходима для плавки металла. Повышает выход металл-фрагментов при их крафте (со 2-го уровня; на 3-м — сильнее).',
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
            'emoji'             => '🏚️',
            'info_text'         => 'Открывает закрытый рынок: позволяет покупать крафтовые предметы у других игроков.',
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
            'emoji'             => '🥼',
            'info_text'         => 'Позволяет проводить исследования и создавать новые технологии. Ускоряет изготовление медикаментов (эффект складывается с Мастерской; со 2-го уровня).',
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
            'emoji'             => '☀️',
            'info_text'         => 'Энергетическая постройка. Требуется как условие для строительства Арсенала.',
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
            'emoji'             => '🥊',
            'info_text'         => 'Спортивный и тренировочный зал. Наличие даёт каждые 30 минут небольшую прибавку к силе персонажа (на 1 уровне 0,01; растёт с уровнем зала).',
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
            'emoji'             => '🌱',
            'info_text'         => 'Производит еду. Пассивно каждую минуту даёт урожай (на 1 уровне: *Фрукты* 2, *Ягоды* 1; расходует воду, выше — с уровнем). Также позволяет сажать семена культур ради дополнительного урожая.',
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
            'emoji'             => '🚰',
            'info_text'         => 'Ручная скважина для добычи воды. После постройки каждую минуту добавляется вода (на 1 уровне 1 единица; растёт с уровнем). Требует уплаты налога и активной базы.',
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
            'emoji'             => '🤖',
            'info_text'         => 'Даёт возможность строить различных роботов и автоматические установки/машины. Ускоряет изготовление роботов (эффект складывается с Мастерской; со 2-го уровня).',
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
            'emoji'             => '🌀',
            'info_text'         => 'Уникальное здание: снижает стоимость телепортов и увеличивает лимит маяков.',
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
            'emoji'             => '📢',
            'info_text'         => 'Расширяет радиус работы роботов от базы: каждый уровень — +100 клеток.',
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
                                          . "Вы завершили строительство *📡 Вышки связи*!\n"
                                          . "Теперь роботы могут работать дальше от базы.\n"
                                          . "Каждый уровень — +100 клеток радиуса!\n\n"
                                          . "_Удачи в развитии вашей колонии!_",
            'completion_bonus_agility'   => 0.05,
            'completion_bonus_intellect' => 0.05,
            'completion_building_type'   => null, // используем buildings.building_type
        ],

        // S26 (v0.51.207, ADR-030) — defensive structures (Фаза 6).
        // building_type='defensive'; combat-эффект через DefenseStructureService
        // (только когда защитник на своей клетке со структурой). WatchTower → S26b.
        'WoodenWall' => [
            'name_rus'          => 'Деревянная стена',
            'emoji'             => '🪵',
            'info_text'         => 'Защитная стена вокруг базы. Снижает урон, который ты получаешь в PvP, пока стоишь на своей базе. Изнашивается при отбитых атаках.',
            'level_required'    => 8,
            'task_name'         => 'buildWoodenWall',
            'task_settings'     => ['building' => 'WoodenWall'],
            'resources'         => [
                'Wood' => 800,
                'Clay' => 200,
            ],
            'crafted_items'     => [
                'WoodMaterials'  => 20,
                'metalFragments' => 10,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/wooden_wall.jpg',
            'completion_text'            => "🪵 Вы построили *Деревянную стену*!\n\nПока ты на своей базе, она снижает получаемый в PvP урон. Параметры тюнятся админом.",
            'completion_bonus_agility'   => 0.02,
            'completion_bonus_intellect' => 0.0,
            'completion_building_type'   => 'defensive',
        ],
        'BarbedFence' => [
            'name_rus'          => 'Колючая ограда',
            'emoji'             => '🌵',
            'info_text'         => 'Ограда из колючей проволоки. Каждый раунд PvP-боя у твоей базы наносит урон атакующему. Изнашивается при отбитых атаках.',
            'level_required'    => 10,
            'task_name'         => 'buildBarbedFence',
            'task_settings'     => ['building' => 'BarbedFence'],
            'resources'         => [
                'Ironstone'  => 60,
                'RareMetals' => 15,
            ],
            'crafted_items'     => [
                'wiring'         => 8,
                'metalFragments' => 15,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/barbed_fence.jpg',
            'completion_text'            => "🌵 Вы построили *Колючую ограду*!\n\nКаждый раунд PvP-боя у твоей базы наносит урон атакующему. Параметры тюнятся админом.",
            'completion_bonus_agility'   => 0.02,
            'completion_bonus_intellect' => 0.0,
            'completion_building_type'   => 'defensive',
        ],
        // S26b (ADR-031) — WatchTower: alert-range detection + defender initiative.
        'WatchTower' => [
            'name_rus'          => 'Дозорная вышка',
            'emoji'             => '🗼',
            'info_text'         => 'Наблюдательная вышка над базой. Предупреждает тебя о приближении чужих игроков и даёт преимущество инициативы в PvP, пока ты защищаешься у своей базы. Изнашивается при отбитых атаках.',
            'level_required'    => 12,
            'task_name'         => 'buildWatchTower',
            'task_settings'     => ['building' => 'WatchTower'],
            'resources'         => [
                'Wood'       => 600,
                'RareMetals' => 25,
            ],
            'crafted_items'     => [
                'wiring'         => 15,
                'metalFragments' => 20,
            ],
            'dependencies'      => [],
            'image_in_progress' => 'uploads/telegram/camp/Construction-by-improvised.jpg',
            'completion_image'           => 'uploads/telegram/camp/watch_tower.jpg',
            'completion_text'            => "🗼 Вы построили *Дозорную вышку*!\n\nОна предупредит тебя о приближении чужаков и даст фору инициативы в бою у твоей базы. Параметры тюнятся админом.",
            'completion_bonus_agility'   => 0.03,
            'completion_bonus_intellect' => 0.02,
            'completion_building_type'   => 'defensive',
        ],
    ];

    /**
     * @return array{
     *     name_rus: string,
     *     emoji: string,
     *     info_text: string,
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
     * }|null Рецепт по ключу, или null если ключ не зарегистрирован.
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
