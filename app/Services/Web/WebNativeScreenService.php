<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Models\ActionLogModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Craft\CraftCardHelper;
use App\Services\Craft\CraftOrderService;
use App\Services\Craft\CraftQueueService;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Logging\PlayerActionLogger;
use App\Services\Player\CharacterSheetService;
use App\Services\Player\DroneService;
use App\Services\Player\EquipmentLoadoutService;
use App\Services\Player\InventoryViewService;
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
 * Ключ дедупа — `intent_id` + суффикс ступени; {@see intentKey()} держит его в VARCHAR(64)
 * `web_play_intents` при любом допустимом `intent_id`.
 *
 * @phpstan-import-type State from WebScreenStore
 * @phpstan-import-type Msg from WebScreenStore
 * @phpstan-import-type Capture from WebScreenStore
 * @phpstan-import-type Sheet from CharacterSheetService
 * @phpstan-import-type Preview from MarchService
 * @phpstan-type CraftNav array{bench?:string, cat?:string, recipe?:string}
 */
class WebNativeScreenService
{
    public const VIEW_ME        = 'me';
    public const VIEW_INVENTORY = 'inventory';
    public const VIEW_GEAR      = 'gear';
    public const VIEW_MAP       = 'map';
    public const VIEW_CRAFT     = 'craft';

    /** Экраны, у которых уже есть нативная вьюха. */
    public const VIEWS = [self::VIEW_ME, self::VIEW_INVENTORY, self::VIEW_GEAR, self::VIEW_MAP, self::VIEW_CRAFT];

    /** Подписи нижнего меню → нативный экран. */
    private const DOCK_VIEWS = [
        '🧑 Я' => self::VIEW_ME, 'Перс' => self::VIEW_ME, '🌍 Мир' => self::VIEW_MAP, 'Карта' => self::VIEW_MAP,
        '🔨 Крафт' => self::VIEW_CRAFT, 'Крафт' => self::VIEW_CRAFT,
    ];

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

    /** Рыбные блюда костра показываются, только пока включён тот же флаг, что у экрана бота. */
    private const FISH_FLAG = 'cooking.fish_dishes.enabled';

    private const FISH_RECIPES = ['FishSoup', 'GrilledFish', 'FishPreserve'];

