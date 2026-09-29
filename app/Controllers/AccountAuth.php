<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Web\AccountSession;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * web-accounts-p0-05 (ADR-188) — страница входа на сайт: Telegram Login Widget, код `/web` из бота,
 * Google и Яндекс. web-accounts-oauth-only (2026-09-30): входа по почте с паролем нет — сайт не хранит
 * паролей; восстановление доступа — у Google/Яндекса/Telegram.
 */
class AccountAuth extends BaseController
{
    /** Сообщения для `?auth=` — их ставит TelegramLogin::callback при возврате на эту страницу. */
    private const AUTH_NOTICES = [
        'fail'         => ['error', 'Telegram не подтвердил вход. Попробуй ещё раз.'],
        'bad_id'       => ['error', 'Telegram не передал id. Попробуй ещё раз.'],
        'bad_payload'  => ['error', 'Telegram не передал данные входа. Попробуй ещё раз.'],
        'db_error'     => ['error', 'Не удалось войти: ошибка на нашей стороне. Попробуй позже.'],
        'logged_out'   => ['info', 'Ты вышел из аккаунта.'],
    ];

    public function login(): ResponseInterface|string
    {
        if ((new AccountSession())->current() !== null) {
            return redirect()->to('/account')->withCookies();
        }

        $auth   = $this->request->getGet('auth');
        $notice = is_string($auth) ? (self::AUTH_NOTICES[$auth] ?? null) : null;

        return $this->render([
            'error'  => $notice !== null && $notice[0] === 'error' ? $notice[1] : null,
            'notice' => $notice !== null && $notice[0] === 'info' ? $notice[1] : null,
        ]);
    }

    public function logout(): ResponseInterface
    {
        (new AccountSession())->logout();

        return redirect()->to('/account/login?auth=logged_out')->withCookies();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): string
    {
        $rawBot = env('telegram.BOT_USERNAME');

        return view('site/account_login', $data + [
            'error'       => null,
            'notice'      => null,
            'botUsername' => is_string($rawBot) ? ltrim($rawBot, '@') : '',
            'meta'        => self::meta(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function meta(): array
    {
        return [
            'title'     => 'Вход — Wild World',
            'canonical' => rtrim(base_url('account/login'), '/'),
            'robots'    => 'noindex,nofollow',
        ];
    }
}
