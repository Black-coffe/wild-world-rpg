<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TelegramUserModel;
use App\Services\Web\AccountService;
use App\Services\Web\AccountSession;
use App\Services\Web\TelegramLoginVerifier;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\Services;

/**
 * ADR-061 — Telegram Login Widget callback + logout.
 *
 * Виджет (/map, /account/login, профиль…) шлёт GET-запрос со своими params (id, first_name, ..., hash).
 * Проверяем HMAC через TelegramLoginVerifier, маппим telegram_id → telegram_users → аккаунт
 * через его telegram-identity (ADR-188) и входим через AccountSession (ключи account_id,
 * character_id, legacy tg_user_id). Уже вошедший посетитель — только привязка из кабинета по
 * одноразовому `link_nonce` (story 09, F1): без него ничего не меняется, слияний нет.
 * Telegram отвязан от аккаунта персонажа — вход не создаёт новый аккаунт (A3).
 *
 * Other-player visibility НЕ открывается этим контроллером — только own position
 * (см. Map::data() me-block).
 */
class TelegramLogin extends BaseController
{
    public function callback(): ResponseInterface
    {
        $payload = $this->request->getGet();
        if (! is_array($payload)) {
            return redirect()->to('/map?auth=bad_payload');
        }

        // ADR-092 Фаза 3: куда вернуть после входа (по умолчанию /map). `next` и `link_nonce` НЕ
        // подписаны Telegram'ом → убираем ДО verify (иначе попадут в data_check_string и сломают подпись).
        $next = $this->safeNext(is_scalar($payload['next'] ?? null) ? (string) $payload['next'] : null);
        $linkNonce = is_string($payload['link_nonce'] ?? null) ? $payload['link_nonce'] : null;
        unset($payload['next'], $payload['link_nonce']);

        $verifier = new TelegramLoginVerifier();
        $result   = $verifier->verify($payload);
        if ($result['ok'] !== true) {
            log_message('warning', 'TelegramLogin verify failed: ' . $result['error']);
            return redirect()->to($this->withParam($next, 'auth=fail'));
        }

        $tgId      = $result['tg_id'];
        $firstName = $result['first_name'];
        $username  = $result['username'];
        if ($tgId <= 0) {
            return redirect()->to($this->withParam($next, 'auth=bad_id'));
        }

        // Найти или создать telegram_users
        $tgUserModel = new TelegramUserModel();
        $tgUser      = $tgUserModel->where('telegram_id', $tgId)->first();
        if (! is_array($tgUser)) {
            $insertData = [
                'telegram_id' => $tgId,
                'username'    => $username !== '' ? $username : null,
                'first_name'  => $firstName !== '' ? $firstName : null,
                'last_name'   => $result['last_name'] !== '' ? $result['last_name'] : null,
            ];
            $tgUserModel->insert($insertData);
            $tgUser = $tgUserModel->where('telegram_id', $tgId)->first();
        }
        if (! is_array($tgUser) || ! isset($tgUser['id']) || ! is_numeric($tgUser['id'])) {
            return redirect()->to($this->withParam($next, 'auth=db_error'));
        }

        $tgUserPk = (int) $tgUser['id'];

        $accounts       = new AccountService();
        $accountSession = new AccountSession(accounts: $accounts);
        $current        = $accountSession->current();

        if ($current !== null) {
            // ADR-188 инв. 4 (story 09, F1): уже вошедший — только привязка, начатая этой сессией
            // (nonce из кабинета). Способ входа никогда не переезжает между аккаунтами.
            return redirect()->to('/account?auth=' . $this->link($accounts, $accountSession, $current['account_id'], $tgId, $linkNonce))
                ->withCookies();
        }

        // ADR-188: вход идёт через telegram-identity аккаунта.
        $accountId = $accounts->accountForTelegramLogin($tgUserPk);
        if ($accountId === null) {
            // Telegram отвязан от аккаунта персонажа (A3): новый пустой аккаунт не создаём.
            return redirect()->to('/account/link?auth=tg_unlinked')->withCookies();
        }

        // Новый id сессии (anti-fixation) + account_id/character_id/tg_user_id.
        $accountSession->login($accountId);
        $session = Services::session();
        $session->set('tg_first_name', $firstName);
        $session->set('tg_username', $username);

        return redirect()->to($this->withParam($next, 'auth=ok'))->withCookies();
    }

    public function logout(): ResponseInterface
    {
        $next = $this->safeNext(is_scalar($this->request->getPost('next') ?? null) ? (string) $this->request->getPost('next') : null);
        (new AccountSession())->logout();

        return redirect()->to($this->withParam($next, 'auth=logged_out'))->withCookies();
    }

    /**
     * Callback виджета у вошедшего посетителя. Без верного одноразового nonce ничего не меняется.
     * С nonce: свободный Telegram добавляется к текущему аккаунту, свой — no-op, чужой — отказ.
     * Возвращает код уведомления кабинета (`?auth=`).
     */
    private function link(AccountService $accounts, AccountSession $session, int $currentAccount, int $tgId, ?string $nonce): string
    {
        $subject = (string) $tgId;
        $owner   = $accounts->findByIdentity('telegram', $subject);

        if (! $session->consumeTelegramLinkNonce($nonce)) {
            return $owner === $currentAccount ? 'ok' : 'link_unconfirmed';
        }
        if ($owner === $currentAccount) {
            return 'link_already';
        }
        if ($owner !== null) {
            return 'link_refused';
        }

        return $accounts->addIdentity($currentAccount, 'telegram', $subject) ? 'linked' : 'link_refused';
    }

    /**
     * Безопасный локальный путь для возврата (anti open-redirect). Дефолт /map.
     * Допускаем только относительные пути, начинающиеся с одного «/» (не «//», без схемы).
     */
    private function safeNext(?string $next): string
    {
        if ($next === null || $next === '') {
            return '/map';
        }
        if ($next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '://')) {
            return '/map';
        }

        return $next;
    }

    /** Добавить query-параметр к пути, корректно выбрав ?/&. */
    private function withParam(string $path, string $param): string
    {
        return $path . (str_contains($path, '?') ? '&' : '?') . $param;
    }
}
