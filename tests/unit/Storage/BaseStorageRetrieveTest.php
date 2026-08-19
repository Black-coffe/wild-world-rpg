<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * Гейт выборочной выдачи со склада базы (storage-craft-insurance-03).
 *
 * Зеркало `BaseStorageDepositTest`: сдача умела «весь вид целиком» кнопкой,
 * а выдача — только «Забрать всё». Чтобы достать одну воду, игрок был
 * вынужден вывалить в рюкзак весь склад и разложить его обратно. Тест держит:
 *  1. все формы callback'а забора резолвятся в один и тот же action (роутер
 *     режет callback_data по первому `_`, хвост разбирает сам класс);
 *  2. режим сортировки протащен в callback_data кнопок выбора ресурса —
 *     иначе тап по ресурсу сбрасывает игроку сортировку склада;
 *  3. кнопки выбора ресурса идут через `ButtonPacker::pack()`, а не сырым
 *     массивом (правило «ноль одиноких кнопок в ряду»);
 *  4. предел кнопок честно назван строкой «…и ещё N видов», не обрезан молча;
 *  5. отказ «не на базе» объясняет, что делать, а не слово «ошибка».
 */
final class BaseStorageRetrieveTest extends CIUnitTestCase
{
    private const HANDLER = \App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageListAction::class;

    /**
     * Роутер режет callback_data по первому `_`, поэтому все формы обязаны
     * прийти в один и тот же action, который сам разбирает хвост.
     */
    public function testRetrieveCallbacksResolveToTheAction(): void
    {
        $routes = new CallbackRoutes();

        foreach ([
            'baseStorageList',
            'baseStorageList_all',
            'baseStorageList_res_42_recent',
            'baseStorageList_sort_name',
        ] as $callbackData) {
            $action = explode('_', $callbackData)[0];

            $this->assertSame(
                self::HANDLER,
                $routes->resolve($action),
                "callback_data '{$callbackData}' не резолвится в экран склада — кнопка будет мёртвой."
            );
        }
    }

    private function source(): string
    {
        return (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Storage/BaseStorageListAction.php'
        );
    }

    /**
     * Кнопка выбора ресурса обязана нести текущий режим сортировки в
     * callback_data — иначе он теряется на каждом тапе (stateless, другого
     * места хранить его нет).
     */
    public function testResourceButtonCarriesTheSortMode(): void
    {
        $this->assertStringContainsString(
            "baseStorageList_res_{\$rid}_{\$mode}",
            $this->source(),
            'Кнопка выбора ресурса обязана протащить текущий режим сортировки в callback_data.'
        );
    }

    /**
     * Кнопки выбора ресурса собираются через общий нормализатор рядов —
     * не сырым массивом, который мог бы дать одинокую кнопку в ряду.
     */
    public function testResourceButtonsGoThroughButtonPacker(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('use App\Services\Telegram\ButtonPacker;', $src);
        $this->assertStringContainsString('ButtonPacker::pack($buttons)', $src);
    }

    /**
     * Предел кнопок (18, как у сдачи) назван честно — «…и ещё N видов»,
     * а не молча обрезан.
     */
    public function testButtonLimitIsAnnouncedHonestly(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('MAX_BUTTONS = 18', $src);
        $this->assertStringContainsString('…и ещё " . (count($entries) - self::MAX_BUTTONS) . " видов', $src);
    }

    /**
     * Отказ «не на базе» — и для «забрать всё», и для одного вида — объясняет,
     * что нужно сделать, а не отдаёт голое «ошибка» (правило UX-discoverability).
     */
    public function testOffBaseDenialExplainsWhatToDo(): void
    {
        $src = $this->source();

        $this->assertSame(
            2,
            substr_count($src, 'Чтобы забрать со склада, нужно быть на своей клейм-клетке. Вернись на базу.'),
            'И «Забрать всё», и забор одного вида обязаны объяснять игроку, что нужно быть на базе.'
        );
    }
}
