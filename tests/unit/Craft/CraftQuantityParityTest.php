<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Services\Craft\CraftCardHelper;
use App\Services\Player\ResourcePoolService;
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
        // Story craft-quantity-parity-06: подпись приведена к виду обычных карточек
        // крафта (`BasicMedKitCraft1Action.php:256`) — паритет из брифа, не регрессия.
        $this->assertSame('🛠️ Крафт 1шт', $flat[0]['text']);
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

        // Story craft-quantity-parity-06: подпись «🛠️ Крафт {N}шт» вместо «{N} шт.».
        $this->assertSame(CraftCardHelper::STEPS, array_map(
            static fn (array $b): int => (int) preg_replace('/\D+/', '', $b['text']),
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

    // ═══════════════════════════════════════════════════════════════════
    // 3. CraftCardHelper::available() — ADR-171: пул (рюкзак+склад на базе),
    //    не голый рюкзак. Story craft-quantity-parity-07.
    //
    //    Регрессия: если `available()` снова начнёт считать только рюкзак,
    //    у персонажа на базе с пустым рюкзаком, но полным складом ступени
    //    количества обрежутся до нуля — сюда же ловим случай выключенного
    //    пула, где обрезание до рюкзака как раз ПРАВИЛЬНО.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * На базе, пул включён, рюкзак пуст — склад тоже виден. `available()`
     * обязан вернуть количество со склада, а не 0 (голый рюкзак бы дал 0).
     */
    public function testAvailableCountsStorageWhenPooledOnBase(): void
    {
        $pool = $this->resourcePoolDouble(backpack: 0, storage: 40, pooled: true);
        $helper = new CraftCardHelper($pool, $this->resourceModelDouble('Редкие металлы', rarity: 2));

        $result = $helper->available(5, ['Редкие металлы' => 12]);

        $this->assertSame(
            [['name' => 'Редкие металлы', 'quantity' => 40, 'rarity' => 2]],
            $result,
            'на базе с пулом склад обязан учитываться, иначе ступени количества обрежутся до нуля вопреки складу',
        );
    }

    /**
     * Пул выключен (killswitch `storage.pool_enabled=false` либо игрок не на
     * базе) — рюкзак пуст, склад полон, но склад НЕ виден. `available()`
     * обязан вернуть 0, то есть сегодняшнее поведение до ADR-171.
     */
    public function testAvailableIgnoresStorageWhenPoolDisabled(): void
    {
        $pool = $this->resourcePoolDouble(backpack: 0, storage: 40, pooled: false);
        $helper = new CraftCardHelper($pool, $this->resourceModelDouble('Редкие металлы', rarity: 2));

        $result = $helper->available(5, ['Редкие металлы' => 12]);

        $this->assertSame(
            [['name' => 'Редкие металлы', 'quantity' => 0, 'rarity' => 2]],
            $result,
            'без пула склад не обязан учитываться — ступени количества режутся по одному рюкзаку',
        );
    }

    /**
     * Рюкзак и склад складываются, когда пул включён — не «либо/либо».
     */
    public function testAvailableSumsBackpackAndStorageWhenPooled(): void
    {
        $pool = $this->resourcePoolDouble(backpack: 7, storage: 40, pooled: true);
        $helper = new CraftCardHelper($pool, $this->resourceModelDouble('Редкие металлы', rarity: 2));

        $result = $helper->available(5, ['Редкие металлы' => 12]);

        $this->assertSame(47, $result[0]['quantity']);
    }

    /**
     * `ResourcePoolService` — двойник ТОЛЬКО на уровне leaf-чтений
     * (рюкзак/склад/pooled-флаг), которые в проде читают БД. Сложение
     * `backpack + (pooled ? storage : 0)` в `available()`/`availableByName()`
     * — настоящий код `ResourcePoolService`, не подделан.
     */
    private function resourcePoolDouble(int $backpack, int $storage, bool $pooled): ResourcePoolService
    {
        return new class ($backpack, $storage, $pooled) extends ResourcePoolService {
            public function __construct(
                private int $backpack,
                private int $storage,
                private bool $pooled,
            ) {
            }

            protected function backpackQuantity(int $characterId, int $resourceId): int
            {
                return $this->backpack;
            }

            protected function storageQuantity(int $characterId, int $resourceId): int
            {
                return $this->storage;
            }

            protected function isPooled(int $characterId): bool
            {
                return $this->pooled;
            }

            protected function resolveResourceId(string $resourceName): ?int
            {
                return 1; // единственный ресурс, участвующий в этих тестах
            }
        };
    }

    private function resourceModelDouble(string $name, int $rarity): ResourceModel
    {
        return new class ($name, $rarity) extends ResourceModel {
            public function __construct(private string $name, private int $rarity)
            {
            }

            public function getResourceByName($name)
            {
                return $name === $this->name ? ['id' => 1, 'rarity' => $this->rarity] : null;
            }
        };
    }
}
