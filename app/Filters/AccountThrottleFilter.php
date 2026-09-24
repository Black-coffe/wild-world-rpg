<?php

declare(strict_types=1);

namespace App\Filters;

use App\Controllers\AccountAuth;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Accounts;
use Config\Services;

/**
 * web-accounts-p0-05 (ADR-188) — лимит попыток на формах `/account/*` (POST).
 *
 * Два ведра CI4 Throttler: по IP (`Config\Accounts::$throttleIpPerMinute` в минуту) и по
 * идентификатору — email или код из формы (`$throttleIdentifierPerHour` в час). Идентификатор
 * защищает конкретный аккаунт от перебора с многих IP. Превышение → 429 со страницей входа
 * и читаемым уведомлением.
 */
class AccountThrottleFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
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
