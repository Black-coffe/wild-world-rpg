<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Services\GameSettings\GameSettingsService;

/**
 * Единственный владелец правила «какой уровень требует рецепт».
 *
 * У требования два источника: `recipe.required_level` в `Config\CraftRecipes` и живое значение
 * в `GameSettings` по ключу `recipe.required_level_setting_key` (ADR-026, admin-tunable). Мост
 * между ними существовал ровно в одном читателе — `GenericCraftActionStart`, который проводит
 * крафт. Витрина «🚚 Мой транспорт» (`VehicleAction`) читала `required_level` напрямую, поэтому
 * первый же тюнинг уровня через админку развёл бы обещание экрана и настоящий гейт: игрок видит
 * один порог, крафт требует другой. На 2026-12-05 значения совпадали, то есть расхождение было
 * латентным — ровно тот случай, когда чинить надо мост, а не место.
 *
 * Настройки поднимаются **лениво**: рецепт без `required_level_setting_key` не трогает БД вовсе,
 * поэтому render-тесты на фикстурах остаются чистыми.
 */
final class RecipeGateResolver
{
    /** @var (callable(string,mixed):mixed)|null точка инъекции для тестов; в проде null → GameSettingsService. */
    private $tunableReader;

    /**
     * @param (callable(string,mixed):mixed)|null $tunableReader `GameSettingsService` объявлен final,
     *   поэтому подменяется не наследником, а читателем — тот же приём, что в `RareResourceGrantEffect`.
     */
    public function __construct(?callable $tunableReader = null)
    {
        $this->tunableReader = $tunableReader;
    }

    /**
     * Требуемый уровень персонажа для рецепта: GameSettings, если ключ задан, иначе значение
     * из конфига. 0 — если рецепта нет или уровень не указан (то есть гейта нет).
     *
     * @param array<string,mixed>|null $recipe
     */
    public function requiredLevel(?array $recipe): int
    {
        if ($recipe === null) {
            return 0;
        }

        $baseRaw = $recipe['required_level'] ?? null;
        $base    = is_numeric($baseRaw) ? (int) $baseRaw : 0;

        $keyRaw = $recipe['required_level_setting_key'] ?? null;
        if (! is_string($keyRaw) || $keyRaw === '') {
            return $base;
        }

        $reader = $this->tunableReader ?? static fn (string $key, $default) => (new GameSettingsService())->get($key, $default);
        $tuned  = $reader($keyRaw, $base);

        return is_numeric($tuned) ? (int) $tuned : $base;
    }
}
