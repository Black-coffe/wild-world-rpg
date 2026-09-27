<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Services\Player\CharacterSheetService;
use App\Services\Player\InventoryViewService;
use App\Services\Telegram\BotMenuService;
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
 * @phpstan-import-type State from WebScreenStore
 * @phpstan-import-type Sheet from CharacterSheetService
 */
class WebNativeScreenService
{
    public const VIEW_ME        = 'me';
    public const VIEW_INVENTORY = 'inventory';

    /** Экраны, у которых уже есть нативная вьюха. */
    public const VIEWS = [self::VIEW_ME, self::VIEW_INVENTORY];

    /** Действие экрана «Я» → нативный экран, который его заменяет. */
    private const NATIVE_ACTIONS = ['inventory' => self::VIEW_INVENTORY];

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
    ];

    private CharacterSheetService $sheets;

    private InventoryViewService $inventory;

    public function __construct(
        private ?WebActService $act = null,
        ?CharacterSheetService $sheets = null,
        ?InventoryViewService $inventory = null
    ) {
        $this->sheets    = $sheets ?? new CharacterSheetService();
        $this->inventory = $inventory ?? new InventoryViewService();
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
     * Кнопка нативного экрана без нативного аналога → тот же callback через мост.
     *
     * @return array{state: State, alert: ?string, unread: int}
     *
     * @throws InvalidArgumentException кнопки нет ни на одном нативном экране или намерение отвергнуто мостом
     */
    public function bridge(int $accountId, int $characterId, string $callback, string $intentId): array
    {
        if ($intentId === '' || strlen($intentId) > 60) {
            throw new InvalidArgumentException('bad intent_id');
        }
        $route = $this->routeTo($characterId, $callback);
        $act   = $this->act ?? new WebActService();

        $result = $act->current($characterId);
        if (self::messageWith($result['state'], $callback) === null) {
            // Путь игрока в Telegram: карточка «Я», затем кнопки маршрута.
            $result = $act->act($accountId, $characterId, [
                'intent_id' => $intentId . ':card',
                'kind'      => WebActService::KIND_TEXT,
                'data'      => BotMenuService::menuLabel('me'),
            ]);
            foreach ($route as $i => $step) {
                $messageId = self::messageWith($result['state'], $step);
                if ($messageId === null) {
                    // Повтор того же намерения (экран уже ушёл дальше) — просто текущий экран.
                    return $result;
                }
                $result = $act->act($accountId, $characterId, [
                    'intent_id'  => $intentId . ':s' . $i,
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
            'intent_id'  => $intentId . ':cb',
            'kind'       => WebActService::KIND_CALLBACK,
            'data'       => $callback,
            'message_id' => (string) $messageId,
        ]);
    }

    /** Текст кнопки нижнего меню, который открывает нативный экран; null — такого нет. */
    public static function viewForDockLabel(string $label): ?string
    {
        return in_array($label, ['🧑 Я', 'Перс'], true) ? self::VIEW_ME : null;
    }

    /**
     * Путь от карточки «Я» до сообщения с кнопкой; кнопка обязана стоять на нативном экране.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException кнопки нет ни на одном нативном экране
     */
    private function routeTo(int $characterId, string $callback): array
    {
        if (isset(self::BRIDGE_ROUTES[$callback])) {
            return self::BRIDGE_ROUTES[$callback];
        }
        $sheet = $this->sheets->forCharacter($characterId);
        if ($sheet !== null && in_array($callback, self::callbacks($sheet), true)) {
            return [];
        }

        throw new InvalidArgumentException('callback is not on a native screen');
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
