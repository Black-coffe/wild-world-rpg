<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Services\Player\CargoAutoLoadService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * Гейт автовывоза карго-дроном.
 *
 * Просьба игрока (Анжела, 18.08.2026): «карго-дрон должен иметь возможность
 * автоматического перемещения ресурса из рюкзака на склад, начиная с высшей редкости и
 * по убыванию, кроме аптечки, еды и воды».
 *
 * Главный инвариант — исключения. `resources.type` хранится списком через запятую
 * («crafting,food»), поэтому проверка идёт посегментно: подстрочный поиск и поймал бы
 * лишнее, и пропустил бы нужное.
 */
final class CargoAutoLoadServiceTest extends CIUnitTestCase
{
    public function testFoodWaterAndSeedsAreNeverTaken(): void
    {
        $service = new CargoAutoLoadService();

        foreach (['food', 'water', 'seed', 'crafting,food', 'crafting,water', 'CRAFTING,FOOD', ' food '] as $type) {
            $this->assertTrue(
                $service->isSkipped($type),
                "Тип «{$type}» обязан оставаться при игроке — иначе автовывоз увезёт запас на выживание."
            );
        }
    }

    public function testUsefulLootIsTaken(): void
    {
        $service = new CargoAutoLoadService();

        foreach (['crafting', 'material', 'rare', 'crafting,material', ''] as $type) {
            $this->assertFalse(
                $service->isSkipped($type),
                "Тип «{$type}» должен вывозиться — это добыча, ради которой дрон и нужен."
            );
        }
    }

    /**
     * Подстрочный поиск дал бы ложное срабатывание на типе, где «food»/«seed» — часть
     * другого слова. Проверяем, что режем именно по сегментам списка.
     */
    public function testSubstringLookalikesAreNotSkipped(): void
    {
        $service = new CargoAutoLoadService();

        $this->assertFalse($service->isSkipped('seedling_parts'), 'Совпадение внутри слова не должно исключать ресурс.');
        $this->assertFalse($service->isSkipped('waterproof_cloth'), 'Совпадение внутри слова не должно исключать ресурс.');
    }

    /** Кнопка обязана резолвиться — иначе автовывоз мёртв (класс-бага `npcAct_`). */
    public function testAutoSendCallbackResolves(): void
    {
        $routes = new CallbackRoutes();

        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Drone\CargoDroneAutoSendAction::class,
            $routes->resolve(explode('_', 'cargoDroneAuto_42')[0]),
            'callback `cargoDroneAuto_<log_id>` не резолвится.'
        );
    }

    /** Вход обязан быть нарисован на экране карго-дрона, иначе фича невидима. */
    public function testEntryButtonExistsOnCargoScreen(): void
    {
        $src = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Drone/CargoDroneSelectAction.php'
        );

        $this->assertStringContainsString('cargoDroneAuto_', $src, 'Кнопка «Автовывоз» пропала с экрана карго-дрона.');
    }
}
