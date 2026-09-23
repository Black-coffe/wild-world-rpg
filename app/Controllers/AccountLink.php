<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Web\AccountSession;
use App\Services\Web\LinkCodeService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * web-accounts-p0-06 (ADR-188) — `/account/link`: ввод одноразового кода из бота (`/web`).
 *
 * Правило плана A2 — в {@see LinkCodeService::link()}: гость входит в аккаунт персонажа, аккаунт
 * без персонажа вливается в аккаунт персонажа, аккаунт с другим персонажем получает отказ (код
 * не тратится). Успех завершается {@see AccountSession::login()} и редиректом на `/account`.
 * POST защищён глобальным CSRF и `accountThrottle` (Routes, поле `code`).
 */
class AccountLink extends BaseController
{
    public function index(): string
    {
        return $this->render([]);
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

        $session->login($result['account_id']);

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
