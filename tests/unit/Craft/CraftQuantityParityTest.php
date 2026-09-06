<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TelegramUserModel;
use App\Services\Craft\CraftCardHelper;
use App\TaskHandlers\Craft\GenericCraftCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionProperty;

/**
 * Story craft-quantity-parity-04.
 *
 * Две независимые вещи, которых сегодня не держит ни один гейт:
 *
 * 1. `CraftCardHelper::quantityRows()` — ряд кнопок количества у карточки
 *    T3-утилит, отсечённый по доступному количеству. Тест зовёт помощник
 *    напрямую (никакого скана исходника): сломай отсечение или упаковку —
 *    тест покраснеет на реальном возврате метода.
 *
 * 2. `GenericCraftCompletionHandler` — долив партии в существующую строку
 *    `crafted_items_log` не должен трогать `durability_count` (иначе стак
 *    инструмента «чинится» или обнуляется каждым новым крафтом). Тест гоняет
 *    настоящий `handle()` с моделями-двойниками (тот же приём, что
 *    `RepairCompletionHandlerTest`/`ToolDurabilityProcessorTest`: анонимные
 *    наследники моделей без открытия DB-соединения, приватные свойства
 *    подставлены через Reflection) и проверяет ТОЧНЫЙ payload, ушедший в
 *    `craftedItemsLogModel::update()`.
 *
 * @internal
 * @covers \App\Services\Craft\CraftCardHelper
 * @covers \App\TaskHandlers\Craft\GenericCraftCompletionHandler
 */
final class CraftQuantityParityTest extends CIUnitTestCase
{
    // ═══════════════════════════════════════════════════════════════════
    // 1. CraftCardHelper::quantityRows() — ряд количества у T3
    // ═══════════════════════════════════════════════════════════════════

    public function testZeroAffordableGivesNoRows(): void
    {
        $helper = new CraftCardHelper();

        $rows = $helper->quantityRows('SapperShovel', 0);

        $this->assertSame([], $rows, 'maxAffordable=0 обязан не давать ни одной кнопки крафта');
    }

    public function testOneAffordableGivesExactlyOneStep(): void
    {
        $helper = new CraftCardHelper();

        $rows = $helper->quantityRows('SapperShovel', 1);
        $flat = array_merge(...$rows);

        $this->assertCount(1, $flat, 'maxAffordable=1 обязан дать ровно одну ступень');
        $this->assertSame('1 шт.', $flat[0]['text']);
        $this->assertSame('genericCraft_SapperShovel_1', $flat[0]['callback_data']);
    }

    public function testStepsAboveAffordableAreExcluded(): void
    {
        $helper = new CraftCardHelper();

        // 12 доступно — ступени 25/50/100 не должны появиться, 10 обязана.
        $rows = $helper->quantityRows('DiamondPickaxe', 12);
        $flat = array_merge(...$rows);
        $qtys = array_map(static fn (array $b): string => $b['callback_data'], $flat);

        $this->assertSame(
            [
                'genericCraft_DiamondPickaxe_1',
                'genericCraft_DiamondPickaxe_5',
                'genericCraft_DiamondPickaxe_10',
            ],
            $qtys,
        );
    }

    public function testLadderDoesNotGrowBeyondLargestStep(): void
    {
        $helper = new CraftCardHelper();

        // Заведомо больше самой большой ступени (100) — лесенка не растёт бесконечно.
        $rows = $helper->quantityRows('DiamondPickaxe', 9999);
        $flat = array_merge(...$rows);

        $this->assertSame(CraftCardHelper::STEPS, array_map(
            static fn (array $b): int => (int) str_replace(' шт.', '', $b['text']),
            $flat,
        ));
        $this->assertSame(
            'genericCraft_DiamondPickaxe_100',
            $flat[count($flat) - 1]['callback_data'],
            'верхняя ступень лесенки обязана остаться 100, не больше',
        );
    }

    public function testCallbackDataFollowsRecipeKeyConvention(): void
    {
        $helper = new CraftCardHelper();

        $rows = $helper->quantityRows('SapperShovel', 10);
        $flat = array_merge(...$rows);

        foreach ($flat as $btn) {
            $this->assertMatchesRegularExpression(
                '/^genericCraft_SapperShovel_(1|5|10|25|50|100)$/',
                $btn['callback_data'],
            );
        }
    }

