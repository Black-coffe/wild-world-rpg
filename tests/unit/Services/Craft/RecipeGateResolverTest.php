<?php

namespace Tests\Unit\Services\Craft;

use App\Services\Craft\RecipeGateResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Мост «уровень рецепта»: `recipe.required_level` в конфиге против живого значения в
 * `GameSettings` по `required_level_setting_key`.
 *
 * Мост существовал в одном читателе (крафт) и отсутствовал в другом (витрина «🚚 Мой транспорт»).
 * Значения совпадали, поэтому расхождение было латентным: первый же тюнинг уровня через админку
 * развёл бы обещание экрана и настоящий гейт. Тест держит правило в одном месте.
 *
 * @internal
 */
final class RecipeGateResolverTest extends CIUnitTestCase
{
    /** Рецепт без ключа настройки не должен трогать GameSettings вовсе. */
    public function testFallsBackToConfigWhenNoSettingKey(): void
    {
        $calls    = 0;
        $resolver = new RecipeGateResolver(function () use (&$calls) {
            $calls++;
            return 999;
        });

        $this->assertSame(6, $resolver->requiredLevel(['required_level' => 6]));
        $this->assertSame(0, $calls, 'без ключа настройки читатель настроек звать нельзя');
    }

    public function testTunedValueWins(): void
    {
        $resolver = new RecipeGateResolver(static fn (string $key, $default) => $key === 'world.vehicle.cart.required_level' ? 9 : $default);

        $level = $resolver->requiredLevel([
            'required_level'             => 6,
            'required_level_setting_key' => 'world.vehicle.cart.required_level',
        ]);

        $this->assertSame(9, $level, 'значение из GameSettings обязано побеждать конфиг');
    }

    /** Настройка отсутствует/мусорная → остаёмся на конфиге, а не на нуле. */
    public function testGarbageSettingFallsBackToConfig(): void
    {
        $resolver = new RecipeGateResolver(static fn (string $key, $default) => 'не-число');

        $this->assertSame(14, $resolver->requiredLevel([
            'required_level'             => 14,
            'required_level_setting_key' => 'world.vehicle.snowmobile.required_level',
        ]));
    }

    public function testNoRecipeMeansNoGate(): void
    {
        $this->assertSame(0, (new RecipeGateResolver())->requiredLevel(null));
    }

    public function testMissingLevelMeansNoGate(): void
    {
        $this->assertSame(0, (new RecipeGateResolver())->requiredLevel(['item_name_rus' => 'Плот']));
    }
}
