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
 * character_id, legacy tg_user_id). Уже вошедший в другой аккаунт — привязка по правилу A2.
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

        // ADR-092 Фаза 3: куда вернуть после входа (по умолчанию /map). `next` НЕ подписан
        // Telegram'ом → убираем ДО verify (иначе попадёт в data_check_string и сломает подпись).
        $next = $this->safeNext(is_scalar($payload['next'] ?? null) ? (string) $payload['next'] : null);
        unset($payload['next']);

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

        // ADR-188 (web-accounts-p0-05): вход идёт через telegram-identity аккаунта.
        $accounts        = new AccountService();
        $accountSession  = new AccountSession(accounts: $accounts);
        $telegramAccount = $accounts->ensureForTelegram($tgUserPk);
        $current         = $accountSession->current();
        $status          = 'auth=ok';

        if ($current !== null && $current['account_id'] !== $telegramAccount) {
            // Уже вошёл в другой аккаунт → привязка по правилу плана A2.
            $merged = $this->linkTelegram($accounts, $current['account_id'], $telegramAccount);
            if ($merged === null) {
                return redirect()->to('/account?auth=link_refused')->withCookies();
            }
            $telegramAccount = $merged;
            $status          = 'auth=linked';
        }

        // Новый id сессии (anti-fixation) + account_id/character_id/tg_user_id.
        $accountSession->login($telegramAccount);
        $session = Services::session();
        $session->set('tg_first_name', $firstName);
        $session->set('tg_username', $username);

        return redirect()->to($this->withParam($next, $status))->withCookies();
    }

    public function logout(): ResponseInterface
    {
        $next = $this->safeNext(is_scalar($this->request->getPost('next') ?? null) ? (string) $this->request->getPost('next') : null);
        (new AccountSession())->logout();

        return redirect()->to($this->withParam($next, 'auth=logged_out'))->withCookies();
    }

    /**
     * Правило A2: аккаунт без персонажа вливается в аккаунт с персонажем; два разных персонажа —
     * отказ (null). Возвращает аккаунт, в котором оказался вход.
     */
    private function linkTelegram(AccountService $accounts, int $currentAccount, int $telegramAccount): ?int
    {
        $currentHasChar  = $accounts->characterForAccount($currentAccount) !== null;
        $telegramHasChar = $accounts->characterForAccount($telegramAccount) !== null;

        if (! $currentHasChar) {
            return $accounts->mergeInto($currentAccount, $telegramAccount) ? $telegramAccount : null;
        }
        if (! $telegramHasChar) {
            return $accounts->mergeInto($telegramAccount, $currentAccount) ? $currentAccount : null;
        }

        return null;
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
