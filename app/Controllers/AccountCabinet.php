<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Web\AccountService;
use App\Services\Web\AccountSession;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * web-accounts-p0-05 (ADR-188) — кабинет аккаунта, заглушка: имя персонажа и выход.
 * Способы входа и их управление — story 07.
 */
class AccountCabinet extends BaseController
{
    /** Сообщения для `?auth=` после входа/привязки виджетом (TelegramLogin::callback). */
    private const AUTH_NOTICES = [
        'linked'       => ['ok', 'Telegram привязан к аккаунту.'],
        'link_refused' => ['error', 'Этот Telegram уже связан с другим персонажем. Один аккаунт — один персонаж.'],
    ];

    public function index(): ResponseInterface|string
    {
        $current = (new AccountSession())->current();
        if ($current === null) {
            return redirect()->to('/account/login')->withCookies();
        }

        $character = (new AccountService())->characterForAccount($current['account_id']);
        $name      = is_string($character['name'] ?? null) && $character['name'] !== '' ? $character['name'] : null;

        $auth   = $this->request->getGet('auth');
        $notice = is_string($auth) ? (self::AUTH_NOTICES[$auth] ?? null) : null;

        return view('site/account_cabinet', [
            'characterName' => $name,
            'hasCharacter'  => $character !== null,
            'notice'        => $notice,
        ]);
    }
}
