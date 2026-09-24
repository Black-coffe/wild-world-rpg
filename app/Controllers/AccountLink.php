<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Web\AccountSession;
use App\Services\Web\LinkCodeService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * web-accounts-p0-06 (ADR-188) — `/account/link`: ввод одноразового кода из бота (`/web`).
 *
 * Правило — в {@see LinkCodeService::link()} (story 09, F1, слияний нет): гость входит в аккаунт
 * персонажа; вошедший в этот же аккаунт — no-op; вошедший в любой другой — отказ (код не
 * тратится). Вход завершается {@see AccountSession::login()} и редиректом на `/account`.
 * Сюда же TelegramLogin ведёт гостя, чей Telegram отвязан от аккаунта персонажа (`?auth=tg_unlinked`).
 * POST защищён глобальным CSRF и `accountThrottle` (Routes, поле `code`).
 */
class AccountLink extends BaseController
{
    public const MSG_TG_UNLINKED = 'Вход через Telegram отвязан от аккаунта твоего персонажа. Войди другим способом, привязанным к аккаунту, или введи здесь код из бота (/web).';

    public function index(): string
    {
        $auth = $this->request->getGet('auth');

        return $this->render($auth === 'tg_unlinked' ? ['error' => self::MSG_TG_UNLINKED] : []);
    }

    public function redeem(): ResponseInterface|string
    {
        $raw  = $this->request->getPost('code');
        $code = is_string($raw) ? trim($raw) : '';
        if (LinkCodeService::normalize($code) === '') {
            return $this->render(['error' => 'Введи код из бота. Получить его: команда /web в боте.']);
        }

        $session = new AccountSession();
        $result  = (new LinkCodeService())->link($code, $session->accountId());

        if ($result['account_id'] === null) {
            return $this->render(['error' => $result['message'], 'code' => $code]);
        }

        if ($result['status'] !== LinkCodeService::STATUS_NOOP) {
            $session->login($result['account_id']);
        }

        return redirect()->to('/account')->withCookies();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): string
    {
        return view('site/account_link', $data + [
            'error'    => null,
            'code'     => '',
            'loggedIn' => (new AccountSession())->accountId() !== null,
            'meta'     => [
                'title'     => 'Код из бота — Wild World',
                'canonical' => rtrim(base_url('account/link'), '/'),
                'robots'    => 'noindex,nofollow',
            ],
        ]);
    }
}