    /**
     * Ни одного одиночного ряда, кроме вырожденного случая «кнопка всего одна»
     * (maxAffordable=1, покрыт отдельным тестом выше). При двух и более
     * доступных ступенях — ноль одиночек (`ButtonPacker` правило владельца).
     */
    public function testNoLoneButtonRowWhenMoreThanOneStepIsAffordable(): void
    {
        $helper = new CraftCardHelper();

        foreach ([5, 10, 25, 50, 100, 999] as $maxAffordable) {
            $rows = $helper->quantityRows('DiamondPickaxe', $maxAffordable);
            foreach ($rows as $row) {
                $this->assertGreaterThanOrEqual(
                    2,
                    count($row),
                    "maxAffordable={$maxAffordable}: одиночный ряд недопустим при нескольких доступных ступенях",
                );
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. GenericCraftCompletionHandler — долив партии не трогает прочность
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Характеризующий тест: строка `crafted_items_log` с частично
     * израсходованным `durability_count` (7 из каталожных 30) после долива
     * партии +5 шт. сохраняет `durability_count` БЕЗ ИЗМЕНЕНИЙ, а `quantity`
     * растёт на размер партии (3 → 8).
     *
     * `assertSame` на ТОЧНОМ payload `update()` ловит обе регрессии сразу:
     * если долив станет чинить прочность (`durability_count` появится со
     * значением каталога 30) — payload перестанет равняться `['quantity' => 8]`
     * и тест покраснеет. Если долив станет обнулять прочность
     * (`durability_count` появится со значением 0) — тот же `assertSame`
     * покраснеет тем же способом: любой лишний ключ в payload ломает точное
     * равенство массивов, независимо от того, каким значением он заполнен.
     */
    public function testTopUpOfPartiallyUsedStackKeepsDurabilityUnchanged(): void
    {
        $existingLogId = 77;
        $existingLog   = [
            'id'               => $existingLogId,
            'character_id'     => 5,
            'crafted_item_id'  => 18,
            'quantity'         => 3,
            'durability_count' => 7, // частично израсходовано от каталожных 30
        ];

        $craftedItem = [
            'id'                 => 18,
            'name_eng'           => 'Bandage',
            'type'               => 'component',
            'direction_craft'    => 'medicine',
            'crafting_location'  => 'any',
            'durability_count'   => 30, // каталожное значение — НЕ должно попасть в payload update()
        ];

        $logModel = $this->craftedItemsLogModelDouble($existingLog);

        $handler = $this->buildHandler(
            $logModel,
            $this->craftedItemsModelDouble($craftedItem),
            $this->characterTaskModelDouble(),
            $this->characterModelDouble(),
            $this->telegramUserModelDouble(),
        );

        $handler->handle([
            'id'               => 999,
            'task_id'          => 501,
            'character_id'     => 5,
            'telegram_user_id' => 0,
            'status'           => 'in_work',
            'task_settings'    => json_encode(['recipe' => 'Bandage', 'quantity' => 5]),
        ]);

        $this->assertSame($existingLogId, $logModel->lastUpdateId, 'update() обязан пойти в существующую строку лога, не в новую');
        $this->assertSame(
            ['quantity' => 8],
            $logModel->lastUpdate,
            'payload долива обязан нести ТОЛЬКО quantity — durability_count менять нельзя ни в одну сторону',
        );
        $this->assertFalse($logModel->insertCalled, 'долив в существующий стак не должен создавать новую строку лога');
    }

    /**
     * Второй маршрут той же лесенки долива: +100 шт. поверх стака,
     * израсходованного почти полностью (durability_count=1) — тот же
     * инвариант payload'а держит независимо от размера партии и близости
     * прочности к нулю.
     */
    public function testTopUpNearZeroDurabilityStillLeavesDurabilityUntouched(): void
    {
        $existingLog = [
            'id'               => 12,
            'character_id'     => 9,
            'crafted_item_id'  => 18,
            'quantity'         => 40,
            'durability_count' => 1,
        ];

        $craftedItem = [
            'id'                 => 18,
            'name_eng'           => 'Bandage',
            'type'               => 'component',
            'direction_craft'    => 'medicine',
            'crafting_location'  => 'any',
            'durability_count'   => 30,
        ];

        $logModel = $this->craftedItemsLogModelDouble($existingLog);

        $handler = $this->buildHandler(
            $logModel,
            $this->craftedItemsModelDouble($craftedItem),
            $this->characterTaskModelDouble(),
            $this->characterModelDouble(),
            $this->telegramUserModelDouble(),
        );

        $handler->handle([
            'id'               => 1000,
            'task_id'          => 502,
            'character_id'     => 9,
            'telegram_user_id' => 0,
            'status'           => 'in_work',
            'task_settings'    => json_encode(['recipe' => 'Bandage', 'quantity' => 100]),
        ]);

        $this->assertSame(['quantity' => 140], $logModel->lastUpdate);
    }

    /**
     * @return CraftedItemsLogModel&object{lastUpdateId:?int,lastUpdate:array<string,mixed>,insertCalled:bool}
     */
    private function craftedItemsLogModelDouble(?array $existingLog): CraftedItemsLogModel
    {
        return new class ($existingLog) extends CraftedItemsLogModel {
            public ?int $lastUpdateId = null;
            public array $lastUpdate = [];
            public bool $insertCalled = false;

            public function __construct(private ?array $existingLog)
            {
            }

            public function where($key = null, $value = null, ?bool $escape = null)
            {
                return $this;
            }

            public function first()
            {
                return $this->existingLog;
            }

            public function update($id = null, $row = null): bool
            {
                $this->lastUpdateId = is_numeric($id) ? (int) $id : null;
                if (is_array($row)) {
                    $this->lastUpdate = $row;
                }

                return true;
            }

            public function insert($row = null, bool $returnID = true)
            {
                $this->insertCalled = true;

                return 999;
            }
        };
    }

    private function craftedItemsModelDouble(array $craftedItem): CraftedItemsModel
    {
        return new class ($craftedItem) extends CraftedItemsModel {
            public function __construct(private array $craftedItem)
            {
            }

            public function where($key = null, $value = null, ?bool $escape = null)
            {
                return $this;
            }

            public function first()
            {
                return $this->craftedItem;
            }
        };
    }

    private function characterTaskModelDouble(): CharacterTaskModel
    {
        return new class extends CharacterTaskModel {
            public function __construct()
            {
            }

            public function update($id = null, $row = null): bool
            {
                return true;
            }

            public function where($key = null, $value = null, ?bool $escape = null)
            {
                return $this;
            }

            public function orderBy($orderBy, string $direction = '', ?bool $escape = null)
            {
                return $this;
            }

            public function first()
            {
                return null; // очередь пуста — activateNextQueuedTask() тихо выходит
            }
        };
    }

    private function characterModelDouble(): CharacterModel
    {
        return new class extends CharacterModel {
            public function __construct()
            {
            }

            public function updateAgilityAndIntellect(int $characterId, float $agilityIncrement, float $intellectIncrement): bool
            {
                return true;
            }
        };
    }

    private function telegramUserModelDouble(): TelegramUserModel
    {
        return new class extends TelegramUserModel {
            public function __construct()
            {
            }

            public function where($key = null, $value = null, ?bool $escape = null)
            {
                return $this;
            }

            public function first()
            {
                return null; // без telegram_id notifyUser() тихо выходит до отправки
            }
        };
    }

    private function buildHandler(
        CraftedItemsLogModel $logModel,
        CraftedItemsModel $itemsModel,
        CharacterTaskModel $taskModel,
        CharacterModel $characterModel,
        TelegramUserModel $telegramUserModel,
    ): GenericCraftCompletionHandler {
        $handler = new GenericCraftCompletionHandler();
        $this->setPrivate($handler, 'craftedItemsLogModel', $logModel);
        $this->setPrivate($handler, 'craftedItemsModel', $itemsModel);
        $this->setPrivate($handler, 'characterTaskModel', $taskModel);
        $this->setPrivate($handler, 'characterModel', $characterModel);
        $this->setPrivate($handler, 'telegramUserModel', $telegramUserModel);

        return $handler;
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $rp = new ReflectionProperty($obj, $prop);
        $rp->setAccessible(true);
        $rp->setValue($obj, $value);
    }
}
