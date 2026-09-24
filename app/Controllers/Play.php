<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\GameSettings\GameSettingsService;
use App\Services\Web\AccountSession;
use App\Services\Web\WebActService;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebInboxService;
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
 * `POST /play/act` c `Accept: application/json` → `{html, unread, alert, csrf}`; без JS — PRG
 * 303 на `/play` (подсказка кнопки — flash). Отвергнутое намерение — 400, без диспетча.
 */
class Play extends BaseController
{
    /** Сколько последних входящих отдаёт панель колокольчика. */
    private const INBOX_PAGE = 50;

    private const FLASH_ALERT = 'play_alert';

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
        } catch (InvalidArgumentException $e) {
            log_message('error', '[Play.index] bootstrap refused: ' . $e->getMessage());
            $result = $this->service()->current($characterId);
        }
        $flash  = session()->getFlashdata(self::FLASH_ALERT);

        return $this->playPage($characterId, $result, is_string($flash) ? $flash : $result['alert']);
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
            $state = $this->service()->current($characterId);
            if ($this->wantsJson()) {
                return $this->response->setStatusCode(400)->setJSON([
                    'error'  => self::REJECTED_ALERT,
                    'html'   => view('site/_play/state', ['state' => $state['state'], 'alert' => self::REJECTED_ALERT]),
                    'unread' => $state['unread'],
                    'alert'  => self::REJECTED_ALERT,
                    'csrf'   => csrf_hash(),
                ]);
            }

            return $this->response->setStatusCode(400)
                ->setBody($this->playPage($characterId, $state, self::REJECTED_ALERT));
        }

        if ($this->wantsJson()) {
            return $this->response->setJSON([
                'html'   => view('site/_play/state', ['state' => $result['state'], 'alert' => $result['alert']]),
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
    private function playPage(int $characterId, array $result, ?string $alert): string
    {
        $config = new WebPlay();

        return view('site/play', [
            'state'          => $result['state'],
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

    private function wantsJson(): bool
    {
        return $this->request instanceof IncomingRequest
            && str_contains($this->request->getHeaderLine('Accept'), 'application/json');
    }

    private function service(): WebActService
    {
        return new WebActService();
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
