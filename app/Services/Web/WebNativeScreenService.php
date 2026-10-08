<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Models\ActionLogModel;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BaseScreenService;
use App\Services\Bases\BaseStorageService;
use App\Services\Buildings\BuildingUpgradeService;
use App\Services\Buildings\BuildOrderService;
use App\Models\CraftedItemsLogModel;
use App\Services\Craft\CraftCardHelper;
use App\Services\Craft\CraftOrderService;
use App\Services\Craft\CraftQueueService;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Player\CharacterSheetService;
use App\Services\Player\EquipmentLoadoutService;
use App\Services\Player\InventorySortService;
use App\Services\Player\InventoryViewService;
use App\Services\Player\Trade\ResourceShopScreenService;
use App\Services\Player\Trade\ResourceTradeService;
use App\Models\QuestModel;
use App\Services\Events\EventsModelService;
use App\Services\Quest\DailyTaskService;
use App\Services\Quest\QuestChainService;
use App\Services\Quest\QuestListService;
use App\Services\Quest\QuestStartService;
use App\Services\Tasks\TasksSurfaceService;
use App\Services\Telegram\BotMenuService;
use App\Services\World\LiveMapService;
use App\Services\World\MarchService;
use App\Services\World\MoveService;
use App\Services\World\SeasonalCraftService;
use Config\CraftCatalog;
use Config\CraftRecipes;
use InvalidArgumentException;

/**
 * W2.N1 (ADR-190) — нативные экраны `/play`: веб-рендерер моделей экранов поверх того же ядра,
 * из которого рисует бот. Мост (ADR-189) остаётся фолбэком для кнопок без нативного экрана.
 *
 * Экран — серверный HTML в `#play-state` (работает без JS), HUD — партиал `site/_play/hud`
 * над ним. Идентификаторы персонажа приходят только из сессии (контроллер), в разметку не
 * выводятся telegram/chat id (ADR-189 инв. 6).
 *
 * Кнопка нативного экрана, которой нет нативного аналога, уходит в мост «как сейчас»: если
 * на текущем экране моста нет сообщения с этой кнопкой, бот проходит тот же путь, что игрок
 * в Telegram — карточка «Я» (текст нижнего меню), затем кнопки по {@see BRIDGE_ROUTES}, — и
 * только потом жмётся нужная кнопка. Каждая ступень — обычный {@see WebActService::act()} с
 * проверкой «кнопка стоит на своём сообщении» и дедупом `intent_id` (суффиксы `:card`,
 * `:s<N>`, `:cb`).
 *
 * Снаряжение (03) меняется нативно: {@see gearChange()} зовёт тот же
 * {@see EquipmentLoadoutService}, что и бот, с дедупом `intent_id` в `web_play_intents`
 * (повтор POST не меняет состояние второй раз).
 *
 * W2.N2-01: «Мир» — нативная сетка из {@see LiveMapService}, той же модели, из которой рисует бот.
 * Кнопки карты без своего экрана (роза, база, остров, события, дроны, Поход…) идут в мост от
 * карты бота: вход — `/go` (тот же экран «🌍 Мир»), затем нажатие кнопки на его сообщении.
 * Клик по клетке — подсказка (биом, координаты).
 *
 * W2.N2-02: соседняя клетка и роза — нативный шаг {@see step()} тем же {@see MoveService}, что у
 * бота, один раз на `intent_id` (`:step`). Хуки шага, которым нужен чат (подсказки, находка,
 * атмосфера…), идут под захватом {@see WebDelivery}: их сообщения вместе с событиями шага (рана,
 * «хвост» клетки) ложатся на экран моста и показываются под картой, а их кнопки жмутся через мост
 * (`/play/act` с `message_id` своего сообщения).
 *
 * W2.N2-03: клик по клетке на одном из 8 лучей — превью Похода ({@see marchPreview()}, `n` — расстояние
 * по Чебышёву, зажатое в потолок заказа); «Выступить», «Продлить», «Продолжить», «Остановиться» —
 * {@see march()} тем же {@see MarchService}, что у бота, один раз на `intent_id` (`:march_*`). Поход из
 * веба пишется без `msg_*`: тик шлёт прогресс в Telegram новым сообщением, веб видит его в HUD и на карте.
 *
 * W2.N3-03: «🔨 Крафт» (`view=craft`) — верстаки, категории и карточка рецепта по индексу
 * {@see CraftCatalog} (дерево экранов бота); карточка — {@see CraftOrderService::preview()}, очередь —
 * {@see CraftQueueService::forCharacter()}. Старт ({@see craftStart()}, `:craft_start`) и отмена ожидающего
 * ({@see craftCancel()}, `:craft_cancel`) — то же ядро, что у бота, один раз на `intent_id`. Замок раздела —
 * те же правила, что у бота (цех для «Проф.»), с путём к карточке требования. Нехватка — мост от хаба
 * `/craft` по пути бота до экрана нехватки (`genericCraft_<Key>_1`).
 *
 * W2.N4-03: «🏠 База» (`view=base`) — пикер при 2+ базах, обзор, «что можно построить» с замками, карточка
 * постройки и превью апгрейда из ядра {@see BaseScreenService} / {@see BuildOrderService} /
 * {@see BuildingUpgradeService}, того же, что у бота. Старт стройки ({@see buildStart()}, `:build_start`) и
 * апгрейд ({@see upgrade()}, `:upgrade`) — один раз на `intent_id`. Открытие обзора — визит ({@see BaseScreenService::open()}):
 * одноразовые подсказки веб-игроку уходят в его чат (виртуальный — во входящие). Действия зданий и базы без
 * нативного экрана — мост от «🏠 База» бота ({@see baseRoute()}).
 *
 * w2-n5-deeds-03: «📋 Дела» (`view=tasks`) — хаб ({@see TasksSurfaceService::model()}: задачи с таймером, сводка
 * квестов, задания дня), три списка квестов и развилки ({@see QuestListService}), карточка квеста из строки
 * `quests`, «События» ({@see EventsModelService}). Старт квеста ({@see questStart()}, `:quest_start`) и выбор
 * ветки ({@see questBranch()}, `:quest_branch`) — ядро бота под блокировкой строки персонажа, один раз на
 * `intent_id`. Выключенный хаб или задания дня — строка-замок. «⛔️ Прервать» и «🌐 Квестомания» — мост от
 * `/tasks` бота ({@see tasksRoute()}).
 *
 * w2-n6-trade-storage-03: «🛒 Магазин» (`view=shop`) — хаб, продажа по редкости, карточка ресурса с ценой и
 * пресетами, покупка, опт — модели {@see ResourceShopScreenService}, те же, из которых рисует бот. Продажа
 * ({@see shopSell()}, `:sell`), покупка ({@see shopBuy()}, `:buy`) и опт ({@see bulkSell()}, `:bulk_sell`) — один
 * раз на `intent_id`; «своё число» больше потолка карточки — отказ ядра, ничего не списано; опт несёт отпечаток
 * плана из превью, повтор подтверждения ядро не исполняет. «Продать/Купить крафт» — мост от карточки «Я» бота.
 * «📦 Склад базы» (`view=storage`) — список, забрать всё или вид, положить всё или вид из рюкзака
 * ({@see storageTake()}, `:storage_take`; {@see storagePut()}, `:storage_put`) — ядро {@see BaseStorageService}, гейт
 * «на базе» в нём; вне базы экран показывает замок с путём, кнопки остаются. Кнопки `shop` и `baseStorageList`
 * других экранов открывают эти экраны нативно ({@see viewForCallback()}), мимо моста.
 *
 * Ключ дедупа — `intent_id` + суффикс ступени; {@see intentKey()} держит его в VARCHAR(64)
 * `web_play_intents` при любом допустимом `intent_id`.
 *
 * @phpstan-import-type State from WebScreenStore
 * @phpstan-import-type Msg from WebScreenStore
 * @phpstan-import-type Capture from WebScreenStore
 * @phpstan-import-type Sheet from CharacterSheetService
 * @phpstan-import-type Preview from MarchService
 * @phpstan-type CraftNav array{bench?:string, cat?:string, recipe?:string, confirm?:int}
 * @phpstan-type BaseNav array{b?:int, section?:string, key?:string, id?:int, from?:int}
 * @phpstan-type TasksNav array{section?:string, id?:int}
 * @phpstan-type ShopNav array{section?:string, r?:int, id?:int, pct?:int}
 * @phpstan-type StorageNav array{mode?:string}
 */
class WebNativeScreenService
{
    public const VIEW_ME        = 'me';
    public const VIEW_INVENTORY = 'inventory';
    public const VIEW_GEAR      = 'gear';
    public const VIEW_MAP       = 'map';
    public const VIEW_CRAFT     = 'craft';
    public const VIEW_BASE      = 'base';
    public const VIEW_TASKS     = 'tasks';
    public const VIEW_SHOP      = 'shop';
    public const VIEW_STORAGE   = 'storage';

    /** Экраны, у которых уже есть нативная вьюха. */
    public const VIEWS = [self::VIEW_ME, self::VIEW_INVENTORY, self::VIEW_GEAR, self::VIEW_MAP, self::VIEW_CRAFT, self::VIEW_BASE, self::VIEW_TASKS, self::VIEW_SHOP, self::VIEW_STORAGE];

    /** Кнопки других экранов (`callback_data` бота), которые теперь открывают нативный экран, а не мост. */
    private const NATIVE_CALLBACKS = ['shop' => self::VIEW_SHOP, 'baseStorageList' => self::VIEW_STORAGE];

    /** «🛒 Магазин»: разделы экрана и мутации с дедупом по `intent_id`. */
    public const SHOP_SECTIONS = ['hub', 'sell', 'sell_rarity', 'sell_card', 'buy', 'buy_rarity', 'buy_card', 'bulk'];
    public const OP_SELL       = 'sell';
    public const OP_BUY        = 'buy';
    public const OP_BULK_SELL  = 'bulk_sell';

    /** «📦 Склад базы»: забрать (всё или вид) и положить (всё или вид) — с дедупом по `intent_id`. */
    public const OP_STORAGE_TAKE = 'storage_take';
    public const OP_STORAGE_PUT  = 'storage_put';

