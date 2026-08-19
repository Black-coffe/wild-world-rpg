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
     * Вход должен стоять на каждом экране, где логично тут же что-то положить:
     * на пустом складе, на экране склада при игроке на базе, и на экране
     * подтверждения «ресурс забран» (ADR-171 — забрал одно, тут же можно
     * положить другое, не возвращаясь никуда).
     *
     * Проверка режет исходник по границам методов/веток и требует кнопку
     * ИМЕННО в каждом из трёх блоков — а не считает суммарные вхождения по
     * файлу: голый счётчик и падает, и молчит не по адресу — падает если
     * появится четвёртый экран с той же кнопкой (защищает от ложного
     * фейла), и не ловит, если дверь исчезнет с одного конкретного экрана,
     * но появится на другом (защищает смысл гейта).
     */
    public function testStorageScreenOffersTheDepositDoor(): void
    {
        $src = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php'
        );

        $button = "'callback_data' => 'baseStorageDeposit'";

        // Блок renderList(): пустой склад — до ветки «на базе»; и сама ветка
        // «на базе» — до следующего метода retrieveAll().
        $renderListStart = strpos($src, 'private function renderList(');
        $retrieveAllStart = strpos($src, 'private function retrieveAll(');
        $this->assertNotFalse($renderListStart, 'Не нашёл renderList() — правь тест под актуальную структуру файла.');
        $this->assertNotFalse($retrieveAllStart, 'Не нашёл retrieveAll() — правь тест под актуальную структуру файла.');
        $renderListBody = substr($src, $renderListStart, $retrieveAllStart - $renderListStart);

        $onBaseMarker = 'Ты на базе — можно забрать всё в инвентарь';
        $onBasePos = strpos($renderListBody, $onBaseMarker);
        $this->assertNotFalse($onBasePos, 'Не нашёл текст ветки «на базе» — правь тест под актуальную структуру файла.');

        $emptyStorageBlock = substr($renderListBody, 0, $onBasePos);
        $onBaseBlock       = substr($renderListBody, $onBasePos);

        $this->assertSame(
            1,
            substr_count($emptyStorageBlock, $button),
            'Кнопка «📥 Положить на склад» пропала с экрана пустого склада.'
        );
        $this->assertSame(
            1,
            substr_count($onBaseBlock, $button),
            'Кнопка «📥 Положить на склад» пропала с экрана склада при игроке на базе.'
        );

        // Блок retrieveOne(): экран-подтверждение «ресурс забран» — до
        // следующего метода resourceName().
        $retrieveOneStart = strpos($src, 'private function retrieveOne(');
        $resourceNameStart = strpos($src, 'private function resourceName(');
        $this->assertNotFalse($retrieveOneStart, 'Не нашёл retrieveOne() — правь тест под актуальную структуру файла.');
        $this->assertNotFalse($resourceNameStart, 'Не нашёл resourceName() — правь тест под актуальную структуру файла.');
        $retrieveOneBody = substr($src, $retrieveOneStart, $resourceNameStart - $retrieveOneStart);

        $this->assertSame(
            1,
            substr_count($retrieveOneBody, $button),
            'Кнопка «📥 Положить на склад» пропала с экрана подтверждения «ресурс забран».'
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
