<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Craft;

use App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart;
use App\Entities\ResourceEntity;
use App\Models\ResourceModel;
use App\Services\Craft\CraftCardHelper;
use App\Services\Player\ResourcePoolService;
use App\Services\Telegram\KeyboardNormalizer;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * ADR-171 — карточка крафта считает наличие тем же пулом (рюкзак + склад
 * базы), которым потом считает старт крафта `GenericCraftActionStart::checkResources()`.
 *
 * Story craft-shortfall-buy-01 — tracer. Тестовые двойники переопределяют
 * точки касания моделей/сервисов (тот же приём, что `ResourcePoolServiceTest`) —
 * тест держит только поведение помощника, не ходит в БД.
 *
 * @internal
 */
final class CraftCardHelperTest extends CIUnitTestCase
{
    /**
     * @param array<string,int> $backpack resourceId => qty
     * @param array<string,int> $storage  resourceId => qty
     * @param array<string,int> $ids      имя ресурса => id — свой `resolveResourceId()`,
     *                                    иначе `ResourcePoolService::availableByName()`
     *                                    пошёл бы в БД мимо переданного в помощник `ResourceModel`.
     */
    private function resourcePool(array $backpack, array $storage, bool $onBase, bool $poolEnabled = true, array $ids = ['Базальт' => 1]): ResourcePoolService
    {
        return new class ($backpack, $storage, $onBase, $poolEnabled, $ids) extends ResourcePoolService {
            /**
             * @param array<string,int> $backpack
             * @param array<string,int> $storage
             * @param array<string,int> $ids
             */
            public function __construct(
                private array $backpack,
                private array $storage,
                private bool $onBase,
                private bool $poolEnabled,
                private array $ids
            ) {
                parent::__construct();
            }

            protected function isPooled(int $characterId): bool
            {
                return $this->poolEnabled && $this->onBase;
            }

            protected function backpackQuantity(int $characterId, int $resourceId): int
            {
                return $this->backpack[$resourceId] ?? 0;
            }

            protected function storageQuantity(int $characterId, int $resourceId): int
            {
                return $this->storage[$resourceId] ?? 0;
            }

            protected function resolveResourceId(string $resourceName): ?int
            {
                return $this->ids[$resourceName] ?? null;
            }
        };
    }

    /** @param array<string,array{id:int,rarity:int}> $resources имя => {id, rarity} */
    private function resourceModel(array $resources): ResourceModel
    {
        return new class ($resources) extends ResourceModel {
            /** @param array<string,array{id:int,rarity:int}> $resources */
            public function __construct(private array $resources)
            {
                parent::__construct();
            }

            public function getResourceByName($name)
            {
                if (! isset($this->resources[$name])) {
                    return null;
                }

                return new ResourceEntity($this->resources[$name] + ['name' => $name]);
            }
        };
    }

    public function testAvailableSumsBackpackAndStorageOnBase(): void
    {
        $pool  = $this->resourcePool(['1' => 10], ['1' => 40], onBase: true);
        $model = $this->resourceModel(['Базальт' => ['id' => 1, 'rarity' => 3]]);
        $helper = new CraftCardHelper($pool, $model);

        $result = $helper->available(7, ['Базальт' => 1]);

        $this->assertSame([['name' => 'Базальт', 'quantity' => 50, 'rarity' => 3]], $result);
    }

    public function testAvailableIsBackpackOnlyInTheField(): void
    {
        $pool  = $this->resourcePool(['1' => 10], ['1' => 40], onBase: false);
        $model = $this->resourceModel(['Базальт' => ['id' => 1, 'rarity' => 3]]);
        $helper = new CraftCardHelper($pool, $model);

        $result = $helper->available(7, ['Базальт' => 1]);

        $this->assertSame(10, $result[0]['quantity']);
    }

    public function testAvailableIsBackpackOnlyWhenKillswitchOff(): void
    {
        $pool  = $this->resourcePool(['1' => 10], ['1' => 40], onBase: true, poolEnabled: false);
        $model = $this->resourceModel(['Базальт' => ['id' => 1, 'rarity' => 3]]);
        $helper = new CraftCardHelper($pool, $model);

        $result = $helper->available(7, ['Базальт' => 1]);

        $this->assertSame(10, $result[0]['quantity']);
    }

    public function testAvailableTreatsUnknownResourceAsZero(): void
    {
        $pool  = $this->resourcePool([], [], onBase: true);
        $model = $this->resourceModel([]);
        $helper = new CraftCardHelper($pool, $model);

        $result = $helper->available(7, ['Неизвестное' => 5]);

        $this->assertSame([['name' => 'Неизвестное', 'quantity' => 0, 'rarity' => 0]], $result);
    }

