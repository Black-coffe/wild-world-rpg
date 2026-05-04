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

        // TODO (F2.1 sprint, постепенно): Workshop, BlastFurnace, Lab,
        // Greenhouse, HandPump, Gym, SolarStation, CommunicationTower,
        // Warehouse, TeleportationCenter, RoboticsWorkshop.
        // Каждое здание = ~10 строк JSON-config против 449 строк копипасты.
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