    /** Подписи нижнего меню → нативный экран. */
    private const DOCK_VIEWS = [
        '🧑 Я' => self::VIEW_ME, 'Перс' => self::VIEW_ME, '🌍 Мир' => self::VIEW_MAP, 'Карта' => self::VIEW_MAP,
        '🔨 Крафт' => self::VIEW_CRAFT, 'Крафт' => self::VIEW_CRAFT, '🏠 База' => self::VIEW_BASE, 'База' => self::VIEW_BASE,
        '📋 Дела' => self::VIEW_TASKS,
    ];

    /** «📋 Дела»: разделы экрана и мутации с дедупом по `intent_id`. */
    public const TASK_SECTIONS   = ['hub', 'active', 'available', 'completed', 'events', 'quest'];
    public const OP_QUEST_START  = 'quest_start';
    public const OP_QUEST_BRANCH = 'quest_branch';

    /** Вход в мост к кнопкам «Дел» без нативного аналога — slash-команда экрана «📋 Дела» бота. */
    private const TASKS_ENTRY = '/tasks';

    /** «🏠 База»: разделы экрана и мутации с дедупом по `intent_id`. */
    public const BASE_SECTIONS    = ['overview', 'catalog', 'building', 'upgrade'];
    public const OP_BUILD_START   = 'build_start';
    public const OP_UPGRADE       = 'upgrade';

    /** Кнопки экрана базы бота без нативного аналога — мост от «🏠 База» (подпись → callback строит модель). */
    private const BASE_BRIDGE_EXACT = [
        'teleportBeacon', 'TeleportToCamp', 'DeleteBase', 'DeleteBase_FullRelocation', 'demolishBuilding', 'Camp',
    ];

    private const BASE_BRIDGE_PATTERN = '/^((hangar|campDecor|baseDevelopment)_b\d+|building_\d+_[A-Za-z]+_b\d+|genericBuildInfo_[A-Za-z]+(_b\d+)?)$/';

    /** Отказ склада вне базы — что делать, а не голое «нельзя» (тот же смысл, что у бота). */
    private const STORAGE_OFF_BASE = '🚫 Склад физически на базе: положить и забрать можно только стоя на своей клейм-клетке. Вернись на базу — или из поля отправь груз карго-дроном.';

    /** Длина `intent_id` из формы и колонки ключа дедупа (`web_play_intents.intent_id`). */
    public const INTENT_MAX     = 60;
    public const INTENT_KEY_MAX = 64;

    /** Вход в мост к кнопкам карты — slash-команда экрана «🌍 Мир» (работает при любом флаге меню). */
    private const MAP_ENTRY = '/go';

    /** Действие экрана «Я» → нативный экран, который его заменяет. */
    private const NATIVE_ACTIONS = ['inventory' => self::VIEW_INVENTORY, 'gear' => self::VIEW_GEAR];

    /** Операции снаряжения из веба. */
    public const OP_EQUIP   = 'equip';
    public const OP_UNEQUIP = 'unequip';

    /** Клик по клетке карты. */
    public const OP_CELL = 'cell';

    /** Шаг на соседнюю клетку (клик по соседу или роза). */
    public const OP_STEP = 'step';

    /** Поход с карты: превью (не мутация) и мутации с дедупом по `intent_id`. */
    public const OP_MARCH_PREVIEW = 'march_preview';
    public const OP_MARCH_START   = 'march_start';
    public const OP_MARCH_EXTEND  = 'march_extend';
    public const OP_MARCH_RESUME  = 'march_resume';
    public const OP_MARCH_STOP    = 'march_stop';

    public const MARCH_OPS = [self::OP_MARCH_START, self::OP_MARCH_EXTEND, self::OP_MARCH_RESUME, self::OP_MARCH_STOP];

    /** Крафт: старт (кнопка шага или «своё число») и отмена ожидающего — с дедупом по `intent_id`. */
    public const OP_CRAFT_START  = 'craft_start';
    public const OP_CRAFT_CANCEL = 'craft_cancel';

    /**
     * Кнопки моста на нативных экранах (кроме «Я», чьи кнопки берутся из модели) и путь от
     * карточки «Я» до сообщения бота, на котором такая кнопка стоит.
     *
     * @var array<string, list<string>>
     */
    private const BRIDGE_ROUTES = [
        'whereItWent'      => ['inventory'],
        'resourceOverview' => ['inventory'],
        // Снаряжение: путь к стройке Арсенала — с lock-экрана раздела «Оружие».
        'genericBuildInfo_Arsenal' => ['equipMenu', 'gearWeapons'],
        // Магазин: крафт у торговца — с хаба «🛒 Магазин» бота (на карточке «Я»), нативного экрана нет (N6b).
        'sellCraft' => ['shop'],
        'buyCraft'  => ['shop'],
    ];

    /**
     * Кнопки моста с id предмета: продажа снаряжения (ADR-165) стоит на карточке предмета.
     * `$1` — id строки склада из самой кнопки.
     *
     * @var array<string, list<string>>
     */
    private const BRIDGE_PATTERNS = [
        '/^sellGearItem_w_(\d+)$/' => ['equipMenu', 'gearWeapons', 'gearWeaponDetail_$1'],
        '/^sellGearItem_a_(\d+)$/' => ['equipMenu', 'gearArmor', 'gearArmorDetail_$1'],
    ];

    private CharacterSheetService $sheets;

    private InventoryViewService $inventory;

    private EquipmentLoadoutService $loadout;

    private LiveMapService $liveMap;

    private MoveService $move;

    private MarchService $march;

    private CraftOrderService $orders;

    private CraftQueueService $queue;

    private BaseScreenService $bases;

    private BuildOrderService $builds;

    private BuildingUpgradeService $upgrades;

    private TasksSurfaceService $tasks;

    private QuestListService $questLists;

    private EventsModelService $eventsModel;

    private QuestStartService $questStart;

    private QuestChainService $chain;

    private DailyTaskService $daily;

    private ResourceShopScreenService $shop;

    private BaseStorageService $storage;

    public function __construct(
        private ?WebActService $act = null,
        ?CharacterSheetService $sheets = null,
        ?InventoryViewService $inventory = null,
        ?EquipmentLoadoutService $loadout = null,
        ?LiveMapService $liveMap = null,
        ?MoveService $move = null,
        ?MarchService $march = null,
        ?CraftOrderService $orders = null,
        ?CraftQueueService $queue = null,
        ?BaseScreenService $bases = null,
        ?BuildOrderService $builds = null,
        ?BuildingUpgradeService $upgrades = null,
        ?TasksSurfaceService $tasks = null,
        ?QuestListService $questLists = null,
        ?EventsModelService $eventsModel = null,
        ?QuestStartService $questStart = null,
        ?QuestChainService $chain = null,
        ?DailyTaskService $daily = null,
        ?ResourceShopScreenService $shop = null,
        ?BaseStorageService $storage = null
    ) {
        $this->sheets    = $sheets ?? new CharacterSheetService();
        $this->inventory = $inventory ?? new InventoryViewService();
        $this->loadout   = $loadout ?? new EquipmentLoadoutService();
        $this->liveMap   = $liveMap ?? new LiveMapService();
        $this->move      = $move ?? new MoveService();
        $this->march     = $march ?? new MarchService();
        $this->orders    = $orders ?? new CraftOrderService();
        $this->queue     = $queue ?? new CraftQueueService();
        $this->bases     = $bases ?? new BaseScreenService();
        $this->builds    = $builds ?? new BuildOrderService();
        $this->upgrades  = $upgrades ?? new BuildingUpgradeService();
        $this->tasks       = $tasks ?? new TasksSurfaceService();
        $this->questLists  = $questLists ?? new QuestListService();
        $this->eventsModel = $eventsModel ?? new EventsModelService();
        $this->chain       = $chain ?? new QuestChainService();
        $this->questStart  = $questStart ?? new QuestStartService($this->chain);
        $this->daily       = $daily ?? new DailyTaskService();
        $this->shop        = $shop ?? new ResourceShopScreenService();
        $this->storage     = $storage ?? new BaseStorageService();
    }

    public static function isView(mixed $view): bool
    {
        return is_string($view) && in_array($view, self::VIEWS, true);
    }

    /** Нативный экран, заменяющий действие экрана «Я»; null — действие идёт через мост. */
    public static function viewForAction(string $actionId): ?string
    {
        return self::NATIVE_ACTIONS[$actionId] ?? null;
    }

    /** Нативный экран, который открывает кнопка бота с этой `callback_data`; null — кнопка идёт в мост. */
    public static function viewForCallback(string $callback): ?string
    {
        return self::NATIVE_CALLBACKS[$callback] ?? null;
    }

    /**
     * Кнопки моста, которые рисует нативный экран инвентаря (подпись → callback).
     *
     * @return array<string, string>
     */
    public static function inventoryBridgeButtons(): array
    {
        return [
            '📦 Склад базы'      => 'baseStorageList',
            '🧾 Куда ушло'       => 'whereItWent',
            '📊 Все мои ресурсы' => 'resourceOverview',
        ];
    }

