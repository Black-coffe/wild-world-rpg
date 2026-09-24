<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Web\PasswordResetService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Accounts;
use Config\Social;

/**
 * web-accounts-p0-08 (ADR-188) — сброс пароля email-входа.
 *
 * `/account/reset` — запрос ссылки на почту; `/account/reset/{selector-validator}` — новый пароль.
 * Story 10: ответ на запрос ссылки один для любого исхода — неизвестная почта, письмо ушло,
 * письмо не ушло (SMTP, plan A10). Иначе отказ почты выдавал бы, какие адреса заведены. Страница
 * всегда честно оговаривает, что письмо может не дойти, и ведёт на вход кодом из бота (`/web` →
 * `/account/link`); реальный отказ видят операторы в логе (PasswordResetService). Работает без флага `web.open_registration`: это восстановление существующего
 * входа, а не регистрация. POST-формы — глобальный CSRF и `accountThrottle` (Routes).
 */
class AccountPassword extends BaseController
{
    public function request(): string
    {
        return $this->render(['mode' => 'request']);
    }

    public function send(): string
    {
        $email = $this->request->getPost('email');
        $email = is_string($email) ? trim($email) : '';
        if ($email === '') {
            return $this->render(['mode' => 'request', 'error' => 'Укажи почту, к которой привязан вход.']);
        }

        // Результат нужен только логу сервиса: видимый ответ от него не зависит.
        (new PasswordResetService())->request($email);

        return $this->render(['mode' => 'requested']);
    }

    public function form(string $token = ''): string
    {
        return $this->render(['mode' => 'form', 'token' => $token]);
    }

    public function complete(string $token = ''): string
    {
        $password = $this->request->getPost('password');
        $password = is_string($password) ? $password : '';
        $min      = (new Accounts())->passwordMinLength;

        if (mb_strlen($password) < $min) {
            return $this->render([
                'mode'  => 'form',
                'token' => $token,
                'error' => "Пароль слишком короткий: нужно не меньше {$min} символов.",
            ]);
        }

        [$selector, $validator] = array_pad(explode('-', $token, 2), 2, '');
        if (! (new PasswordResetService())->complete($selector, $validator, $password)) {
            return $this->render(['mode' => 'invalid']);
        }

        return $this->render(['mode' => 'done']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): string
    {
        return view('site/account_reset', $data + [
            'mode'      => 'request',
            'token'     => '',
            'error'     => null,
            'minLength' => (new Accounts())->passwordMinLength,
            'ttlMin'    => max(1, intdiv((new Accounts())->passwordResetTtlSeconds, 60)),
            'botLink'   => config(Social::class)->botStart('src_site_reset'),
            'meta'      => [
                'title'     => 'Сброс пароля — Wild World',
                'canonical' => rtrim(base_url('account/reset'), '/'),
                'robots'    => 'noindex,nofollow',
            ],
        ]);
    }
}
