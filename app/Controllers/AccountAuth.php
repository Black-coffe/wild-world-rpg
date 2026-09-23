<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Web\AccountAuthService;
use App\Services\Web\AccountSession;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * web-accounts-p0-05 (ADR-188) — вход на сайт: email+пароль (всегда) и Telegram Login Widget.
 *
 * POST-формы защищены глобальным CSRF (Config\Filters) и `accountThrottle` (Routes).
 * Неверный email и неверный пароль дают одно и то же сообщение.
 */
class AccountAuth extends BaseController
{
    public const BAD_CREDENTIALS = 'Неверная почта или пароль.';

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

    public function attempt(): ResponseInterface|string
    {
        $email    = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        $email    = is_string($email) ? $email : '';
        $password = is_string($password) ? $password : '';

        $accountId = (new AccountAuthService())->verifyPassword($email, $password);
        if ($accountId === null) {
            return $this->render(['error' => self::BAD_CREDENTIALS, 'email' => $email]);
        }

        (new AccountSession())->login($accountId, $this->request->getPost('remember') !== null);

        return redirect()->to('/account')->withCookies();
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
            'email'       => '',
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
