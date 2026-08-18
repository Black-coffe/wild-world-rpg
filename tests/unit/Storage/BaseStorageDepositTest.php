<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * Гейт ручной сдачи на склад базы.
 *
 * Сигнал игрока (Анжела, 18.08.2026): «забрать со склада в инвентарь кнопкой, а
 * выложить — только дроном». В `base_storage` писала ровно одна точка —
 * `CargoDroneSendAction`, а выдача требовала стоять на базе. Стоя дома, игрок мог
 * вынести со склада, но не мог ничего туда положить.
 *
 * Тест держит три вещи:
 *  1. все три формы callback'а сдачи резолвятся (класс-бага мёртвых `npcAct_`);
 *  2. вход на экран сдачи реально нарисован на экране склада (UX-discoverability:
 *     фича, до которой нет кнопки, для игрока не существует);
 *  3. ни один player-facing текст больше не обещает «на склад — ТОЛЬКО дроном»
 *     (правило «обещание = достижимость»: путей теперь два, и совет, называющий
 *     один, гонит игрока крафтить дрон вместо одной кнопки).
 */
final class BaseStorageDepositTest extends CIUnitTestCase
{
    private const HANDLER = \App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageDepositAction::class;

    /**
     * Роутер режет callback_data по первому `_`, поэтому все три формы обязаны
     * прийти в один и тот же action, который сам разбирает хвост.
     */
    public function testDepositCallbacksResolveToTheAction(): void
    {
        $routes = new CallbackRoutes();

        foreach ([
            'baseStorageDeposit',
            'baseStorageDeposit_all',
            'baseStorageDeposit_res_42',
        ] as $callbackData) {
            $action = explode('_', $callbackData)[0];

            $this->assertSame(
                self::HANDLER,
                $routes->resolve($action),
                "callback_data '{$callbackData}' не резолвится в экран сдачи на склад — кнопка будет мёртвой."
            );
        }
    }

    /**
     * Вход должен стоять на самом экране склада — и когда там пусто (класть
     * особенно логично именно тогда), и когда игрок стоит на базе.
     */
    public function testStorageScreenOffersTheDepositDoor(): void
    {
        $src = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php'
        );

        $this->assertSame(
            2,
            substr_count($src, "'callback_data' => 'baseStorageDeposit'"),
            'Кнопка «📥 Положить на склад» обязана быть и на пустом складе, и на экране склада при игроке на базе.'
        );
    }

    /**
     * Анти-ложь: путей на склад теперь два. Ни один живой текст не должен
     * называть карго-дрон единственным.
     */
    public function testNoPlayerFacingTextClaimsDroneIsTheOnlyWay(): void
    {
        $files = [
            APPPATH . 'Controllers/Telegram/Commands/Actions/InventoryAction.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/ResourceOverviewAction.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php',
            APPPATH . 'Services/Onboarding/GuideCatalog.php',
            APPPATH . 'Services/Onboarding/OnboardingHintCatalog.php',
        ];

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);

            $this->assertSame(
                0,
                preg_match_all('/только\s+\*?карго/iu', $src),
                basename($file) . ' обещает игроку «только карго-дрон» — на склад теперь можно положить руками, стоя на базе.'
            );
        }
    }
}