    /**
     * Нативный экран по имени — готовый HTML для `#play-state`.
     *
     * @param array<string, mixed> $state  текущее состояние моста (из него берётся док)
     * @param list<Msg>            $events события шага под картой (сообщения экрана моста)
     * @param Preview|null         $preview превью Похода под картой (клик по клетке на луче)
     * @param CraftNav             $craft   где стоит экран крафта: верстак, категория, рецепт
     * @param BaseNav              $base    где стоит экран базы: база, раздел, постройка
     * @param TasksNav             $tasks   где стоит экран «Дела»: раздел, квест карточки
     * @param ShopNav              $shop    где стоит экран магазина: раздел, редкость, ресурс, доля опта
     * @param StorageNav           $storage режим сортировки склада
     *
     * @throws InvalidArgumentException неизвестный экран или нет персонажа
     */
    public function render(int $characterId, string $view, array $state, ?string $alert = null, array $events = [], ?array $preview = null, array $craft = [], array $base = [], array $tasks = [], array $shop = [], array $storage = []): string
    {
        $dock = is_array($state['dock'] ?? null) ? $state['dock'] : [];

        if ($view === self::VIEW_SHOP) {
            return view('site/_play/native_shop', [
                'shop'  => $this->shopModel($characterId, $shop),
                'dock'  => $dock,
                'alert' => $alert,
            ]);
        }

        if ($view === self::VIEW_STORAGE) {
            return view('site/_play/native_storage', [
                'storage' => $this->storageModel($characterId, $storage),
                'dock'    => $dock,
                'alert'   => $alert,
            ]);
        }

        if ($view === self::VIEW_TASKS) {
            return view('site/_play/native_tasks', [
                'tasks' => $this->tasksModel($characterId, $tasks),
                'dock'  => $dock,
                'alert' => $alert,
            ]);
        }

        if ($view === self::VIEW_BASE) {
            return view('site/_play/native_base', [
                'base'  => $this->baseModel($characterId, $base),
                'dock'  => $dock,
                'alert' => $alert,
            ]);
        }

        if ($view === self::VIEW_CRAFT) {
            return view('site/_play/native_craft', [
                'craft' => $this->craftModel($characterId, $craft),
                'dock'  => $dock,
                'alert' => $alert,
            ]);
        }

        if ($view === self::VIEW_MAP) {
            return view('site/_play/native_map', [
                'map'    => $this->liveMap->forCharacter($characterId),
                'dock'   => $dock,
                'alert'   => $alert,
                'events'  => $events,
                'march'   => $this->march->status($characterId),
                'preview' => $preview,
            ]);
        }
        if ($view === self::VIEW_GEAR) {
            return view('site/_play/native_gear', [
                'loadout' => $this->loadout->forCharacter($characterId),
                'dock'    => $dock,
                'alert'   => $alert,
            ]);
        }
        if ($view === self::VIEW_INVENTORY) {
            return view('site/_play/native_inventory', [
                'inventory' => $this->inventory->forCharacter($characterId),
                'dock'      => $dock,
                'alert'     => $alert,
            ]);
        }
        if ($view !== self::VIEW_ME) {
            throw new InvalidArgumentException('unknown view');
        }
        $sheet = $this->sheets->forCharacter($characterId);
        if ($sheet === null) {
            throw new InvalidArgumentException('character not found');
        }

        return view('site/_play/native_me', ['sheet' => $sheet, 'dock' => $dock, 'alert' => $alert]);
    }

    /**
     * HUD персонажа — HTML партиала; пустая строка, если персонажа нет или HUD не собрался
     * (сбой HUD не должен ронять экран и действие — игрок просто не видит полосу до следующего ответа).
     */
    public function hudHtml(int $characterId): string
    {
        try {
            $hud = $this->sheets->hud($characterId);
        } catch (\Throwable $e) {
            log_message('error', '[WebNativeScreenService] hud failed: ' . $e::class . ': ' . $e->getMessage());

            return '';
        }

        return $hud === null ? '' : view('site/_play/hud', ['hud' => $hud]);
    }

