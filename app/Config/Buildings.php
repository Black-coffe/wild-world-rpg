<?php

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
        ],

        // TODO (F3.B2): Gym, Greenhouse, HandPump, RoboticsWorkshop, TeleportCenter
        // TODO (F3.B3): CommunicationTower
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
