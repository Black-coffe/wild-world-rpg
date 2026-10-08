<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\GameSettings\GameSettingsService;
use App\Services\Web\AccountSession;
use App\Services\Web\WebActService;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebInboxService;
use App\Services\Web\WebNativeScreenService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\WebPlay;
use InvalidArgumentException;

/**
 * web-bridge-p1-07 (ADR-189 §1, §6) — игра на сайте `/play` через маршруты бота.
 *
 * Каждый маршрут: флаг `web.play_enabled` на сервере (инв. 8) → вход (`AccountSession`) →
 * персонаж ТОЛЬКО из сессии. Флаг первым (p1-08): при выключенном флаге заглушку видит и гость.
 * Гость на `GET /play` при включённом флаге — цель возврата `/play` в сессию и на вход. Из запроса берутся лишь `intent_id`, `kind`, `data`, `message_id`;
 * telegram/chat/character/account id не принимаются и в ответ не выводятся (инв. 6).
 * CSRF — глобальный фильтр; лимит частоты — `accountThrottle:play|inbox` (Routes).
 *
 * `POST /play/act` c `Accept: application/json` → `{html, hud, unread, alert, csrf}`; без JS — PRG
 * 303 на `/play` (подсказка кнопки — flash). Отвергнутое намерение — 400, без диспетча.
 *
 * W2.N1 (ADR-190): `POST /play/view` — нативный экран (`view`) из модели экрана, те же гейты и
 * лимит `accountThrottle:play`; `op=bridge` + `data` — кнопка нативного экрана без своего экрана
 * уходит в мост; `view=gear` + `op=equip|unequip` + `kind` + `item` + `intent_id` — смена
 * снаряжения тем же сервисом, что у бота (дедуп по `intent_id`). Без JS — PRG на `/play?view=…`
 * (ответ смены — flash). HUD (`hud`) едет в каждом JSON-ответе.
 *
 * W2.N2-01: `view=map` — нативная сетка «Мир»; `view=map` + `op=cell` + `x` + `y` — подсказка по
 * клетке окна (биом, координаты). Позиция и персонаж — только из сессии, `x`/`y` лишь выбирают
 * клетку внутри окна; клетка вне окна — 400. Кнопки карты — `op=bridge`.
 *
 * W2.N2-02: `view=map` + `op=step` + `dir` + `intent_id` — шаг тем же сервисом, что у бота (повтор
 * `intent_id` второго шага не делает). События шага — под картой (JSON `html`; без JS — flash и PRG
 * на `/play?view=map`), их кнопки — `/play/act` через мост.
 *
 * W2.N2-03: `view=map` + `op=march_preview` + `dir` + `n` — превью Похода под картой (без JS — flash и
 * PRG на `/play?view=map`); `op=march_start` (`dir`, `n`), `march_extend` (`n`), `march_resume`,
 * `march_stop` + `intent_id` — Поход тем же сервисом, что у бота (повтор `intent_id` ничего не делает).
 *
 * W2.N3-03: `view=craft` + `bench`/`cat`/`recipe` — экран «🔨 Крафт» (верстак → категория → карточка;
 * без JS — PRG на `/play?view=craft&bench=…&cat=…&recipe=…`); `op=craft_start` + `recipe` + `qty` +
 * `intent_id` — старт тем же ядром, что у бота (`qty` 1..`max_qty` карточки); `op=craft_cancel` + `task` +
 * `intent_id` — отмена ожидающего. Повтор `intent_id` ничего не делает.
 *
 * W2.N4-03: `view=base` + `b`/`section`/`key`/`id` — экран «🏠 База» (выбор базы, обзор, «что можно построить»,
 * карточка постройки, превью апгрейда; без JS — PRG на `/play?view=base&…`); `op=build_start` + `key` и
 * `op=upgrade` + `id` (+ `b`, `intent_id`) — то же ядро, что у бота. Повтор `intent_id` ничего не делает.
 *
 * w2-n5-deeds-03: `view=tasks` + `section`/`id` — экран «📋 Дела» (хаб, списки квестов, карточка квеста, события;
 * без JS — PRG на `/play?view=tasks&…`); `op=quest_start` и `op=quest_branch` + `id` + `intent_id` — старт квеста и
 * выбор ветки тем же ядром, что у бота (проверка и вставка под блокировкой строки персонажа). Повтор `intent_id`
 * ничего не делает.
 *
 * w2-n6-trade-storage-03: `view=shop` + `section`/`r`/`id`/`pct` — экран «🛒 Магазин» (хаб, продажа по редкости,
 * карточка, покупка, превью опта; без JS — PRG на `/play?view=shop&…`); `op=sell|buy` + `id` + `qty` и
 * `op=bulk_sell` + `pct` (+ `r`, `token` из превью) + `intent_id` — сделки тем же ядром, что у бота; `qty` больше
 * потолка карточки — отказ, ничего не списано. `view=storage` + `mode` — «📦 Склад базы»; `op=storage_take|storage_put`
 * (+ `id` вида, без него — всё) + `intent_id`. Повтор `intent_id` ничего не делает. Кнопка `op=bridge` с
 * `shop`/`baseStorageList` открывает эти экраны нативно, мимо моста.
 */
