<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\Player\CharacterSheetService;
use App\Services\Player\EquipmentLoadoutService;
use App\Services\Player\InventoryViewService;
use App\Services\Telegram\BotMenuService;
use App\Services\World\LiveMapService;
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
 * Ключ дедупа — `intent_id` + суффикс ступени; {@see intentKey()} держит его в VARCHAR(64)
 * `web_play_intents` при любом допустимом `intent_id`.
 *
 * @phpstan-import-type State from WebScreenStore
 * @phpstan-import-type Sheet from CharacterSheetService
 */
class WebNativeScreenService
{
    public const VIEW_ME        = 'me';
    public const VIEW_INVENTORY = 'inventory';
    public const VIEW_GEAR      = 'gear';
    public const VIEW_MAP       = 'map';

    /** Экраны, у которых уже есть нативная вьюха. */
    public const VIEWS = [self::VIEW_ME, self::VIEW_INVENTORY, self::VIEW_GEAR, self::VIEW_MAP];

    /** Подписи нижнего меню → нативный экран. */
    private const DOCK_VIEWS = ['🧑 Я' => self::VIEW_ME, 'Перс' => self::VIEW_ME, '🌍 Мир' => self::VIEW_MAP, 'Карта' => self::VIEW_MAP];

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

    public function __construct(
        private ?WebActService $act = null,
        ?CharacterSheetService $sheets = null,
        ?InventoryViewService $inventory = null,
        ?EquipmentLoadoutService $loadout = null,
        ?LiveMapService $liveMap = null
    ) {
        $this->sheets    = $sheets ?? new CharacterSheetService();
        $this->inventory = $inventory ?? new InventoryViewService();
        $this->loadout   = $loadout ?? new EquipmentLoadoutService();
        $this->liveMap   = $liveMap ?? new LiveMapService();
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
     * @param array<string, mixed> $state текущее состояние моста (из него берётся док)
     *
     * @throws InvalidArgumentException неизвестный экран или нет персонажа
     */
    public function render(int $characterId, string $view, array $state, ?string $alert = null): string
    {
        $dock = is_array($state['dock'] ?? null) ? $state['dock'] : [];

        if ($view === self::VIEW_MAP) {
            return view('site/_play/native_map', [
                'map'   => $this->liveMap->forCharacter($characterId),
                'dock'  => $dock,
                'alert' => $alert,
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
     * Кнопка нативного экрана без нативного аналога → тот же callback через мост.
     *
     * @return array{state: State, alert: ?string, unread: int}
     *
     * @throws InvalidArgumentException кнопки нет ни на одном нативном экране или намерение отвергнуто мостом
     */
    public function bridge(int $accountId, int $characterId, string $callback, string $intentId): array
    {
        self::assertIntent($intentId);
        $route = $this->routeTo($characterId, $callback);
        $onMap = $route === null;
        if ($onMap && ! $this->isMapCallback($characterId, $callback)) {
            throw new InvalidArgumentException('callback is not on a native screen');
        }
        $act   = $this->act ?? new WebActService();

        $result = $act->current($characterId);
        if (self::messageWith($result['state'], $callback) === null) {
            // Путь игрока в Telegram: карточка «Я» (для кнопок карты — экран «🌍 Мир»),
            // затем кнопки маршрута.
            $result = $act->act($accountId, $characterId, $onMap ? [
                'intent_id' => self::intentKey($intentId, ':card'),
                'kind'      => WebActService::KIND_COMMAND,
                'data'      => self::MAP_ENTRY,
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

                return $label . " — X={$x}, Y={$y}. Ходи розой под картой: шаг и Поход кликом по клетке — скоро.";
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
