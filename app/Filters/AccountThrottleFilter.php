<?php

declare(strict_types=1);

namespace App\Filters;

use App\Controllers\AccountAuth;
use App\Services\Web\AccountSession;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Accounts;
use Config\Services;
use Config\WebPlay;

/**
 * web-accounts-p0-05 (ADR-188) — лимит попыток на формах `/account/*` (POST).
 *
 * Два ведра CI4 Throttler: по IP (`Config\Accounts::$throttleIpPerMinute` в минуту) и по
 * идентификатору — email или код из формы (`$throttleIdentifierPerHour` в час). Идентификатор
 * защищает конкретный аккаунт от перебора с многих IP. Превышение → 429 со страницей входа
 * и читаемым уведомлением.
 *
 * web-bridge-p1-07 (ADR-189 §6, plan A9) — аргументы `accountThrottle:play` и
 * `accountThrottle:inbox`: отдельное ведро на аккаунт из сессии (`Config\WebPlay`
 * `actsPerMinute` / `inboxReadsPerMinute`), любой метод (чтение входящих — GET). Без аргумента
 * поведение P0 не меняется. Без входа ведро не считается: `/play` сам отправит на вход.
 */
class AccountThrottleFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $bucket = is_array($arguments) && is_string($arguments[0] ?? null) ? $arguments[0] : null;
        if ($bucket !== null) {
            return $this->playBudget($bucket);
        }

        if (! $request instanceof IncomingRequest || strtolower($request->getMethod()) !== 'post') {
            return null;
        }

        $config    = new Accounts();
        $throttler = Services::throttler();

        $ip = $request->getIPAddress();
        if (! $throttler->check('acct-ip-' . md5($ip), $config->throttleIpPerMinute, MINUTE)) {
            return $this->tooMany($throttler->getTokenTime());
        }

        $identifier = $this->identifier($request);
        if ($identifier !== null
            && ! $throttler->check('acct-id-' . md5($identifier), $config->throttleIdentifierPerHour, HOUR)) {
            return $this->tooMany($throttler->getTokenTime());
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function playBudget(string $bucket): ?ResponseInterface
    {
        $config   = new WebPlay();
        $capacity = match ($bucket) {
            'play'  => $config->actsPerMinute,
            'inbox' => $config->inboxReadsPerMinute,
            default => null,
        };
        if ($capacity === null) {
            log_message('error', "[AccountThrottleFilter] unknown bucket '{$bucket}' — not throttled");

            return null;
        }

        $accountId = (new AccountSession())->accountId();
        if ($accountId === null) {
            return null;
        }

        $throttler = Services::throttler();
        if ($throttler->check("play-{$bucket}-{$accountId}", $capacity, MINUTE)) {
            return null;
        }
        $wait = max(1, $throttler->getTokenTime());

        return Services::response()
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) $wait)
            ->setJSON(['error' => "Слишком часто. Подожди {$wait} с. и попробуй снова."]);
    }

    private function identifier(IncomingRequest $request): ?string
    {
        foreach (['email', 'code'] as $field) {
            $value = $request->getPost($field);
            if (is_string($value) && trim($value) !== '') {
                return $field . ':' . mb_strtolower(trim($value));
            }
        }

        return null;
    }

    private function tooMany(int $waitSeconds): ResponseInterface
    {
        $minutes = max(1, (int) ceil(max(0, $waitSeconds) / 60));

        return Services::response()
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) max(1, $waitSeconds))
            ->setBody(view('site/account_login', [
                'error'       => "Слишком много попыток. Подожди {$minutes} мин. и попробуй снова.",
                'email'       => '',
                'botUsername' => '',
                'meta'        => AccountAuth::meta(),
            ]));
    }
}