class Play extends BaseController
{
    /** Сколько последних входящих отдаёт панель колокольчика. */
    private const INBOX_PAGE = 50;

    private const FLASH_ALERT = 'play_alert';

    /** События шага под картой — переживают PRG без JS. */
    private const FLASH_EVENTS = 'play_map_events';

    /** Превью Похода (`dir`, `n`) — переживает PRG без JS. */
    private const FLASH_PREVIEW = 'play_map_preview';

    private const REJECTED_ALERT = 'Эта кнопка уже недоступна — экран обновлён.';

    public function index(): ResponseInterface|string
    {
        $gate = $this->gate();
        if ($gate instanceof ResponseInterface) {
            // Гость при включённом флаге: кабинет вернёт на /play после входа (один раз).
            (new AccountSession())->rememberReturnTarget(AccountSession::RETURN_PLAY);

            return $gate;
        }
        if (is_string($gate)) {
            return $gate;
        }
        [$accountId, $characterId] = $gate;

        try {
            $result = $this->service()->bootstrap($accountId, $characterId);
        } catch (\Throwable $e) {
            // p1-13 (#9): любой сбой первого входа — не 500, а /play с сохранённым (или пустым) экраном.
            log_message('error', '[Play.index] bootstrap failed: ' . $e::class . ': ' . $e->getMessage());
            $result = $this->storedOrEmpty($characterId);
        }
        $flash  = session()->getFlashdata(self::FLASH_ALERT);
        $alert  = is_string($flash) ? $flash : $result['alert'];
        $events = session()->getFlashdata(self::FLASH_EVENTS);
        $wanted = session()->getFlashdata(self::FLASH_PREVIEW);

        // PRG нативного экрана: `/play?view=me` рисует экран из модели поверх того же дока.
        $view   = $this->request->getGet('view');
        $native = null;
        if (is_string($view) && WebNativeScreenService::isView($view)) {
            try {
                $preview = null;
                if ($view === WebNativeScreenService::VIEW_MAP && is_array($wanted) && is_string($wanted['dir'] ?? null) && is_int($wanted['n'] ?? null)) {
                    $preview = $this->native()->marchPreview($characterId, $wanted['dir'], $wanted['n']);
                    $preview = $preview['ok'] ? $preview : null;
                }
                $native = $this->native()->render($characterId, $view, $result['state'], $alert, self::eventList($events), $preview, self::craftNav($this->request->getGet(...)), self::baseNav($this->request->getGet(...)), self::tasksNav($this->request->getGet(...)), self::shopNav($this->request->getGet(...)), self::storageNav($this->request->getGet(...)));
            } catch (\Throwable $e) {
                log_message('error', '[Play.index] native view failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }

        return $this->playPage($characterId, $result, $alert, $native);
    }

    public function act(): ResponseInterface|string
    {
        $gate = $this->gate();
        if ($gate instanceof ResponseInterface || is_string($gate)) {
            return $this->denied($gate);
        }
        [$accountId, $characterId] = $gate;

        $intent = [];
        foreach (['intent_id', 'kind', 'data', 'message_id'] as $field) {
            $value = $this->request->getPost($field);
            if (is_string($value)) {
                $intent[$field] = $value;
            }
        }

        try {
            $result = $this->service()->act($accountId, $characterId, $intent);
        } catch (InvalidArgumentException $e) {
            log_message('info', '[Play.act] rejected: ' . $e->getMessage());

            return $this->rejected($characterId);
        }

        return $this->bridgeResponse($characterId, $result);
    }

    /**
     * W2.N1 — нативный экран или кнопка нативного экрана через мост. Персонаж — только из сессии.
     */
    public function view(): ResponseInterface|string
    {
        $gate = $this->gate();
        if ($gate instanceof ResponseInterface || is_string($gate)) {
            return $this->denied($gate);
        }
        [$accountId, $characterId] = $gate;

        $view = $this->request->getPost('view');
        $op   = $this->request->getPost('op');

        $data       = $op === 'bridge' ? $this->request->getPost('data') : null;
        $nativeView = is_string($data) ? WebNativeScreenService::viewForCallback($data) : null;
        if ($nativeView !== null) {
            // Кнопка, у которой теперь есть нативный экран (магазин, склад), — экран, а не мост.
            $view = $nativeView;
            $op   = null;
        } elseif ($op === 'bridge') {
            $intentId = $this->request->getPost('intent_id');
            try {
                $result = $this->native()->bridge(
                    $accountId,
                    $characterId,
                    is_string($data) ? $data : '',
                    is_string($intentId) ? $intentId : ''
                );
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] bridge rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }

            return $this->bridgeResponse($characterId, $result);
        }

        $alert   = null;
        $events  = [];
        $preview = null;
        $craft   = self::craftNav($this->request->getPost(...));
        $base    = self::baseNav($this->request->getPost(...));
        $tasks   = self::tasksNav($this->request->getPost(...));
        $shop    = self::shopNav($this->request->getPost(...));
        $storage = self::storageNav($this->request->getPost(...));
        if ($view === WebNativeScreenService::VIEW_SHOP && is_string($op)
            && in_array($op, [WebNativeScreenService::OP_SELL, WebNativeScreenService::OP_BUY], true)) {
            $intentId = $this->request->getPost('intent_id');
            $intentId = is_string($intentId) ? $intentId : '';
            $qty      = self::quantity($this->request->getPost('qty'));
            try {
                $alert = $op === WebNativeScreenService::OP_SELL
                    ? $this->native()->shopSell($accountId, $characterId, $shop['id'] ?? 0, $qty, $intentId)
                    : $this->native()->shopBuy($accountId, $characterId, $shop['id'] ?? 0, $qty, $intentId);
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] shop ' . $op . ' rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
            // После сделки — та же карточка: видно, сколько осталось, «ещё» — в один клик.
            $shop = ['section' => $op === WebNativeScreenService::OP_SELL ? 'sell_card' : 'buy_card', 'id' => $shop['id'] ?? 0];
        } elseif ($view === WebNativeScreenService::VIEW_SHOP && $op === WebNativeScreenService::OP_BULK_SELL) {
            $intentId = $this->request->getPost('intent_id');
            $token    = $this->request->getPost('token');
            try {
                $alert = $this->native()->bulkSell(
                    $accountId,
                    $characterId,
                    $shop['r'] ?? null,
                    $shop['pct'] ?? 0,
                    is_string($token) && preg_match('/^[0-9a-f]{8}$/', $token) === 1 ? $token : '',
                    is_string($intentId) ? $intentId : ''
                );
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] bulk sell rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
            $shop = isset($shop['r']) ? ['section' => 'sell_rarity', 'r' => $shop['r']] : ['section' => 'sell'];
        } elseif ($view === WebNativeScreenService::VIEW_STORAGE && is_string($op)
            && in_array($op, [WebNativeScreenService::OP_STORAGE_TAKE, WebNativeScreenService::OP_STORAGE_PUT], true)) {
            $intentId = $this->request->getPost('intent_id');
            $intentId = is_string($intentId) ? $intentId : '';
            $resource = $this->request->getPost('id');
            $resource = is_string($resource) && preg_match('/^[1-9]\d{0,11}$/', $resource) === 1 ? (int) $resource : null;
            try {
                $alert = $op === WebNativeScreenService::OP_STORAGE_TAKE
                    ? $this->native()->storageTake($accountId, $characterId, $resource, $intentId)
                    : $this->native()->storagePut($accountId, $characterId, $resource, $intentId);
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] storage ' . $op . ' rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
        } elseif ($view === WebNativeScreenService::VIEW_TASKS && is_string($op)
            && in_array($op, [WebNativeScreenService::OP_QUEST_START, WebNativeScreenService::OP_QUEST_BRANCH], true)) {
            $intentId = $this->request->getPost('intent_id');
            $intentId = is_string($intentId) ? $intentId : '';
            try {
                $alert = $op === WebNativeScreenService::OP_QUEST_START
                    ? $this->native()->questStart($accountId, $characterId, $tasks['id'] ?? 0, $intentId)
                    : $this->native()->questBranch($accountId, $characterId, $tasks['id'] ?? 0, $intentId);
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] quest ' . $op . ' rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
            // Старт — карточка квеста (теперь «идёт»), ветка — список активных.
            $tasks = $op === WebNativeScreenService::OP_QUEST_START ? ['section' => 'quest'] + $tasks : ['section' => 'active'];
        } elseif ($view === WebNativeScreenService::VIEW_BASE && $op === WebNativeScreenService::OP_BUILD_START) {
            $intentId = $this->request->getPost('intent_id');
            try {
                $alert = $this->native()->buildStart($accountId, $characterId, $base['b'] ?? null, $base['key'] ?? '', is_string($intentId) ? $intentId : '');
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] build start rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
            // После старта — обзор базы: стройка видна в строке задач HUD.
            $base = array_intersect_key($base, ['b' => true]);
        } elseif ($view === WebNativeScreenService::VIEW_BASE && $op === WebNativeScreenService::OP_UPGRADE) {
            $intentId = $this->request->getPost('intent_id');
            try {
                $alert = $this->native()->upgrade($accountId, $characterId, $base['b'] ?? null, $base['id'] ?? 0, $base['from'] ?? null, is_string($intentId) ? $intentId : '');
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] upgrade rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
            $base = array_intersect_key($base, ['b' => true]);
        } elseif ($view === WebNativeScreenService::VIEW_CRAFT && $op === WebNativeScreenService::OP_CRAFT_START) {
            $recipe   = $this->request->getPost('recipe');
            $intentId = $this->request->getPost('intent_id');
            try {
                $outcome = $this->native()->craftStartOutcome(
                    $accountId,
                    $characterId,
                    is_string($recipe) ? $recipe : '',
                    self::count($this->request->getPost('qty')),
                    is_string($intentId) ? $intentId : '',
                    $this->request->getPost('confirmed') === '1'
                );
                $alert = $outcome['alert'];
                // craft-batch-price-confirm: ядро попросило подтвердить партию — экран рецепта с панелью итога.
                if ($outcome['confirm'] !== null) {
                    $craft['confirm'] = $outcome['confirm'];
                }
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] craft start rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
        } elseif ($view === WebNativeScreenService::VIEW_CRAFT && $op === WebNativeScreenService::OP_CRAFT_CANCEL) {
            $task     = $this->request->getPost('task');
            $intentId = $this->request->getPost('intent_id');
            try {
                $alert = $this->native()->craftCancel(
                    $accountId,
                    $characterId,
                    is_string($task) && ctype_digit($task) && strlen($task) <= 12 ? (int) $task : 0,
                    is_string($intentId) ? $intentId : ''
                );
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] craft cancel rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
        } elseif ($view === WebNativeScreenService::VIEW_GEAR && is_string($op)
            && in_array($op, [WebNativeScreenService::OP_EQUIP, WebNativeScreenService::OP_UNEQUIP], true)) {
            $kind     = $this->request->getPost('kind');
            $item     = $this->request->getPost('item');
            $intentId = $this->request->getPost('intent_id');
            try {
                $alert = $this->native()->gearChange(
                    $accountId,
                    $characterId,
                    $op,
                    is_string($kind) ? $kind : '',
                    is_string($item) && ctype_digit($item) ? (int) $item : 0,
                    is_string($intentId) ? $intentId : ''
                );
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] gear change rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
        } elseif ($view === WebNativeScreenService::VIEW_MAP && $op === WebNativeScreenService::OP_CELL) {
            $x = self::coordinate($this->request->getPost('x'));
            $y = self::coordinate($this->request->getPost('y'));
            try {
                if ($x === null || $y === null) {
                    throw new InvalidArgumentException('bad cell');
                }
                $alert = $this->native()->cellHint($characterId, $x, $y);
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] cell rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
        } elseif ($view === WebNativeScreenService::VIEW_MAP && $op === WebNativeScreenService::OP_STEP) {
            $dir      = $this->request->getPost('dir');
            $intentId = $this->request->getPost('intent_id');
            try {
                $step   = $this->native()->step(
                    $accountId,
                    $characterId,
                    is_string($dir) ? $dir : '',
                    is_string($intentId) ? $intentId : ''
                );
                $alert  = $step['alert'];
                $events = $step['events'];
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] step rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
        } elseif ($view === WebNativeScreenService::VIEW_MAP && $op === WebNativeScreenService::OP_MARCH_PREVIEW) {
            $dir = $this->request->getPost('dir');
            try {
                $preview = $this->native()->marchPreview($characterId, is_string($dir) ? $dir : '', self::count($this->request->getPost('n')));
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] march preview rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
            if (! $preview['ok']) {
                $alert   = $preview['message'];
                $preview = null;
            }
        } elseif ($view === WebNativeScreenService::VIEW_MAP && is_string($op) && in_array($op, WebNativeScreenService::MARCH_OPS, true)) {
            $dir      = $this->request->getPost('dir');
            $intentId = $this->request->getPost('intent_id');
            try {
                $march  = $this->native()->march(
                    $accountId,
                    $characterId,
                    $op,
                    is_string($dir) ? $dir : '',
                    self::count($this->request->getPost('n')),
                    is_string($intentId) ? $intentId : ''
                );
                $alert  = $march['alert'];
                $events = $march['events'];
            } catch (InvalidArgumentException $e) {
                log_message('info', '[Play.view] march rejected: ' . $e->getMessage());

                return $this->rejected($characterId);
            }
        } elseif (! is_string($view) || ! WebNativeScreenService::isView($view) || $op !== null) {
            log_message('info', '[Play.view] rejected: bad view/op');

            return $this->rejected($characterId);
        }

        if (! $this->wantsJson()) {
            if ($alert !== null) {
                session()->setFlashdata(self::FLASH_ALERT, $alert);
            }
            if ($events !== []) {
                session()->setFlashdata(self::FLASH_EVENTS, $events);
            }
            if ($preview !== null) {
                session()->setFlashdata(self::FLASH_PREVIEW, ['dir' => $preview['dir'], 'n' => $preview['n']]);
            }

            $query = match (true) {
                $view === WebNativeScreenService::VIEW_CRAFT && $craft !== [] => '&' . http_build_query($craft),
                $view === WebNativeScreenService::VIEW_BASE && $base !== []   => '&' . http_build_query($base),
                $view === WebNativeScreenService::VIEW_TASKS && $tasks !== [] => '&' . http_build_query($tasks),
                $view === WebNativeScreenService::VIEW_SHOP && $shop !== []   => '&' . http_build_query($shop),
                $view === WebNativeScreenService::VIEW_STORAGE && $storage !== [] => '&' . http_build_query($storage),
                default                                                     => '',
            };

            return redirect()->to('/play?view=' . rawurlencode($view) . $query, 303)->withCookies();
        }

        $current = $this->service()->current($characterId);
        try {
            $html = $this->native()->render($characterId, $view, $current['state'], $alert, $events, $preview, $craft, $base, $tasks, $shop, $storage);
        } catch (InvalidArgumentException $e) {
            log_message('info', '[Play.view] render rejected: ' . $e->getMessage());

            return $this->rejected($characterId);
        }

        return $this->response->setJSON([
            'html'   => $html,
            'hud'    => $this->native()->hudHtml($characterId),
            'unread' => $current['unread'],
            'alert'  => $alert,
            'csrf'   => csrf_hash(),
        ]);
    }

    /**
     * Ответ после диспетча в мост: JSON с экраном моста и HUD, либо PRG на `/play`.
     *
     * @param array{state: array<string,mixed>, alert: ?string, unread: int} $result
     */
    private function bridgeResponse(int $characterId, array $result): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->response->setJSON([
                'html'   => view('site/_play/state', ['state' => $result['state'], 'alert' => $result['alert']]),
                'hud'    => $this->native()->hudHtml($characterId),
                'unread' => $result['unread'],
                'alert'  => $result['alert'],
                'csrf'   => csrf_hash(),
            ]);
        }

        if ($result['alert'] !== null) {
            session()->setFlashdata(self::FLASH_ALERT, $result['alert']);
        }

        return redirect()->to('/play', 303)->withCookies();
    }