    /**
     * Надеть / снять из веба: тот же сервис, что у бота, один раз на `intent_id`.
     *
     * @return string|null текст для игрока; null — повтор того же намерения (ничего не менялось)
     *
     * @throws InvalidArgumentException неизвестная операция, вид предмета или намерение
     */
    public function gearChange(int $accountId, int $characterId, string $op, string $kind, int $rowId, string $intentId): ?string
    {
        if (! in_array($op, [self::OP_EQUIP, self::OP_UNEQUIP], true)
            || ! in_array($kind, [EquipmentLoadoutService::KIND_WEAPON, EquipmentLoadoutService::KIND_ARMOR], true)
            || $rowId <= 0) {
            throw new InvalidArgumentException('bad gear change');
        }
        self::assertIntent($intentId);
        $claimed = (new ConditionalWriteService())->insertUnique('web_play_intents', [
            'account_id' => $accountId,
            'intent_id'  => self::intentKey($intentId, ':gear'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($claimed !== WriteOutcome::Applied) {
            return null;
        }

        $outcome = $op === self::OP_EQUIP
            ? $this->loadout->equip($characterId, $kind, $rowId)
            : $this->loadout->unequip($characterId, $kind, $rowId);
        $name = $outcome['item']['name'] ?? '';
        if (! $outcome['ok']) {
            return EquipmentLoadoutService::refusal($kind, $outcome['code'], $name);
        }

        return match ($outcome['code']) {
            EquipmentLoadoutService::EQUIPPED   => $kind === EquipmentLoadoutService::KIND_WEAPON
                ? "Надето: «{$name}». Остальное оружие снято."
                : "Надето: «{$name}». Остальное в слоте «{$outcome['slot']}» снято.",
            EquipmentLoadoutService::UNEQUIPPED => "Снято: «{$name}».",
            default                             => $outcome['item'] !== null && $outcome['item']['equipped']
                ? "«{$name}» уже надето."
                : "«{$name}» уже снято.",
        };
    }

    /**
     * Шаг из веба: тот же {@see MoveService}, что у бота, один раз на `intent_id`. Хуки шага с чатом
     * идут под захватом; их сообщения и события шага ложатся на экран моста (кнопки — через мост).
     *
     * @return array{alert: ?string, events: list<Msg>} ответ и события под картой; повтор того же
     *                                                  намерения — `alert = null`, событий нет
     *
     * @throws InvalidArgumentException неизвестное направление, намерение или у персонажа нет личности
     */
    public function step(int $accountId, int $characterId, string $dir, string $intentId): array
    {
        if (! MoveService::isDirection($dir)) {
            throw new InvalidArgumentException('bad direction');
        }
        self::assertIntent($intentId);
        $identity = (new VirtualIdentityService())->identityForCharacter($characterId);
        if ($identity === null) {
            throw new InvalidArgumentException('character has no identity');
        }
        $claimed = (new ConditionalWriteService())->insertUnique('web_play_intents', [
            'account_id' => $accountId,
            'intent_id'  => self::intentKey($intentId, ':step'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($claimed !== WriteOutcome::Applied) {
            return ['alert' => null, 'events' => []];
        }

        $store = new WebScreenStore();
        WebDelivery::beginCapture($identity['telegram_id'], $characterId);
        try {
            $outcome = $this->move->step($characterId, $dir);
            if ($outcome['ok']) {
                try {
                    $this->move->afterStep($outcome, $identity['telegram_id']);
                } catch (\Throwable $e) {
                    // Шаг уже записан: сбой хука не должен превращать его в ошибку для игрока.
                    log_message('error', '[WebNativeScreenService] step hooks failed: ' . $e::class . ': ' . $e->getMessage());
                }
            }
        } finally {
            $capture = WebDelivery::endCapture();
        }

        if (! $outcome['ok']) {
            return ['alert' => str_replace('*', '', (string) $outcome['message']), 'events' => []];
        }

        foreach ($outcome['events'] as $event) {
            $capture['sent'][] = [
                'message_id'      => $store->nextMessageId($characterId),
                'text'            => $event['text'],
                'caption'         => null,
                'parse_mode'      => $event['type'] === MoveService::EVENT_DEBUFF ? 'Markdown' : null,
                'photo_url'       => null,
                'inline_keyboard' => [$event['buttons']],
            ];
        }
        if ($capture['sent'] !== []) {
            $store->applyCapture($characterId, $capture);
        }

        return ['alert' => 'Вы двинулись на: ' . MoveService::DIRECTION_RU[$dir] . '.', 'events' => $capture['sent']];
    }

    /**
     * Превью Похода (экран маршрута бота): не мутация, дедупа нет.
     *
     * @return Preview отказ — `ok = false` и `message`
     *
     * @throws InvalidArgumentException неизвестное направление
     */
    public function marchPreview(int $characterId, string $dir, int $n): array
    {
        if (! MarchService::isDirection($dir)) {
            throw new InvalidArgumentException('bad direction');
        }

        return $this->march->preview($characterId, $dir, $n);
    }

    /**
     * Поход из веба: старт, продление, возобновление, остановка — тот же {@see MarchService}, что у
     * бота, один раз на `intent_id`. Подсказки первого Похода (им нужен чат) идут под захватом и
     * ложатся под карту, как события шага.
     *
     * @return array{alert: ?string, events: list<Msg>} повтор того же намерения — `alert = null`
     *
     * @throws InvalidArgumentException неизвестная операция, направление, намерение или нет личности
     */
    public function march(int $accountId, int $characterId, string $op, string $dir, int $n, string $intentId): array
    {
        if (! in_array($op, self::MARCH_OPS, true) || ($op === self::OP_MARCH_START && ! MarchService::isDirection($dir))) {
            throw new InvalidArgumentException('bad march op');
        }
        self::assertIntent($intentId);
        $identity = $op === self::OP_MARCH_START ? (new VirtualIdentityService())->identityForCharacter($characterId) : null;
        if ($op === self::OP_MARCH_START && $identity === null) {
            throw new InvalidArgumentException('character has no identity');
        }
        $claimed = (new ConditionalWriteService())->insertUnique('web_play_intents', [
            'account_id' => $accountId,
            'intent_id'  => self::intentKey($intentId, ':' . $op),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($claimed !== WriteOutcome::Applied) {
            return ['alert' => null, 'events' => []];
        }

        if ($op === self::OP_MARCH_EXTEND) {
            return ['alert' => $this->march->extend($characterId, $n)['message'], 'events' => []];
        }
        if ($op === self::OP_MARCH_RESUME) {
            return ['alert' => $this->march->resume($characterId)['message'], 'events' => []];
        }
        if ($op === self::OP_MARCH_STOP) {
            return ['alert' => $this->march->stop($characterId)['message'], 'events' => []];
        }

        $outcome = $this->march->start($characterId, $dir, $n);
        if (! $outcome['ok']) {
            return ['alert' => $outcome['message'], 'events' => []];
        }
        WebDelivery::beginCapture($identity['telegram_id'], $characterId);
        try {
            $this->march->afterStart($characterId, $outcome['n'], $identity['telegram_id']);
        } catch (\Throwable $e) {
            log_message('error', '[WebNativeScreenService] march hints failed: ' . $e::class . ': ' . $e->getMessage());
        } finally {
            $capture = WebDelivery::endCapture();
        }
        if ($capture['sent'] !== []) {
            (new WebScreenStore())->applyCapture($characterId, $capture);
        }

        return ['alert' => $outcome['message'], 'events' => $capture['sent']];
    }

    /**
     * Модель экрана «🏠 База». Какая база — ядро (`resolve()`: своя клетка, единственная, выбор при 2+;
     * `b` перепроверяется). Обзор — визит, как у бота ({@see BaseScreenService::open()}). Разделы: `catalog` —
     * «что можно построить», `building` — карточка постройки `key`, `upgrade` — превью апгрейда постройки `id`
     * (тип `buildings.id`). Неизвестный раздел — обзор.
     *
     * @param BaseNav $nav
     *
     * @return array<string, mixed>
     */
    public function baseModel(int $characterId, array $nav): array
    {
        $resolved = $this->bases->resolve($characterId, $nav['b'] ?? null);
        $model    = [
            'state'    => $resolved['state'],
            'text'     => $resolved['text'],
            'base_id'  => $resolved['base_id'],
            'bases'    => array_map(static fn (array $b): array => [
                'id' => $b['base_id'], 'name' => $b['name'], 'x' => $b['x'], 'y' => $b['y'], 'covered' => $b['isCovered'], 'distance' => $b['distance'],
            ], $resolved['bases']),
            'section'  => 'overview',
            'overview' => null,
            'bridge'   => [],
            'catalog'  => null,
            'refusal'  => '',
            'card'     => null,
            'upgrade'  => null,
        ];
        if ($resolved['state'] === BaseScreenService::STATE_FAR) {
            $model['overview'] = $this->bases->overview($characterId, $resolved['base_id']);
        }
        if ($resolved['state'] !== BaseScreenService::STATE_BASE) {
            return $model;
        }

        $baseId  = $resolved['base_id'];
        $section = in_array($nav['section'] ?? null, self::BASE_SECTIONS, true) ? $nav['section'] : 'overview';
        if ($section === 'overview') {
            // Визит и онбординг-подсказки — как при открытии «🏠 База» в боте; сбой не роняет экран.
            try {
                $this->bases->open($characterId, $baseId);
            } catch (\Throwable $e) {
                log_message('error', '[WebNativeScreenService] base open failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }
        $overview = $this->bases->overview($characterId, $baseId, $resolved['coverage']);
        if ($overview === null) {
            $model['state'] = BaseScreenService::STATE_UNAVAILABLE;
            $model['text']  = \App\Services\Bases\BaseScopeResolver::TEXT_UNAVAILABLE;

            return $model;
        }
        $model['overview'] = $overview;
        $model['bridge']   = self::baseBridgeButtons($baseId, $overview['base']['decor_enabled']);
        $model['section']  = $section;

        if ($section === 'catalog') {
            $catalog          = $this->builds->catalog($characterId, $baseId);
            $model['catalog'] = $catalog['items'];
            $model['refusal'] = self::plain($catalog['refusal']);
        } elseif ($section === 'building' && isset($nav['key'])) {
            $model['card'] = $this->builds->preview($characterId, $baseId, $nav['key']);
        } elseif ($section === 'upgrade' && isset($nav['id'])) {
            $model['upgrade'] = $this->upgrades->preview($characterId, $baseId, $nav['id']);
        } else {
            // Карточка без ключа или апгрейд без постройки — обзор.
            $model['section'] = 'overview';
        }

        return $model;
    }

    /**
     * Старт стройки из веба: то же ядро, что у бота, один раз на `intent_id`. Отказ пишется в `action_log`
     * тем же кодом, что у бота (`BUILD_<Key>`, причина ядра).
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохой ключ постройки или намерение
     */
    public function buildStart(int $accountId, int $characterId, ?int $baseId, string $key, string $intentId): ?string
    {
        if (preg_match('/^[A-Za-z]{1,40}$/', $key) !== 1) {
            throw new InvalidArgumentException('bad building key');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_BUILD_START)) {
            return null;
        }

        $out = $this->builds->start($characterId, $baseId, $key);
        if (! $out['ok']) {
            if ($out['log'] !== null) {
                $this->logRejected($characterId, $out['log']['action'], $out['log']['reason'], $out['log']['extra']);
            }

            return self::plain($out['message']);
        }
        $name = $out['recipe'] !== null ? trim($out['recipe']['emoji'] . ' ' . $out['recipe']['name']) : $key;

        return "🏗 Стройка начата: {$name}. Готово через {$out['minutes']} мин — таймер в строке задач сверху.";
    }

    /**
     * Апгрейд постройки из веба: то же ядро, что у бота (условная оплата, уровень только из текущего), один
     * раз на `intent_id`. `$fromLevel` — уровень из превью (поле формы `from`); нет его или постройка уже на
     * другом — отказ ядра `stale`, ничего не списано.
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохая постройка или намерение
     */
    public function upgrade(int $accountId, int $characterId, ?int $baseId, int $buildingId, ?int $fromLevel, string $intentId): ?string
    {
        if ($buildingId <= 0) {
            throw new InvalidArgumentException('bad building id');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_UPGRADE)) {
            return null;
        }

        $out = $this->upgrades->apply($characterId, $baseId, $buildingId, $fromLevel);
        if ($out['ok']) {
            return '⬆️ «' . ($out['name'] ?? 'Постройка') . "»: уровень {$out['current_level']} → {$out['level']}.";
        }
        if ($out['code'] === BuildingUpgradeService::MISSING) {
            return "Не хватает ресурсов для уровня {$out['next_level']}: " . implode('; ', array_map(static fn (string $l): string => ltrim($l, '- '), $out['missing'])) . '.';
        }

        return self::plain($out['message']);
    }

    /**
     * Кнопки экрана базы бота, у которых нет нативного экрана: они уходят в мост с той же `callback_data`
     * (суффикс базы — у тех, что его несут в боте). Подписи — как у бота; раскладка — как в
     * {@see \App\Services\Bases\BaseServiceMessageFormatter::baseBuildings()}.
     *
     * @return list<array{text: string, data: string}>
     */
    private static function baseBridgeButtons(int $baseId, bool $decorEnabled): array
    {
        $out = [
            ['text' => '📡 Маяки', 'data' => 'teleportBeacon'],
            ['text' => '🤖 Ангар', 'data' => BaseCallbackSuffix::append('hangar', $baseId)],
        ];
        if ($decorEnabled) {
            $out[] = ['text' => '🎨 Декор', 'data' => BaseCallbackSuffix::append('campDecor', $baseId)];
        }
        $out[] = ['text' => '🏗 Развитие базы', 'data' => BaseCallbackSuffix::append('baseDevelopment', $baseId)];
        if (BotMenuService::craftBaseHubEnabled()) {
            $out[] = ['text' => '📦 Склад базы', 'data' => 'baseStorageList'];
            $out[] = ['text' => '📡 Телепорт', 'data' => 'TeleportToCamp'];
            $out[] = ['text' => '⚠️ Снести / переехать', 'data' => 'DeleteBase'];
        } else {
            $out[] = ['text' => '📡 Телепорт', 'data' => 'TeleportToCamp'];
            $out[] = ['text' => '❌ Удалить базу', 'data' => 'DeleteBase'];
            $out[] = ['text' => '🚚 Полноценный переезд', 'data' => 'DeleteBase_FullRelocation'];
        }
        $out[] = ['text' => '🔨 Снести постройку', 'data' => 'demolishBuilding'];

        return $out;
    }

    /**
     * Путь бота от «🏠 База» до сообщения с кнопкой экрана базы: выбор базы на пикере (`Base_b<id>`), затем
     * экран построек (`construction_b<id>`) для карточек зданий или «🏗 Строить» (`Build_b<id>`) для карточки
     * стройки. Ступени необязательные — пропускаются, если бот уже показал нужный экран. null — кнопка не с
     * экрана базы.
     *
     * @return list<string>|null
     */
    private static function baseRoute(string $callback): ?array
    {
        if (! in_array($callback, self::BASE_BRIDGE_EXACT, true) && preg_match(self::BASE_BRIDGE_PATTERN, $callback) !== 1) {
            return null;
        }
        [$plain, $baseId] = BaseCallbackSuffix::split($callback);
        $steps = [];
        if ($baseId !== null) {
            $steps[] = BaseCallbackSuffix::append('Base', $baseId);
        }
        if (str_starts_with($plain, 'building_') || $plain === 'baseDevelopment') {
            $steps[] = $baseId !== null ? BaseCallbackSuffix::append('construction', $baseId) : 'construction';
        }
        if (str_starts_with($plain, 'genericBuildInfo_')) {
            $steps[] = $baseId !== null ? BaseCallbackSuffix::append('Build', $baseId) : 'Build';
        }

        return $steps;
    }

    /**
     * Кнопка экрана базы через мост: «🏠 База» бота текстом нижнего меню, необязательные ступени
     * {@see baseRoute()}, затем сама кнопка — только если она стоит на сообщении бота.
     *
     * @param list<string> $steps
     *
     * @return array{state: State, alert: ?string, unread: int}
     */
    private function baseBridge(WebActService $act, int $accountId, int $characterId, string $callback, string $intentId, array $steps): array
    {
        $result = $act->current($characterId);
        if (self::messageWith($result['state'], $callback) === null) {
            $result = $act->act($accountId, $characterId, [
                'intent_id' => self::intentKey($intentId, ':card'),
                'kind'      => WebActService::KIND_TEXT,
                'data'      => BotMenuService::menuLabel('base'),
            ]);
            foreach ($steps as $i => $step) {
                if (self::messageWith($result['state'], $callback) !== null) {
                    break;
                }
                $messageId = self::messageWith($result['state'], $step);
                if ($messageId === null) {
                    continue;
                }
                $result = $act->act($accountId, $characterId, [
                    'intent_id'  => self::intentKey($intentId, ':s' . $i),
                    'kind'       => WebActService::KIND_CALLBACK,
                    'data'       => $step,
                    'message_id' => (string) $messageId,
                ]);
            }
        }

        $messageId = self::messageWith($result['state'], $callback);
        if ($messageId === null) {
            return $result;
        }

        return $act->act($accountId, $characterId, [
            'intent_id'  => self::intentKey($intentId, ':cb'),
            'kind'       => WebActService::KIND_CALLBACK,
            'data'       => $callback,
            'message_id' => (string) $messageId,
        ]);
    }

    /**
     * Модель экрана «🔨 Крафт»: верстаки (с замками), категории выбранного верстака, рецепты выбранной
     * категории, карточка выбранного рецепта и очередь. Неизвестные или запертые ступени навигации
     * отбрасываются до ближайшей допустимой (ссылка из старой вкладки не роняет экран).
     *
     * @param CraftNav $nav
     *
     * @return array<string, mixed>
     */
    public function craftModel(int $characterId, array $nav): array
    {
        $catalog = new CraftCatalog();
        $recipes = new CraftRecipes();

        $benches = [];
        $locked  = [];
        foreach ($catalog->benches as $key => $bench) {
            $lock = $bench['lock'];
            if ($lock !== null && (new CraftedItemsLogModel())->ownedQuantityByNameEng($lock['item'], $characterId) > 0) {
                $lock = null;
            }
            $locked[$key] = $lock !== null;
            $benches[]    = [
                'key'   => $key,
                'label' => $bench['label'],
                'lock'  => $lock === null ? null : [
                    'title'  => '🔒 ' . self::shortLabel($bench['label']) . ' (нужно: ' . $lock['need'] . ')',
                    'why'    => 'Раздел откроется, когда ' . $lock['need'] . ' будет собран и будет лежать у тебя. Карточка сборки покажет, чего не хватает.',
                    'path'   => $catalog->benches[$lock['bench']]['label'] . ' → ' . $catalog->benches[$lock['bench']]['categories'][$lock['cat']]['label'] . ' → ' . $lock['need'],
                    'target' => ['bench' => $lock['bench'], 'cat' => $lock['cat'], 'recipe' => $lock['recipe']],
                ],
            ];
        }

        $benchKey = $nav['bench'] ?? null;
        if ($benchKey !== null && (! isset($catalog->benches[$benchKey]) || $locked[$benchKey])) {
            $benchKey = null;
        }
        $cats   = [];
        $catKey = null;
        $list   = [];
        foreach ($benchKey !== null ? $catalog->benches[$benchKey]['categories'] : [] as $key => $cat) {
            $visible = $this->visibleRecipes($cat);
            if ($visible === []) {
                continue;
            }
            $cats[] = ['key' => $key, 'label' => $cat['label'], 'count' => count($visible)];
            if (($nav['cat'] ?? null) !== $key) {
                continue;
            }
            $catKey = $key;
            foreach ($visible as $recipeKey) {
                $r      = $recipes->get($recipeKey) ?? [];
                $list[] = [
                    'key'  => $recipeKey,
                    'name' => is_string($r['item_name_rus'] ?? null) ? $r['item_name_rus'] : $recipeKey,
                    'icon' => is_string($r['icon_emoji'] ?? null) ? $r['icon_emoji'] : '🛠',
                ];
            }
        }

        $queue     = $this->queue->forCharacter($characterId);
        $card      = null;
        $recipeKey = $nav['recipe'] ?? null;
        if ($recipeKey !== null && in_array($recipeKey, array_column($list, 'key'), true)) {
            $pv   = $this->orders->preview($characterId, $recipeKey, 1);
            // Номер — как в списке очереди `/play`: ожидающих + 1, если старт уйдёт в очередь (идёт крафт
            // этого рецепта); иначе старт сразу и строки нет. `queue_pos` ядра — номер бота, здесь не он.
            $willQueue = in_array($recipeKey, array_column($queue['active'], 'recipe'), true);
            $card      = ['queue_pos' => $willQueue ? count($queue['queued']) + 1 : null] + $pv + [
                'steps'    => array_values(array_filter(CraftCardHelper::STEPS, static fn (int $n): bool => $n <= $pv['max_qty'])),
                'shortage' => $pv['code'] === CraftOrderService::MISSING_MATERIALS ? (new CraftCardHelper())->fallbackButton($recipeKey) : null,
                'confirm'  => $this->confirmPanel($characterId, $recipeKey, $nav['confirm'] ?? 0, $pv['max_qty']),
            ];
        }

        return [
            'benches' => $benches,
            'bench'   => $benchKey,
            'cats'    => $cats,
            'cat'     => $catKey,
            'recipes' => $list,
            'card'    => $card,
            'queue'   => $queue,
        ];
    }

    /**
     * craft-batch-price-confirm: панель «проверь перед запуском» для партии, на которую ядро ответило
     * `confirm_required`. Итог — {@see CraftOrderService::preview()} на это количество, тот же, что у бота;
     * своего порога у веба нет. Панели нет, если партия больше не требует подтверждения (порог сменили в
     * админке, сырьё ушло) — тогда карточка показывает обычные кнопки.
     *
     * @return array{qty:int, gold:int, minutes_total:int, reqs:list<array{name:string,need:int}>}|null
     */
    private function confirmPanel(int $characterId, string $recipeKey, int $qty, int $maxQty): ?array
    {
        if ($qty < 1 || $qty > $maxQty) {
            return null;
        }
        $big = $this->orders->preview($characterId, $recipeKey, $qty);
        if (! $big['ok'] || ! $big['needs_confirm']) {
            return null;
        }
        $reqs = [];
        foreach (array_merge($big['resources'], $big['items']) as $row) {
            $reqs[] = ['name' => $row['name'], 'need' => $row['need']];
        }

        return ['qty' => $qty, 'gold' => $big['gold'], 'minutes_total' => $big['minutes_total'], 'reqs' => $reqs];
    }

    /**
     * Старт крафта из веба: то же ядро, что у бота, один раз на `intent_id`. Количество — от 1 до
     * `max_qty` карточки (сырьё, золото, лимит очереди); больше — отказ без старта. Рецепт, который экран
     * сейчас скрывает ({@see visibleRecipes()}), — отказ без старта. Каждый отказ пишется в `action_log`
     * как у бота (`CRAFT_<Key>`, причина ядра). Номер в очереди — `position` новой строки в списке `/play`.
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException рецепта нет в каталоге веба, количество < 1 или плохое намерение
     */
    public function craftStart(int $accountId, int $characterId, string $recipeKey, int $qty, string $intentId): ?string
    {
        return $this->craftStartOutcome($accountId, $characterId, $recipeKey, $qty, $intentId)['alert'];
    }

    /**
     * {@see craftStart()} с исходом подтверждения (craft-batch-price-confirm): `confirm` — количество, на
     * которое ядро ответило `confirm_required` (ничего не списано, экран покажет панель итога); `null` —
     * обычный исход в `alert`. `$confirmed` — игрок нажал «✅ Запустить» на панели.
     *
     * @return array{alert:?string, confirm:?int}
     *
     * @throws InvalidArgumentException рецепта нет в каталоге веба, количество < 1 или плохое намерение
     */
    public function craftStartOutcome(int $accountId, int $characterId, string $recipeKey, int $qty, string $intentId, bool $confirmed = false): array
    {
        $alert = static fn (?string $text): array => ['alert' => $text, 'confirm' => null];
        if ((new CraftCatalog())->locate($recipeKey) === null || $qty < 1) {
            throw new InvalidArgumentException('bad craft start');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_CRAFT_START)) {
            return $alert(null);
        }

        if (! $this->recipeVisible($recipeKey)) {
            $this->logCraftRejected($characterId, $recipeKey, 'recipe_hidden', ['qty' => $qty]);

            return $alert('Этот рецепт сейчас недоступен.');
        }

        $pv = $this->orders->preview($characterId, $recipeKey, 1);
        if ($pv['ok'] && $qty > $pv['max_qty']) {
            $this->logCraftRejected($characterId, $recipeKey, 'qty_over_max', ['qty' => $qty, 'max_qty' => $pv['max_qty']]);

            return $alert("Столько не выйдет: сейчас можно поставить не больше {$pv['max_qty']} шт.");
        }
        $out = $this->orders->start($characterId, $recipeKey, $qty, $confirmed);
        if ($out['code'] === CraftOrderService::CONFIRM_REQUIRED) {
            // Не отказ, а вопрос: ничего не списано, экран покажет панель итога. В action_log не пишем.
            return ['alert' => null, 'confirm' => $qty];
        }
        if (! $out['ok']) {
            $this->logCraftRejected($characterId, $recipeKey, $out['log']['reason'] ?? $out['code'], $out['log']['extra'] ?? []);

            return $alert(self::plain($out['message']));
        }
        $what = trim($pv['recipe']['icon'] . ' ' . $pv['recipe']['name']) . " ×{$qty}";
        if ($out['code'] !== CraftOrderService::QUEUED) {
            return $alert("🛠 Крафт начат: {$what}. Готово через {$out['minutes_total']} мин.");
        }

        $queued = $this->queue->forCharacter($characterId)['queued'];
        $pos    = count($queued) + 1;
        foreach ($queued as $row) {
            if ($row['charTaskId'] === $out['char_task_id']) {
                $pos = $row['position'];
                break;
            }
        }

        return $alert("📋 В очереди: {$what} — №{$pos}. Начнётся, когда закончится текущий.");
    }

    /**
     * Отмена ожидающего крафта: то же ядро, что у бота (возврат туда, откуда списано), один раз на `intent_id`.
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохая строка очереди или намерение
     */
    public function craftCancel(int $accountId, int $characterId, int $charTaskId, string $intentId): ?string
    {
        if ($charTaskId <= 0) {
            throw new InvalidArgumentException('bad craft cancel');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_CRAFT_CANCEL)) {
            return null;
        }
        $out = $this->queue->cancel($characterId, $charTaskId);

        return $out['ok']
            ? "❌ Отменено: {$out['name']} ×{$out['qty']}. Сырьё и золото вернулись туда, откуда были взяты."
            : self::plain($out['message']);
    }

    /**
     * Модель экрана «📋 Дела»: `hub` — задачи с таймером, сводка квестов, задания дня (замок, если хаб или
     * задания дня выключены — те же флаги, что у бота); `active` / `available` (с замками цепочки и
     * развилками) / `completed` — списки квестов; `quest` — карточка квеста `id` из строки `quests` со статусом
     * у персонажа; `events` — активные и прошедшие события. Неизвестный раздел — хаб, чужой квест — «Доступные».
     *
     * @param TasksNav $nav
     *
     * @return array<string, mixed>
     */
    public function tasksModel(int $characterId, array $nav): array
    {
        $section = in_array($nav['section'] ?? null, self::TASK_SECTIONS, true) ? $nav['section'] : 'hub';
        $hubOn   = $this->tasks->enabled();
        $dailyOn = $this->daily->enabled();
        $model   = [
            'section'       => $section,
            'hub_enabled'   => $hubOn,
            'daily_enabled' => $dailyOn,
            'hub'           => null,
            'active'        => [],
            'available'     => [],
            'completed'     => [],
            'branches'      => [],
            'card'          => null,
            'events'        => null,
        ];

        if ($section === 'hub') {
            if ($hubOn) {
                if ($dailyOn) {
                    // Набор на сегодня — как при заходе в бот (под блокировкой строки персонажа, без дублей дня).
                    try {
                        $this->daily->ensureAssigned(['id' => $characterId, 'level' => $this->characterLevel($characterId)]);
                    } catch (\Throwable $e) {
                        log_message('error', '[WebNativeScreenService] daily assign failed: ' . $e::class . ': ' . $e->getMessage());
                    }
                }
                $model['hub'] = $this->tasks->model($characterId);
            }

            return $model;
        }
        if ($section === 'events') {
            $model['events'] = $this->eventsModel->model($characterId);
        } elseif ($section === 'active') {
            $model['active'] = $this->questLists->active($characterId);
        } elseif ($section === 'completed') {
            $model['completed'] = $this->questLists->completed($characterId);
        } elseif ($section === 'quest') {
            $model['card'] = $this->questCard($characterId, $nav['id'] ?? 0);
        }
        if ($section === 'available' || ($section === 'quest' && $model['card'] === null)) {
            $model['section']   = 'available';
            $model['available'] = $this->questLists->available($characterId);
            $model['branches']  = $this->questLists->branches($characterId);
        }

        return $model;
    }

    /**
     * Старт квеста из веба: то же ядро, что у бота ({@see QuestStartService::start()} — проверка «уже начат» и
     * вставка под блокировкой строки персонажа), один раз на `intent_id`. Отказ пишется в `action_log`.
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохой квест или намерение
     */
    public function questStart(int $accountId, int $characterId, int $questId, string $intentId): ?string
    {
        if ($questId <= 0) {
            throw new InvalidArgumentException('bad quest id');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_QUEST_START)) {
            return null;
        }

        $quest   = (new QuestModel())->find($questId);
        $titleEn = is_array($quest) && is_string($quest['title_en'] ?? null) ? $quest['title_en'] : '';
        $out     = $this->questStart->start($characterId, $titleEn);
        if (! $out['ok']) {
            $this->logRejected($characterId, 'QUEST_START_' . ($titleEn !== '' ? $titleEn : (string) $questId), $out['code']);

            return self::plain($out['message']);
        }
        $reward = $out['reward'] > 0 ? " Награда за завершение: {$out['reward']}." : '';

        return '📜 Квест начат: ' . ($out['title_ru'] ?? $titleEn) . ".{$reward} Прогресс — в «🚀 Активные».";
    }

    /**
     * Выбор ветки развилки из веба: то же ядро, что у бота ({@see QuestChainService::chooseBranch()} — под
     * блокировкой строки персонажа), один раз на `intent_id`. Выбор необратим.
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохой квест или намерение
     */
    public function questBranch(int $accountId, int $characterId, int $questId, string $intentId): ?string
    {
        if ($questId <= 0) {
            throw new InvalidArgumentException('bad branch id');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_QUEST_BRANCH)) {
            return null;
        }
        $out = $this->chain->chooseBranch($characterId, $questId);
        if (! $out['ok']) {
            $this->logRejected($characterId, 'QUEST_BRANCH_' . $questId, $out['reason']);

            return QuestChainService::branchRefusalText($out['reason']);
        }
        $title = $out['title_ru'] !== '' ? $out['title_ru'] : 'выбранный путь';

        return "🔀 Путь выбран: {$title}." . ($out['reward'] > 0 ? " Награда за завершение: {$out['reward']} золота." : '')
            . ' Остальные ветки этой развилки закрыты.';
    }

    /**
     * Карточка квеста `id` со статусом у персонажа: идёт, завершён, можно начать или заперт звеном цепочки;
     * null — квеста нет ни в одном списке персонажа (чужое и выключенное флагом не показываем).
     *
     * @return array<string, mixed>|null
     */
    private function questCard(int $characterId, int $questId): ?array
    {
        if ($questId <= 0) {
            return null;
        }
        foreach (['active' => $this->questLists->active($characterId), 'completed' => $this->questLists->completed($characterId)] as $status => $list) {
            foreach ($list as $q) {
                if ($q['id'] === $questId) {
                    return $q + ['status' => $status, 'lock_reason' => ''];
                }
            }
        }
        foreach ($this->questLists->available($characterId) as $q) {
            if ($q['id'] === $questId) {
                return $q + ['status' => $q['locked'] ? 'locked' : 'available'];
            }
        }

        return null;
    }

    /**
     * Модель экрана «🛒 Магазин» — разделы из ядра {@see ResourceShopScreenService}, тех же моделей, что рисует бот.
     * Неизвестный раздел, редкость вне 1…10 или чужой ресурс — ближайший допустимый раздел (ссылка из старой
     * вкладки не роняет экран).
     *
     * @param ShopNav $nav
     *
     * @return array<string, mixed>
     */
    public function shopModel(int $characterId, array $nav): array
    {
        $section = in_array($nav['section'] ?? null, self::SHOP_SECTIONS, true) ? $nav['section'] : 'hub';
        $rarity  = isset($nav['r']) && in_array($nav['r'], ResourceShopScreenService::RARITIES, true) ? $nav['r'] : null;
        $model   = [
            'section'     => $section,
            'hub'         => $this->shop->hubEntries(),
            'bulk_on'     => $this->shop->bulkEnabled(),
            'rarity'      => $rarity,
            'sell_hub'    => null,
            'sell_rarity' => null,
            'card'        => null,
            'buy_hub'     => null,
            'buy_rarity'  => null,
            'bulk'        => null,
        ];

        if ($section === 'sell_card' && isset($nav['id'])) {
            $model['card'] = $this->shop->sellCardModel($characterId, $nav['id']);
        } elseif ($section === 'buy_card' && isset($nav['id'])) {
            $model['buy_hub'] = $this->shop->buyHubModel($characterId);
            $model['card']    = $model['buy_hub']['allowed'] ? $this->shop->buyCardModel($characterId, $nav['id']) : null;
        } elseif ($section === 'bulk' && isset($nav['pct'])) {
            $bulk          = $this->shop->bulkPreviewModel($characterId, $rarity, $nav['pct']);
            $model['bulk'] = $bulk['code'] === ResourceShopScreenService::INVALID ? null : $bulk;
        }
        if (in_array($section, ['sell_card', 'buy_card', 'bulk'], true) && $model['card'] === null && $model['bulk'] === null) {
            // Карточка без ресурса, покупка без золота на минимум или опт без доли — раздел уровнем выше.
            $section = $section === 'buy_card' ? 'buy' : 'sell';
        }

        if ($section === 'sell_rarity' && $rarity !== null) {
            $model['sell_rarity'] = $this->shop->sellRarityModel($characterId, $rarity);
        } elseif ($section === 'buy_rarity' && $rarity !== null) {
            $model['buy_hub']    = $this->shop->buyHubModel($characterId);
            $model['buy_rarity'] = $model['buy_hub']['allowed'] ? $this->shop->buyRarityModel($rarity) : null;
            $section             = $model['buy_rarity'] === null ? 'buy' : $section;
        } elseif (in_array($section, ['sell_rarity', 'buy_rarity'], true)) {
            $section = $section === 'sell_rarity' ? 'sell' : 'buy';
        }
        if ($section === 'sell') {
            $model['sell_hub'] = $this->shop->sellHubModel($characterId);
        } elseif ($section === 'buy') {
            $model['buy_hub'] = $this->shop->buyHubModel($characterId);
        }
        $model['section'] = $section;

        return $model;
    }

    /**
     * Продажа сырья из веба: кнопка-пресет или «своё число» — {@see ResourceShopScreenService::sell()} с потолком
     * «сколько есть», один раз на `intent_id`. Сделка пишется в `action_log` тем же кодом и голосом, что у бота
     * (`SELL_RESOURCE`: лента «Куда ушло» и шаг онбординга «продай что-нибудь» её читают).
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохой ресурс или намерение
     */
    public function shopSell(int $accountId, int $characterId, int $resourceId, int $qty, string $intentId): ?string
    {
        if ($resourceId <= 0) {
            throw new InvalidArgumentException('bad resource id');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_SELL)) {
            return null;
        }

        $card = $this->shop->sellCardModel($characterId, $resourceId);
        $out  = $this->shop->sell($characterId, $resourceId, $qty);
        if ($out['code'] !== ResourceShopScreenService::OK) {
            return self::plain($out['message']);
        }
        $name = $card !== null && $card['name'] !== '' ? $card['name'] : "Ресурс#{$resourceId}";
        $this->logDone($characterId, 'SELL_RESOURCE', ResourceTradeService::describeTrade('Продажа', $name, $out['qty'], $out['amount']));

        return self::plain($out['message']);
    }

    /**
     * Покупка сырья из веба: {@see ResourceShopScreenService::buy()} с потолком «сколько оплатит свежее золото»,
     * один раз на `intent_id`. Запись `BUY_RESOURCE` ядро пишет само.
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохой ресурс или намерение
     */
    public function shopBuy(int $accountId, int $characterId, int $resourceId, int $qty, string $intentId): ?string
    {
        if ($resourceId <= 0) {
            throw new InvalidArgumentException('bad resource id');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_BUY)) {
            return null;
        }
        if (! $this->shop->buyHubModel($characterId)['allowed']) {
            return 'Золота меньше минимума для покупки у торговца — продай что-нибудь и возвращайся.';
        }

        return self::plain($this->shop->buy($characterId, $resourceId, $qty)['message']);
    }

    /**
     * Опт из веба: подтверждение несёт отпечаток плана из превью (`token`), сделка — {@see ResourceShopScreenService::bulkSell()}.
     * Повтор той же формы гасит `intent_id`; новый `intent_id` со старым отпечатком ядро не исполняет — запас уже
     * другой (hotfix-bulk-confirm-once). Сделка пишется в `action_log` как у бота (`BULK_SELL`).
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException доля или редкость не из списка, плохое намерение
     */
    public function bulkSell(int $accountId, int $characterId, ?int $rarity, int $percent, string $token, string $intentId): ?string
    {
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_BULK_SELL)) {
            return null;
        }

        $out = $this->shop->bulkSell($characterId, $rarity, $percent, $token);
        if ($out['code'] === ResourceShopScreenService::DISABLED) {
            return 'Оптовая продажа временно недоступна.';
        }
        if ($out['code'] === ResourceShopScreenService::INVALID) {
            throw new InvalidArgumentException('bad bulk share');
        }
        if ($out['code'] !== ResourceShopScreenService::OK) {
            return self::plain($out['message']);
        }
        $scope = $rarity === null ? 'всех ресурсов' : "редкости {$rarity}";
        $this->logDone($characterId, 'BULK_SELL', ResourceTradeService::describeBulkTrade("Продажа опт {$percent}% {$scope}", $out['lines'], $out['gold']));

        return "🧺 Оптовая продажа выполнена: {$percent}% {$scope} — {$out['types']} вид(ов), " . number_format($out['qty'], 0, '.', ' ')
            . ' ед. Выручка: +' . number_format($out['gold'], 0, '.', ' ') . ' 💰.';
    }

    /**
     * Модель экрана «📦 Склад базы»: строки склада в режиме сортировки, итог, «на базе» и что лежит в рюкзаке
     * (то, что можно положить). Флаг «на базе» считается и при пустом складе — от него зависит сдача.
     *
     * @param StorageNav $nav
     *
     * @return array{mode:string, rows:list<array{resource_id:int, name:string, quantity:int}>, total_units:int, on_base:bool, carried:list<array{resource_id:int, name:string, quantity:int}>}
     */
    public function storageModel(int $characterId, array $nav): array
    {
        $m    = $this->storage->storageModel($characterId, $nav['mode'] ?? InventorySortService::MODE_RECENT);
        $rows = [];
        foreach ($m['rows'] as $r) {
            $id   = is_numeric($r['resource_id'] ?? null) ? (int) $r['resource_id'] : 0;
            $name = is_string($r['name'] ?? null) ? $r['name'] : '';
            $qty  = is_numeric($r['quantity'] ?? null) ? (int) $r['quantity'] : 0;
            if ($id > 0 && $name !== '' && $qty > 0) {
                $rows[] = ['resource_id' => $id, 'name' => $name, 'quantity' => $qty];
            }
        }

        return [
            'mode'        => $m['mode'],
            'rows'        => $rows,
            'total_units' => $m['total_units'],
            'on_base'     => $this->storage->isOnBase($characterId),
            'carried'     => $this->storage->carriedResources($characterId),
        ];
    }

    /**
     * Забрать со склада всё (`$resourceId` null) или один вид целиком — ядро {@see BaseStorageService} с гейтом
     * «на базе» и условным списанием, один раз на `intent_id`.
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохое намерение
     */
    public function storageTake(int $accountId, int $characterId, ?int $resourceId, string $intentId): ?string
    {
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_STORAGE_TAKE)) {
            return null;
        }