    public function testFallbackButtonPointsToQuantityOneOfTheSameRecipe(): void
    {
        $helper = new CraftCardHelper($this->resourcePool([], [], false), $this->resourceModel([]));

        $btn = $helper->fallbackButton('LumberjackAxe');

        $this->assertSame('genericCraft_LumberjackAxe_1', $btn['callback_data']);
        $this->assertNotSame('', $btn['text']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 🔴 Story craft-shortfall-buy-fix-05 — доказательство ИСПОЛНЕНИЕМ кода,
    // а не сканом исходника (`CraftCardExitGuardTest` смотрит только на
    // наличие строки `fallbackButton(` в 35 файлах карточек и остался бы
    // зелёным, если сам помощник начнёт отдавать пустой/битый callback_data
    // или кнопка перестанет доживать до клавиатуры). Оба теста ниже гоняют
    // РЕАЛЬНЫЙ продовый код (`CallbackRoutes::resolve()` — та же логика,
    // что использует боевой роутер callback'ов; `KeyboardNormalizer::normalize()` —
    // тот же нормализатор, что центрально вызывается на каждом исходящем
    // сообщении), а не переизобретают его в тесте.
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * 🔴 Кнопка выхода из тупика «нельзя собрать ни одной штуки» обязана
     * резолвиться в реальный, существующий обработчик — тем же путём, что
     * и боевой `CallbackqueryCommand`: первый сегмент `callback_data` до `_`,
     * exact-match в `CallbackRoutes`. Сломай `fallbackButton()` — верни
     * пустой/переименованный callback_data — и этот тест покраснеет, потому
     * что роутер честно не найдёт обработчик.
     */
    public function testFallbackButtonCallbackDataResolvesToRealHandlerViaRouter(): void
    {
        $helper = new CraftCardHelper($this->resourcePool([], [], false), $this->resourceModel([]));
        $btn    = $helper->fallbackButton('LumberjackAxe');

        $this->assertNotSame('', $btn['callback_data'], 'пустой callback_data — кнопка мертва в Telegram');
        $this->assertNotSame('', trim($btn['text']), 'пустой текст — кнопки физически не видно игроку');

        $routes  = new CallbackRoutes();
        $segment = explode('_', $btn['callback_data'])[0];
        $handler = $routes->resolve($segment);

        $this->assertSame(
            GenericCraftActionStart::class,
            $handler,
            "callback_data '{$btn['callback_data']}' не резолвится роутером ни в один обработчик — "
            . 'кнопка выхода была бы мертва в реальном Telegram'
        );
    }

    /**
     * 🔴 Кнопка выхода реально ПОПАДАЕТ в клавиатуру после прохождения того
     * же нормализатора рядов, что центрально вызывается на каждом исходящем
     * сообщении (`Request::send()`/`sendMessage()`). Ряд собран ровно так,
     * как его собирает `LumberjackAxeCraft1Action` для состояния «нельзя
     * собрать ни одной штуки» (story craft-shortfall-buy-01): два одиночных
     * ряда подряд — [кнопка выхода] и [⬅️ Назад]. Сломай помощника так,
     * чтобы `fallbackButton()` возвращал массив без `text`/`callback_data`
     * нужной формы (например, вложенный массив вместо плоского), — и
     * нормализатор откажется трогать клавиатуру (вернёт форму невалидной),
     * кнопка останется одиночным рядом, и assertion ниже покраснеет.
     */
    public function testFallbackButtonSurvivesRealKeyboardNormalizationAsALoneRow(): void
    {
        $helper = new CraftCardHelper($this->resourcePool([], [], false), $this->resourceModel([]));
        $btn    = $helper->fallbackButton('LumberjackAxe');

        // Та же форма клавиатуры, что LumberjackAxeCraft1Action::handle() строит
        // в ветке `$maxCraftableItems < 1`.
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '◀️ Я', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [$btn],
                [['text' => '⬅️ Назад', 'callback_data' => 'tools']],
            ],
        ];

        $normalized = KeyboardNormalizer::normalize(['reply_markup' => json_encode($keyboard)]);
        $decoded    = json_decode((string) $normalized['reply_markup'], true);
        $rows       = $decoded['inline_keyboard'] ?? [];

        $this->assertNotSame([], $rows, 'нормализатор не должен был вернуть клавиатуру без рядов');
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(2, count($row), 'после нормализатора не должно остаться одиночного ряда');
        }

        $flatCallbacks = array_column(array_merge(...$rows), 'callback_data');
        $this->assertContains(
            $btn['callback_data'],
            $flatCallbacks,
            'кнопка выхода потерялась при нормализации клавиатуры'
        );
    }
}