    /** Отвергнутое намерение: 400 и текущий экран моста, без диспетча. */
    private function rejected(int $characterId): ResponseInterface
    {
        $state = $this->service()->current($characterId);
        if ($this->wantsJson()) {
            return $this->response->setStatusCode(400)->setJSON([
                'error'  => self::REJECTED_ALERT,
                'html'   => view('site/_play/state', ['state' => $state['state'], 'alert' => self::REJECTED_ALERT]),
                'hud'    => $this->native()->hudHtml($characterId),
                'unread' => $state['unread'],
                'alert'  => self::REJECTED_ALERT,
                'csrf'   => csrf_hash(),
            ]);
        }

        return $this->response->setStatusCode(400)
            ->setBody($this->playPage($characterId, $state, self::REJECTED_ALERT));
    }

    public function inbox(): ResponseInterface|string
    {
        $gate = $this->gate();
        if ($gate instanceof ResponseInterface || is_string($gate)) {
            return $this->denied($gate, true);
        }
        $inbox = new WebInboxService();

        return $this->response->setJSON([
            'unread' => $inbox->unreadCount($gate[1]),
            'html'   => view('site/_play/inbox', ['items' => $inbox->latest($gate[1], self::INBOX_PAGE)]),
            'hud'    => $this->native()->hudHtml($gate[1]),
            // p1-13: JS берёт отсюда свежий токен перед PRG-откатом.
            'csrf'   => csrf_hash(),
        ]);
    }