    /**
     * Кнопки моста на нативных экранах (кроме «Я», чьи кнопки берутся из модели) и путь от
     * карточки «Я» до сообщения бота, на котором такая кнопка стоит.
     *
     * @var array<string, list<string>>
     */
    private const BRIDGE_ROUTES = [
        'baseStorageList'  => ['inventory'],
        'whereItWent'      => ['inventory'],
        'resourceOverview' => ['inventory'],
        // Снаряжение: путь к стройке Арсенала — с lock-экрана раздела «Оружие».
        'genericBuildInfo_Arsenal' => ['equipMenu', 'gearWeapons'],
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

    public function __construct(
        private ?WebActService $act = null,
        ?CharacterSheetService $sheets = null,
        ?InventoryViewService $inventory = null,
        ?EquipmentLoadoutService $loadout = null,
        ?LiveMapService $liveMap = null,
        ?MoveService $move = null,
        ?MarchService $march = null,
        ?CraftOrderService $orders = null,
        ?CraftQueueService $queue = null
    ) {
        $this->sheets    = $sheets ?? new CharacterSheetService();
        $this->inventory = $inventory ?? new InventoryViewService();
        $this->loadout   = $loadout ?? new EquipmentLoadoutService();
        $this->liveMap   = $liveMap ?? new LiveMapService();
        $this->move      = $move ?? new MoveService();
        $this->march     = $march ?? new MarchService();
        $this->orders    = $orders ?? new CraftOrderService();
        $this->queue     = $queue ?? new CraftQueueService();
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
     *
     * @throws InvalidArgumentException неизвестный экран или нет персонажа
     */
    public function render(int $characterId, string $view, array $state, ?string $alert = null, array $events = [], ?array $preview = null, array $craft = []): string
    {
        $dock = is_array($state['dock'] ?? null) ? $state['dock'] : [];

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
        if ((new CraftCatalog())->locate($recipeKey) === null || $qty < 1) {
            throw new InvalidArgumentException('bad craft start');
        }
        self::assertIntent($intentId);
        if (! $this->claim($accountId, $intentId, ':' . self::OP_CRAFT_START)) {
            return null;
        }

        if (! $this->recipeVisible($recipeKey)) {
            $this->logCraftRejected($characterId, $recipeKey, 'recipe_hidden', ['qty' => $qty]);

            return 'Этот рецепт сейчас недоступен.';
        }

        $pv = $this->orders->preview($characterId, $recipeKey, 1);
        if ($pv['ok'] && $qty > $pv['max_qty']) {
            $this->logCraftRejected($characterId, $recipeKey, 'qty_over_max', ['qty' => $qty, 'max_qty' => $pv['max_qty']]);

            return "Столько не выйдет: сейчас можно поставить не больше {$pv['max_qty']} шт.";
        }
        $out = $this->orders->start($characterId, $recipeKey, $qty);
        if (! $out['ok']) {
            $this->logCraftRejected($characterId, $recipeKey, $out['log']['reason'] ?? $out['code'], $out['log']['extra'] ?? []);

            return self::plain($out['message']);
        }
        $what = trim($pv['recipe']['icon'] . ' ' . $pv['recipe']['name']) . " ×{$qty}";
        if ($out['code'] !== CraftOrderService::QUEUED) {
            return "🛠 Крафт начат: {$what}. Готово через {$out['minutes_total']} мин.";
        }

        $queued = $this->queue->forCharacter($characterId)['queued'];
        $pos    = count($queued) + 1;
        foreach ($queued as $row) {
            if ($row['charTaskId'] === $out['char_task_id']) {
                $pos = $row['position'];
                break;
            }
        }

        return "📋 В очереди: {$what} — №{$pos}. Начнётся, когда закончится текущий.";
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
     * Кнопка нативного экрана без нативного аналога → тот же callback через мост.
     *
     * @return array{state: State, alert: ?string, unread: int}
     *
     * @throws InvalidArgumentException кнопки нет ни на одном нативном экране или намерение отвергнуто мостом
     */
    public function bridge(int $accountId, int $characterId, string $callback, string $intentId): array
    {
        self::assertIntent($intentId);
        $craft = self::craftRoute($callback);
        $route = $craft ?? $this->routeTo($characterId, $callback);
        $onMap = $route === null;
        if ($onMap && ! $this->isMapCallback($characterId, $callback)) {
            throw new InvalidArgumentException('callback is not on a native screen');
        }
        $act   = $this->act ?? new WebActService();

        $result = $act->current($characterId);
        if (self::messageWith($result['state'], $callback) === null) {
            // Путь игрока в Telegram: карточка «Я» (для кнопок карты — экран «🌍 Мир», для нехватки
            // крафта — хаб «🔨 Крафт»), затем кнопки маршрута.
            $result = $act->act($accountId, $characterId, ($onMap || $craft !== null) ? [
                'intent_id' => self::intentKey($intentId, ':card'),
                'kind'      => WebActService::KIND_COMMAND,
                'data'      => $craft !== null ? CraftCatalog::BOT_ENTRY : self::MAP_ENTRY,
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

        return array_values(array_filter($keys, fn (string $k): bool => $this->recipeShown($k)));
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
        $actionName = "CRAFT_{$recipeKey}";
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
            log_message('error', '[WebNativeScreenService] craft reject log failed: ' . $e->getMessage());
        }
    }

    private function recipeShown(string $key): bool
    {
        if (in_array($key, self::FISH_RECIPES, true)) {
            $raw = (new GameSettingsService())->get(self::FISH_FLAG, false);

            return is_bool($raw) ? $raw : (is_numeric($raw) && (int) $raw === 1);
        }

        return match ($key) {
            'DroneScout'  => (new DroneService())->isEnabled(),
            'DroneCargo'  => (new DroneService())->cargoIsEnabled(),
            'DroneRepair' => (new DroneService())->repairIsEnabled(),
            'DroneCombat' => (new DroneService())->combatIsEnabled(),
            default       => true,
        };
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
