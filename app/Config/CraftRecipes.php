<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * F2.2 — рецепты крафтовых предметов для GenericCraftCompletionHandler.
 *
 * Заменяет 42 копипастных файла в `app/TaskHandlers/Craft/` (~7560 строк
 * дубля). Каждый предмет = одна запись в `$recipes`. Вся логика
 * (mark task completed, find item, update inventory log, bump stats,
 * notify user) живёт в одном `GenericCraftCompletionHandler`.
 *
 * Сейчас (F2.2 PoC) описан только **Bandage** — paritetно с
 * `CraftCompletionBandageHandler.php` v0.1.0. Тот же name_eng,
 * agility_bonus, intellect_bonus, картинка, текст, keyboard.
 *
 * После прод-validation (sprint после F2.2):
 *   1. Перенести оставшиеся 41 предмет — каждый = ~10 строк config.
 *   2. Регистрация в `Worker::getHandlerClassName()` (или TaskDispatcher
 *      из F2.4) — все `tasks.name='craft<X>'` → один `GenericCraftCompletionHandler`
 *      с `task_settings.recipe = '<X>'`.
 *   3. Удалить старые 42 `CraftCompletion*Handler.php`.
 *
 * Структура одной записи:
 *   - `item_name_eng`     : `crafted_items.name_eng` (lookup-ключ).
 *   - `item_name_rus`     : запасной name_rus, если в БД его нет.
 *   - `icon_emoji`        : строка с эмодзи для UI ("🩹", "💊").
 *   - `zone_emoji`        : контекстная зона применения ("💊", "⚒").
 *   - `zone_name`         : "медицина", "ремесло", "оружие".
 *   - `agility_bonus`     : float, прибавка к ловкости персонажа.
 *   - `intellect_bonus`   : float, прибавка к интеллекту персонажа.
 *   - `image_completed`   : путь к картинке завершения крафта.
 *   - `craft_again_callback` : callback_data для кнопки «Крафтить ещё»
 *                              (например 'craftBandage_1' → 1 шт.).
 *
 * Все значения первой записи (Bandage) взяты 1:1 из
 * `CraftCompletionBandageHandler.php` v0.1.0.
 *
 * См. mmorpg-vault/lore/refactor/Architecture.md (P0 item 3).
 */
class CraftRecipes extends BaseConfig
{
    /**
     * @var array<string, array{
     *     item_name_eng: string,
     *     item_name_rus: string,
     *     icon_emoji: string,
     *     zone_emoji: string,
     *     zone_name: string,
     *     agility_bonus: float,
     *     intellect_bonus: float,
     *     image_completed: string,
     *     craft_again_callback: string,
     * }>
     */
    public array $recipes = [
        'Bandage' => [
            'item_name_eng'         => 'Bandage',
            'item_name_rus'         => 'Повязка',
            'icon_emoji'            => '🩹',
            'zone_emoji'            => '💊',
            'zone_name'             => 'медицина',
            'agility_bonus'         => 0.02,
            'intellect_bonus'       => 0.01,
            'image_completed'       => 'uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg',
            'craft_again_callback'  => 'craftBandage_1',
        ],

        // TODO (F2.2 sprint): перенести оставшиеся 41 предмет:
        // craftAntiseptic, craftSedative, craftPainReliefPower,
        // craftStrengtheningElixir, craftStimulator, craftRegenerator,
        // craftBasicMedKit, craftFabric, craftWiring, craftSoil,
        // craftElectronicComponents, craftStoneBlocks, craftMetalFragments,
        // craftFertilizer, craftWoodMaterials, craftCharcoalBriquettes,
        // craftGlassBags, craftHoe, craftFishingRod, craftFoldingKnife,
        // craftIronShovel, craftStonePickaxe, craftLumberjackAxe,
        // craftIronPickaxe, craftTireIron, craftWorkbenchOne,
        // craftRobotExplorer, craftRobotGatherer, craftTeleportBeaconBasic,
        // craftTeleportBackpack, craftArmorRaggedShirt,
        // craftArmorDrifterClothes, craftLeatherJacket,
        // craftReinforcedLeatherJacket, craftPipeGun, craftCrossbowMk1,
        // craftWiredBat, craftMetalSpear, и т.д.
        // Каждый = ~10 строк JSON-config против 187 строк копипасты.
    ];

    /**
     * @return array<string,mixed>|null Рецепт по ключу, или null если
     *                                  ключ не зарегистрирован.
     */
    public function get(string $recipeKey): ?array
    {
        return $this->recipes[$recipeKey] ?? null;
    }

    /**
     * @return list<string> Зарегистрированные ключи рецептов.
     */
    public function keys(): array
    {
        return array_keys($this->recipes);
    }
}