        if ($resourceId === null) {
            $out = $this->storage->withdrawAll($characterId);

            return match ($out['code']) {
                BaseStorageService::OK       => "🎒 Забрано со склада: {$out['units']} шт. Всё перенесено в рюкзак.",
                BaseStorageService::OFF_BASE => self::STORAGE_OFF_BASE,
                BaseStorageService::EMPTY    => '📦 Склад уже пуст — забирать нечего.',
                default                      => 'Не удалось забрать ресурсы со склада — попробуй ещё раз.',
            };
        }
        $out = $this->storage->withdrawOne($characterId, $resourceId);

        return match ($out['code']) {
            BaseStorageService::OK       => "🎒 Забрано со склада: {$out['name']} × {$out['withdrawn']} шт. Ресурс теперь в рюкзаке.",
            BaseStorageService::OFF_BASE => self::STORAGE_OFF_BASE,
            BaseStorageService::FAILED   => 'Не удалось забрать ресурс со склада — попробуй ещё раз.',
            default                      => 'Такого ресурса на складе уже нет.',
        };
    }

    /**
     * Положить на склад всё добытое (`$resourceId` null) или один вид целиком — ядро {@see BaseStorageService}, один
     * раз на `intent_id`. Клетка прихода — клетка персонажа (как при сдаче из бота).
     *
     * @return string|null ответ для игрока; null — повтор того же намерения
     *
     * @throws InvalidArgumentException плохое намерение
     */
    public function storagePut(int $accountId, int $characterId, ?int $resourceId, string $intentId): ?string
    {
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_STORAGE_PUT)) {
            return null;
        }
        $cell = $this->characterCell($characterId);

        if ($resourceId === null) {
            $out = $this->storage->depositAll($characterId, $cell);

            return match ($out['code']) {
                BaseStorageService::OK       => "📥 На склад: {$out['kinds']} вид(ов), {$out['units']} шт." . ($out['skipped'] > 0 ? " Пропущено видов: {$out['skipped']} — запас изменился, проверь рюкзак." : ''),
                BaseStorageService::OFF_BASE => self::STORAGE_OFF_BASE,
                BaseStorageService::EMPTY    => '🎒 В рюкзаке нет добытого — класть нечего.',
                default                      => 'Не удалось сложить на склад — запас изменился, попробуй ещё раз.',
            };
        }
        $out = $this->storage->depositOne($characterId, $resourceId, $cell);

        return match ($out['code']) {
            BaseStorageService::OK       => "📥 На склад: {$out['name']} × {$out['quantity']} шт.",
            BaseStorageService::OFF_BASE => self::STORAGE_OFF_BASE,
            BaseStorageService::SHORT    => 'Не удалось сложить на склад — запас изменился, попробуй ещё раз.',
            default                      => 'Этого ресурса в рюкзаке уже нет.',
        };
    }

    /** Клетка персонажа (null — нет персонажа или клетки) — клетка прихода при сдаче на склад. */
    private function characterCell(int $characterId): ?int
    {
        $res = \Config\Database::connect()->table('characters')->select('cell_number')->where('id', $characterId)->get();
        $row = $res === false ? null : $res->getRowArray();

        return is_array($row) && is_numeric($row['cell_number'] ?? null) ? (int) $row['cell_number'] : null;
    }

    /** Уровень персонажа для выдачи заданий дня (1 — персонажа нет). */
    private function characterLevel(int $characterId): int
    {
        $res = \Config\Database::connect()->table('characters')->select('level')->where('id', $characterId)->get();
        $row = $res === false ? null : $res->getRowArray();

        return is_array($row) && is_numeric($row['level'] ?? null) ? (int) $row['level'] : 1;
    }

    /**
     * Кнопка нативного экрана без нативного аналога → тот же callback через мост.
     *
     * @return array{state: State, alert: ?string, unread: int}
     *
     * @throws InvalidArgumentException кнопки нет ни на одном нативном экране или намерение отвергнуто мостом
     */
    public function bridge(int $accountId, int $characterId, string $callback, string $intentId): array
    {
        self::assertIntent($intentId);
        $baseSteps = isset(self::BRIDGE_ROUTES[$callback]) ? null : self::baseRoute($callback);
        if ($baseSteps !== null) {
            return $this->baseBridge($this->act ?? new WebActService(), $accountId, $characterId, $callback, $intentId, $baseSteps);
        }
        $craft = self::craftRoute($callback);
        $tasks = $craft === null ? self::tasksRoute($callback) : null;
        $route = $craft ?? $tasks ?? $this->routeTo($characterId, $callback);
        $onMap = $route === null;
        if ($onMap && ! $this->isMapCallback($characterId, $callback)) {
            throw new InvalidArgumentException('callback is not on a native screen');
        }
        $act   = $this->act ?? new WebActService();

        $result = $act->current($characterId);
        if (self::messageWith($result['state'], $callback) === null) {
            // Путь игрока в Telegram: карточка «Я» (для кнопок карты — экран «🌍 Мир», для нехватки
            // крафта — хаб «🔨 Крафт», для «Дел» — `/tasks`), затем кнопки маршрута.
            $result = $act->act($accountId, $characterId, ($onMap || $craft !== null || $tasks !== null) ? [
                'intent_id' => self::intentKey($intentId, ':card'),
                'kind'      => WebActService::KIND_COMMAND,
                'data'      => $craft !== null ? CraftCatalog::BOT_ENTRY : ($tasks !== null ? self::TASKS_ENTRY : self::MAP_ENTRY),
            ] : [
                'intent_id' => self::intentKey($intentId, ':card'),
                'kind'      => WebActService::KIND_TEXT,
                'data'      => BotMenuService::menuLabel('me'),
            ]);
            foreach ($route ?? [] as $i => $step) {
                $messageId = self::messageWith($result['state'], $step);
                if ($messageId === null) {
                    // Повтор того же намерения (экран уже ушёл дальше) — просто текущий экран.
                    return $result;
                }
                $result = $act->act($accountId, $characterId, [
                    'intent_id'  => self::intentKey($intentId, ':s' . $i),
                    'kind'       => WebActService::KIND_CALLBACK,
                    'data'       => $step,
                    'message_id' => (string) $messageId,
                ]);
            }
        }

        $messageId = self::messageWith($result['state'], $callback);
        if ($messageId === null) {
            return $result;
        }

        return $act->act($accountId, $characterId, [
            'intent_id'  => self::intentKey($intentId, ':cb'),
            'kind'       => WebActService::KIND_CALLBACK,
            'data'       => $callback,
            'message_id' => (string) $messageId,
        ]);
    }

    /**
     * Клик по клетке карты — подсказка: что на клетке, биом (если открыта), координаты.
     *
     * @throws InvalidArgumentException клетки нет в окне карты персонажа
     */
    public function cellHint(int $characterId, int $x, int $y): string
    {
        $map = $this->liveMap->forCharacter($characterId);
        foreach ($map['cells'] as $row) {
            foreach ($row as $cell) {
                if ($cell['x'] !== $x || $cell['y'] !== $y) {
                    continue;
                }
                $what  = match ($cell['code']) {
                    LiveMapService::CODE_OUT          => 'За пределами мира',
                    LiveMapService::CODE_PLAYER       => 'Ты здесь',
                    LiveMapService::CODE_OWN_BASE     => 'Твоя база',
                    LiveMapService::CODE_FOREIGN_BASE => 'Чужая база',
                    LiveMapService::CODE_FOG          => 'Не изучено',
                    LiveMapService::CODE_NPC          => 'NPC рядом',
                    default                           => null,
                };
                $parts = array_values(array_filter(
                    [$what, LiveMapService::biomeName($cell['biome'])],
                    static fn (?string $p): bool => $p !== null
                ));
                $label = $parts === [] ? $cell['marker'] : $cell['marker'] . ' ' . implode(' · ', $parts);

                return $label . " — X={$x}, Y={$y}. Шаг — клик по соседней клетке или роза под картой; Поход — клик по клетке на одной из 8 линий от тебя.";
            }
        }

        throw new InvalidArgumentException('cell is not in the map window');
    }

    /**
     * Ключ дедупа ступени: `intent_id` + суффикс, не длиннее VARCHAR(64). Если с суффиксом не
     * влезает, `intent_id` заменяется своим md5 (32 символа): ключ по-прежнему свой у каждого
     * намерения и тот же у повтора.
     */
    public static function intentKey(string $intentId, string $suffix): string
    {
        $key = $intentId . $suffix;

        return strlen($key) <= self::INTENT_KEY_MAX ? $key : md5($intentId) . $suffix;
    }

    /**
     * Путь бота к экрану нехватки рецепта каталога (`genericCraft_<Key>_1`): раздел → категория →
     * карточка; null — это не кнопка нехватки веб-крафта.
     *
     * @return list<string>|null
     */
    private static function craftRoute(string $callback): ?array
    {
        if (preg_match('/^genericCraft_([A-Za-z0-9]+)_1$/', $callback, $m) !== 1) {
            return null;
        }
        $recipe = (new CraftRecipes())->get($m[1]);
        $info   = is_array($recipe) && is_string($recipe['info_callback'] ?? null) ? $recipe['info_callback'] : '';

        return (new CraftCatalog())->botRoute($m[1], $info);
    }

    /**
     * Путь бота от `/tasks` к кнопке «Дел» без нативного экрана: «⛔️ Прервать» стоит на самом экране
     * «📋 Дела», «🌐 Квестомания» — в «📜 Квестах»; null — это не такая кнопка.
     *
     * @return list<string>|null
     */
    private static function tasksRoute(string $callback): ?array
    {
        return match (true) {
            preg_match('/^finishAllTasks_\d{1,12}$/', $callback) === 1 => [],
            $callback === 'questInfo'                                 => ['questAndTask'],
            default                                                   => null,
        };
    }

    /**
     * Рецепты категории, которые бот сейчас показывает: сезон — только активный, рыба и дроны — по
     * тем же флагам, что их экраны.
     *
     * @param array{recipes:list<string>, seasonal?:bool} $cat
     *
     * @return list<string>
     */
    private function visibleRecipes(array $cat): array
    {
        $keys = $cat['recipes'];
        if (($cat['seasonal'] ?? false) === true) {
            $active = (new SeasonalCraftService())->getRecipeKeysForActiveSeason();
            $keys   = array_values(array_filter($keys, static fn (string $k): bool => in_array($k, $active, true)));
        }

        return array_values(array_filter($keys, fn (string $k): bool => $this->orders->recipeEnabled($k)));
    }

    /** Рецепт стоит хотя бы в одной категории каталога, где экран его сейчас показывает. */
    private function recipeVisible(string $recipeKey): bool
    {
        foreach ((new CraftCatalog())->benches as $bench) {
            foreach ($bench['categories'] as $cat) {
                if (in_array($recipeKey, $cat['recipes'], true) && in_array($recipeKey, $this->visibleRecipes($cat), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Отказ веб-старта крафта — в `action_log` и firehose, как `BaseAction::logRejected` бота
     * (`CRAFT_<Key>`, причина, extra). Чата у веба нет — `chat_id` 0. Никогда не валит запрос.
     *
     * @param array<string, mixed> $extra
     */
    private function logCraftRejected(int $characterId, string $recipeKey, string $reason, array $extra = []): void
    {
        $this->logRejected($characterId, "CRAFT_{$recipeKey}", $reason, $extra);
    }

    /**
     * Отказ веб-действия — в `action_log` и firehose, как `BaseAction::logRejected` бота. Чата у веба нет —
     * `chat_id` 0. Никогда не валит запрос.
     *
     * @param array<string, mixed> $extra
     */
    private function logRejected(int $characterId, string $actionName, string $reason, array $extra = []): void
    {
        PlayerActionLogger::current()->markRejected($actionName . ': ' . $reason);

        try {
            $description = $extra === [] ? $reason : $reason . ' | ' . json_encode($extra, JSON_UNESCAPED_UNICODE);
            (new ActionLogModel())->save([
                'character_id'  => $characterId,
                'chat_id'       => 0,
                'action_name'   => $actionName,
                'action_status' => 'REJECTED',
                'description'   => mb_substr($description, 0, 500),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[WebNativeScreenService] reject log failed: ' . $e->getMessage());
        }
    }

    /**
     * Исполненное веб-действие — в `action_log` тем же кодом, что у бота (`BaseAction::logActivity`). Чата у веба
     * нет — `chat_id` 0. Никогда не валит запрос.
     */
    private function logDone(int $characterId, string $actionName, string $description): void
    {
        try {
            (new ActionLogModel())->save([
                'character_id'  => $characterId,
                'chat_id'       => 0,
                'action_name'   => $actionName,
                'action_status' => 'Completed',
                'description'   => mb_substr($description, 0, 500),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[WebNativeScreenService] action log failed: ' . $e->getMessage());
        }
    }

    /** Намерение ещё не исполнялось — занять его ключ; false — повтор. */
    private function claim(int $accountId, string $intentId, string $suffix): bool
    {
        return (new ConditionalWriteService())->insertUnique('web_play_intents', [
            'account_id' => $accountId,
            'intent_id'  => self::intentKey($intentId, $suffix),
            'created_at' => date('Y-m-d H:i:s'),
        ]) === WriteOutcome::Applied;
    }

    /** Markdown бота → простой текст экрана. */
    private static function plain(string $text): string
    {
        return str_replace(['*', '_'], '', $text);
    }

    /** «🛠️ Профессиональный крафт» → «Профессиональный крафт» (подпись замка несёт свой 🔒). */
    private static function shortLabel(string $label): string
    {
        $pos = strpos($label, ' ');

        return $pos === false ? $label : substr($label, $pos + 1);
    }

    /** Текст кнопки нижнего меню, который открывает нативный экран; null — такого нет. */
    public static function viewForDockLabel(string $label): ?string
    {
        return self::DOCK_VIEWS[$label] ?? null;
    }

    /** @throws InvalidArgumentException пустой или слишком длинный `intent_id` */
    private static function assertIntent(string $intentId): void
    {
        if ($intentId === '' || strlen($intentId) > self::INTENT_MAX) {
            throw new InvalidArgumentException('bad intent_id');
        }
    }

    /** Кнопка стоит на экране «Мир» персонажа (роза, клетка, Поход, витрины острова). */
    private function isMapCallback(int $characterId, string $callback): bool
    {
        return in_array($callback, array_column($this->liveMap->actions(['id' => $characterId]), 'callback'), true);
    }

    /**
     * Путь от карточки «Я» до сообщения с кнопкой; null — кнопки нет на экранах от «Я»
     * (тогда она может стоять только на карте).
     *
     * @return list<string>|null
     */
    private function routeTo(int $characterId, string $callback): ?array
    {
        if (isset(self::BRIDGE_ROUTES[$callback])) {
            return self::BRIDGE_ROUTES[$callback];
        }
        foreach (self::BRIDGE_PATTERNS as $pattern => $route) {
            if (preg_match($pattern, $callback, $m) === 1) {
                return array_map(static fn (string $step): string => str_replace('$1', $m[1], $step), $route);
            }
        }
        $sheet = $this->sheets->forCharacter($characterId);
        if ($sheet !== null && in_array($callback, self::callbacks($sheet), true)) {
            return [];
        }

        return null;
    }

    /**
     * Callback-кнопки экрана «Я», которые уходят в мост (нативные — не в их числе).
     *
     * @param Sheet $sheet
     *
     * @return list<string>
     */
    private static function callbacks(array $sheet): array
    {
        $out = [];
        foreach (array_merge($sheet['personal_actions'], $sheet['tail_actions']) as $action) {
            if (isset($action['callback']) && self::viewForAction($action['id']) === null) {
                $out[] = $action['callback'];
            }
        }

        return $out;
    }

    /** @param State $state */
    private static function messageWith(array $state, string $callback): ?int
    {
        foreach (array_reverse($state['screen']) as $msg) {
            if (WebScreenStore::hasCallback($msg, $callback)) {
                return $msg['message_id'];
            }
        }

        return null;
    }
}