    public function markRead(): ResponseInterface|string
    {
        $gate = $this->gate();
        if ($gate instanceof ResponseInterface || is_string($gate)) {
            return $this->denied($gate, true);
        }
        $inbox = new WebInboxService();
        $inbox->markAllRead($gate[1]);

        return $this->response->setJSON(['unread' => $inbox->unreadCount($gate[1]), 'csrf' => csrf_hash()]);
    }

    public static function playEnabled(): bool
    {
        $raw = (new GameSettingsService())->get(WebDelivery::FLAG, false);

        return $raw === true || (is_numeric($raw) && (int) $raw === 1);
    }

    /**
     * Флаг → вход → персонаж сессии. Иначе готовый ответ: заглушка или редирект на вход.
     *
     * @return array{0:int, 1:int}|ResponseInterface|string
     */
    private function gate(): array|ResponseInterface|string
    {
        if (! self::playEnabled()) {
            return $this->stub('flag_off');
        }
        $current = (new AccountSession())->current();
        if ($current === null) {
            return redirect()->to('/account/login')->withCookies();
        }
        if ($current['character_id'] === null) {
            return $this->stub('no_character');
        }

        return [$current['account_id'], $current['character_id']];
    }

    /** POST и JSON-маршруты при закрытом входе: вход — редирект, прочее — 403 без диспетча. */
    private function denied(ResponseInterface|string $gate, bool $json = false): ResponseInterface
    {
        if ($gate instanceof ResponseInterface) {
            return ($json || $this->wantsJson())
                ? $this->response->setStatusCode(401)->setJSON(['error' => 'login required'])
                : $gate;
        }
        if ($json || $this->wantsJson()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'play unavailable']);
        }

        return $this->response->setStatusCode(403)->setBody($gate);
    }

    private function stub(string $reason): string
    {
        return view('site/play_stub', [
            'reason'       => $reason,
            'can_register' => AccountRegister::registrationOpen(),
            'meta'         => self::meta(),
        ]);
    }

    /**
     * @param array{state: array<string,mixed>, alert: ?string, unread: int} $result
     */
    private function playPage(int $characterId, array $result, ?string $alert, ?string $nativeHtml = null): string
    {
        $config = new WebPlay();

        return view('site/play', [
            'state'          => $result['state'],
            'native_html'    => $nativeHtml,
            'hud_html'       => $this->native()->hudHtml($characterId),
            'unread'         => $result['unread'],
            'poll_seconds'   => max($config->inboxPollMinSeconds, $config->inboxPollSeconds),
            'character_name' => $this->characterName($characterId),
            'alert'          => $alert,
            'meta'           => self::meta(),
        ]);
    }

    private function characterName(int $characterId): string
    {
        $res = Database::connect()->query('SELECT name FROM characters WHERE id = ? LIMIT 1', [$characterId]);
        $row = $res instanceof ResultInterface ? $res->getRowArray() : null;

        return is_array($row) && is_string($row['name'] ?? null) ? $row['name'] : '';
    }

    /**
     * События шага из flash: только сообщения экрана моста нужной формы.
     *
     * @return list<array{message_id:int, text:?string, caption:?string, parse_mode:?string, photo_url:?string, inline_keyboard:list<list<array{text:string, callback_data?:string, url?:string}>>}>
     */
    private static function eventList(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $msg) {
            if (! is_array($msg) || ! is_int($msg['message_id'] ?? null) || ! is_array($msg['inline_keyboard'] ?? null)) {
                continue;
            }
            $rows = [];
            foreach ($msg['inline_keyboard'] as $row) {
                $buttons = [];
                foreach (is_array($row) ? $row : [] as $btn) {
                    if (is_array($btn) && is_string($btn['text'] ?? null) && is_string($btn['callback_data'] ?? null)) {
                        $buttons[] = ['text' => $btn['text'], 'callback_data' => $btn['callback_data']];
                    }
                }
                $rows[] = $buttons;
            }
            $out[] = [
                'message_id'      => $msg['message_id'],
                'text'            => is_string($msg['text'] ?? null) ? $msg['text'] : null,
                'caption'         => is_string($msg['caption'] ?? null) ? $msg['caption'] : null,
                'parse_mode'      => is_string($msg['parse_mode'] ?? null) ? $msg['parse_mode'] : null,
                'photo_url'       => null,
                'inline_keyboard' => $rows,
            ];
        }

        return $out;
    }

    /**
     * Где стоит экран крафта (`bench`, `cat`, `recipe`) — только короткие ключи-идентификаторы; что из
     * них допустимо для персонажа, решает сервис по каталогу.
     *
     * @param callable(string): mixed $read чтение поля запроса (GET или POST)
     *
     * `confirm` — количество партии, которую ядро попросило подтвердить (панель итога на карточке рецепта).
     *
     * @return array{bench?:string, cat?:string, recipe?:string, confirm?:int}
     */
    private static function craftNav(callable $read): array
    {
        $out = [];
        foreach (['bench', 'cat', 'recipe'] as $field) {
            $value = $read($field);
            if (is_string($value) && preg_match('/^[A-Za-z0-9_]{1,40}$/', $value) === 1) {
                $out[$field] = $value;
            }
        }
        $confirm = $read('confirm');
        if (is_string($confirm) && preg_match('/^[1-9][0-9]{0,3}$/', $confirm) === 1) {
            $out['confirm'] = (int) $confirm;
        }

        return $out;
    }

    /**
     * Где стоит экран «🏠 База»: база (`b`, id — только подсказка, ядро перепроверяет), раздел, ключ постройки
     * каталога, тип постройки для апгрейда и уровень, с которого он подтверждён (`from`). Всё прочее отбрасывается.
     *
     * @param callable(string): mixed $read чтение поля запроса (GET или POST)
     *
     * @return array{b?:int, section?:string, key?:string, id?:int, from?:int}
     */
    private static function baseNav(callable $read): array
    {
        $out  = [];
        $from = $read('from');
        if (is_string($from) && preg_match('/^\d{1,4}$/', $from) === 1) {
            $out['from'] = (int) $from;
        }
        foreach (['b', 'id'] as $field) {
            $value = $read($field);
            if (is_string($value) && preg_match('/^[1-9]\d{0,11}$/', $value) === 1) {
                $out[$field] = (int) $value;
            }
        }
        $section = $read('section');
        if (is_string($section) && in_array($section, WebNativeScreenService::BASE_SECTIONS, true)) {
            $out['section'] = $section;
        }
        $key = $read('key');
        if (is_string($key) && preg_match('/^[A-Za-z]{1,40}$/', $key) === 1) {
            $out['key'] = $key;
        }

        return $out;
    }

    /**
     * Где стоит экран «📋 Дела»: раздел и id квеста (карточка, старт, ветка; только подсказка — ядро
     * перепроверяет). Всё прочее отбрасывается.
     *
     * @param callable(string): mixed $read чтение поля запроса (GET или POST)
     *
     * @return array{section?:string, id?:int}
     */
    private static function tasksNav(callable $read): array
    {
        $out     = [];
        $section = $read('section');
        if (is_string($section) && in_array($section, WebNativeScreenService::TASK_SECTIONS, true)) {
            $out['section'] = $section;
        }
        $id = $read('id');
        if (is_string($id) && preg_match('/^[1-9]\d{0,11}$/', $id) === 1) {
            $out['id'] = (int) $id;
        }

        return $out;
    }

    /**
     * Где стоит экран «🛒 Магазин»: раздел, редкость 1…10, ресурс и доля опта (только подсказка — ядро
     * перепроверяет). Всё прочее отбрасывается.
     *
     * @param callable(string): mixed $read чтение поля запроса (GET или POST)
     *
     * @return array{section?:string, r?:int, id?:int, pct?:int}
     */
    private static function shopNav(callable $read): array
    {
        $out     = [];
        $section = $read('section');
        if (is_string($section) && in_array($section, WebNativeScreenService::SHOP_SECTIONS, true)) {
            $out['section'] = $section;
        }
        foreach (['r' => '/^(10|[1-9])$/', 'id' => '/^[1-9]\d{0,11}$/', 'pct' => '/^(100|[1-9]\d?)$/'] as $field => $pattern) {
            $value = $read($field);
            if (is_string($value) && preg_match($pattern, $value) === 1) {
                $out[$field] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * Режим сортировки склада (`mode`) — только из списка режимов склада.
     *
     * @param callable(string): mixed $read чтение поля запроса (GET или POST)
     *
     * @return array{mode?:string}
     */
    private static function storageNav(callable $read): array
    {
        $mode = $read('mode');

        return is_string($mode) && in_array($mode, \App\Services\Player\InventorySortService::STORAGE_MODES, true) ? ['mode' => $mode] : [];
    }

    /**
     * Количество сделки из формы: 0 — нет/не число (ядро откажет «укажи количество»); длиннее 7 цифр — 9 999 999,
     * чтобы ядро ответило честным «больше потолка», а не «укажи количество».
     */
    private static function quantity(mixed $raw): int
    {
        if (! is_string($raw) || preg_match('/^\d+$/', $raw) !== 1) {
            return 0;
        }

        return strlen(ltrim($raw, '0')) > 7 ? 9_999_999 : (int) $raw;
    }

    /** Целая координата из формы (окно карты может заходить за край мира: знак допустим). */
    private static function coordinate(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^-?\d{1,4}$/', $raw) === 1 ? (int) $raw : null;
    }

    /** Число клеток из формы (0 — нет/не число: сервис зажмёт в 1..потолок). */
    private static function count(mixed $raw): int
    {
        return is_string($raw) && preg_match('/^\d{1,4}$/', $raw) === 1 ? (int) $raw : 0;
    }

    private function wantsJson(): bool
    {
        return $this->request instanceof IncomingRequest
            && str_contains($this->request->getHeaderLine('Accept'), 'application/json');
    }

    private function service(): WebActService
    {
        // Через Factories — тест подменяет сервис (injectMock), как AccountOAuth::factory().
        $service = Factories::get('libraries', WebActService::class);

        return $service instanceof WebActService ? $service : new WebActService();
    }

    private function native(): WebNativeScreenService
    {
        // Через Factories — тест подменяет сервис, как service().
        $native = Factories::get('libraries', WebNativeScreenService::class);

        return $native instanceof WebNativeScreenService ? $native : new WebNativeScreenService($this->service());
    }

    /**
     * Сохранённый экран без диспетча; если и он не читается — пустой, с алертом сбоя.
     *
     * @return array{state: array<string,mixed>, alert: ?string, unread: int}
     */
    private function storedOrEmpty(int $characterId): array
    {
        try {
            $result = $this->service()->current($characterId);
        } catch (\Throwable $e) {
            log_message('error', '[Play.index] stored state unavailable: ' . $e::class . ': ' . $e->getMessage());
            $result = ['state' => ['screen' => [], 'history' => [], 'dock' => [], 'input' => null], 'alert' => null, 'unread' => 0];
        }
        $result['alert'] = WebActService::FAILED_ALERT;

        return $result;
    }

    /** @return array<string, string> */
    private static function meta(): array
    {
        return [
            'title'     => 'Играть — Wild World',
            'canonical' => rtrim(base_url('play'), '/'),
            'robots'    => 'noindex,nofollow',
        ];
    }
}
