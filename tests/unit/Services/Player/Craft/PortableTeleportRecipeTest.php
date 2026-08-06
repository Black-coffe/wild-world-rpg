<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player\Craft;

use App\Services\Player\Craft\PortableTeleportRecipe;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Рецепт «📡 Портативный телепорт» — предмет, который годами существовал в БД и умел
 * телепортировать, но не имел способа быть полученным (0 владельцев на проде).
 *
 * Тест держит инварианты рецепта как ЧИСТЫЕ данные (без БД): состав, имя задачи и —
 * главное — экономическую безопасность.
 *
 * @internal
 */
final class PortableTeleportRecipeTest extends CIUnitTestCase
{
    /**
     * 🔴 Анти-эксплойт (класс бага ADR-157). Цена продажи торговцу считается от
     * `crafted_items.price` и при максимальной карме достигает самого прайса
     * (sell_multiplier ≤ 1.0). Если сборка стоит дешевле этого потолка, круг
     * «собрал → продал» печатает золото, а материалы в пустоши бесплатны.
     */
    public function testCraftCostExceedsVendorSellCeiling(): void
    {
        $this->assertGreaterThan(
            PortableTeleportRecipe::VENDOR_BASE_PRICE,
            PortableTeleportRecipe::DEFAULT_GOLD,
            'Сборка обязана стоить дороже, чем торговец платит за предмет, иначе крафт печатает золото.'
        );
    }

    /** Рецепт без материалов превратил бы устройство в «золото → предмет» без добычи. */
    public function testRecipeRequiresRealMaterials(): void
    {
        $this->assertNotEmpty(PortableTeleportRecipe::RESOURCES, 'Рецепт обязан требовать сырьё.');
        $this->assertNotEmpty(PortableTeleportRecipe::COMPONENTS, 'Рецепт обязан требовать компоненты.');

        foreach (PortableTeleportRecipe::RESOURCES + PortableTeleportRecipe::COMPONENTS as $name => $qty) {
            $this->assertIsString($name);
            $this->assertGreaterThan(0, $qty, "Количество для «{$name}» должно быть положительным.");
        }
    }

    /** Заряды и время — положительные, иначе устройство мертво в момент выдачи. */
    public function testDefaultsAreSane(): void
    {
        $this->assertGreaterThan(0, PortableTeleportRecipe::DEFAULT_CHARGES);
        $this->assertGreaterThan(0, PortableTeleportRecipe::DEFAULT_DURATION);
        $this->assertGreaterThan(0, PortableTeleportRecipe::DEFAULT_MIN_LEVEL);
        $this->assertTrue(PortableTeleportRecipe::DEFAULT_ENABLED, 'Механика применения уже жива — рецепт не должен рождаться dormant.');
    }

    /**
     * Имя задачи — тот самый ключ, по которому Worker находит handler завершения.
     * Разъедется — сборка зависнет в in_work навсегда (класс BUILT-BUT-DEAD).
     */
    public function testTaskNameIsRoutedByWorker(): void
    {
        $worker = (string) file_get_contents(APPPATH . 'Controllers/Worker.php');

        $this->assertStringContainsString(
            "'" . PortableTeleportRecipe::TASK_NAME . "'",
            $worker,
            'Задача сборки не зарегистрирована в Worker — завершение крафта никогда не сработает.'
        );
        $this->assertStringContainsString(
            'CraftCompletionPortableTeleportHandler',
            $worker,
            'Worker не знает класс handler-а завершения сборки.'
        );
    }

    /**
     * Обе двери экрана крафта существуют в роутере: сам рецепт и запуск сборки.
     * (Урок мёртвых callback'ов: класс есть, а маршрута нет — кнопка молчит.)
     */
    public function testCallbackRoutesExposeCraftDoors(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/CallbackRoutes.php');

        $this->assertStringContainsString("'portableTeleport2'", $routes);
        $this->assertStringContainsString("'startCraftPortableTeleport2'", $routes);
    }

    /** Вход в рецепт обязан быть виден из раздела «🌀 Телепорты» (UX-discoverability). */
    public function testCraftSelectScreenLinksToRecipe(): void
    {
        $select = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/TeleportBeacon/TeleportBeaconCraft2Select.php'
        );

        $this->assertStringContainsString(
            "'portableTeleport2'",
            $select,
            'В разделе телепортов нет кнопки на портативный телепорт — предмет снова недостижим.'
        );
    }
}
