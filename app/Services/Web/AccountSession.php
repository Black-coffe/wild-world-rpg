<?php

declare(strict_types=1);

namespace App\Services\Web;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\SessionInterface;
use Config\Accounts;
use Config\Cookie;
use Config\Database;
use Config\Services;
use InvalidArgumentException;

/**
 * web-accounts-p0-05 (ADR-188) — единая веб-сессия игрока, ключ — `account_id`.
 *
 * Ключи сессии: `account_id`, `character_id` (если у аккаунта есть персонаж) и legacy
 * `tg_user_id` (= `telegram_users.id`, только если у персонажа есть Telegram) — для старых читателей.
 *
 * Remember-me: cookie `ww_remember` = `selector:validator`; в `account_tokens` лежит только
 * sha256(validator). Токен одноразовый: при восстановлении сессии он удаляется и выдаётся новый
 * (ротация). Неверный validator при живом selector'е = кража → токен удаляется.
 *
 * Legacy: сессия, где есть только `tg_user_id` (вход виджетом до P0), апгрейдится до аккаунта
 * через {@see AccountService::ensureForTelegram()} — игрок не разлогинивается.
 */
class AccountSession
{
    public const KEY_ACCOUNT   = 'account_id';
    public const KEY_CHARACTER = 'character_id';
    public const KEY_TG_USER   = 'tg_user_id';

    /** Всё, что снимает logout (включая отображаемое имя из виджета). */
    private const ALL_KEYS = ['account_id', 'character_id', 'tg_user_id', 'tg_first_name', 'tg_username'];

    private AccountService $accounts;

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    private Accounts $config;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?AccountService $accounts = null, ?BaseConnection $db = null, ?Accounts $config = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->accounts = $accounts ?? new AccountService($this->db);
        $this->config   = $config ?? new Accounts();
    }

    /**
     * Вход в аккаунт: новый id сессии (anti-fixation), ключи, `last_login_at`, remember-токен по запросу.
     */
    public function login(int $accountId, bool $remember = false): void
    {
        $this->session()->regenerate(true);
        $this->writeKeys($accountId);

        $this->db->table('accounts')->where('id', $accountId)->update(['last_login_at' => date('Y-m-d H:i:s')]);

        if ($remember) {
            $this->issueRememberToken($accountId);
        }
    }

    /**
     * Текущий вход или null. Порядок: ключ `account_id` → legacy `tg_user_id` → remember-cookie.
     *
     * @return array{account_id:int, character_id:?int, telegram_user_id:?int}|null
     */
    public function current(): ?array
    {
        $session   = $this->session();
        $accountId = self::toInt($session->get(self::KEY_ACCOUNT));

        if ($accountId !== null && $accountId > 0) {
            if ($this->accountExists($accountId)) {
                return $this->snapshot($accountId);
            }
            // Аккаунт исчез (влит в другой на другом устройстве) — сессия больше ничего не значит.
            $session->remove(self::ALL_KEYS);
        } else {
            $tgUserId = self::toInt($session->get(self::KEY_TG_USER));
            if ($tgUserId !== null && $tgUserId > 0) {
                try {
                    $upgraded = $this->accounts->ensureForTelegram($tgUserId);
                    $this->writeKeys($upgraded);

                    return $this->snapshot($upgraded);
                } catch (InvalidArgumentException) {
                    $session->remove(self::ALL_KEYS);
                }
            }
        }

        return $this->restoreFromCookie();
    }

    public function accountId(): ?int
    {
        return $this->current()['account_id'] ?? null;
    }

    public function characterId(): ?int
    {
        return $this->current()['character_id'] ?? null;
    }

    /** Перечитать персонажа аккаунта в сессию (после привязки / создания персонажа). */
    public function refreshCharacter(): void
    {
        $accountId = self::toInt($this->session()->get(self::KEY_ACCOUNT));
        if ($accountId !== null && $accountId > 0) {
            $this->writeKeys($accountId);
        }
    }

    /** Выход: ключи сессии сняты, id сессии новый, remember-токен удалён, cookie просрочена. */
    public function logout(): void
    {
        $parsed = $this->parseCookie();
        if ($parsed !== null) {
            $this->db->table('account_tokens')
                ->where('selector', $parsed[0])
                ->where('purpose', 'remember')
                ->delete();
        }
        $this->expireCookie();

        $session = $this->session();
        $session->remove(self::ALL_KEYS);
        $session->regenerate(true);
    }

    private function writeKeys(int $accountId): void
    {
        $session = $this->session();
        $session->set(self::KEY_ACCOUNT, $accountId);

        $character   = $this->accounts->characterForAccount($accountId);
        $characterId = self::toInt($character['id'] ?? null);
        $tgUserId    = self::toInt($character['telegram_user_id'] ?? null);

        if ($characterId !== null) {
            $session->set(self::KEY_CHARACTER, $characterId);
        } else {
            $session->remove(self::KEY_CHARACTER);
        }
        if ($tgUserId !== null) {
            $session->set(self::KEY_TG_USER, $tgUserId);
        } else {
            $session->remove(self::KEY_TG_USER);
        }
    }

    /**
     * @return array{account_id:int, character_id:?int, telegram_user_id:?int}
     */
    private function snapshot(int $accountId): array
    {
        $session = $this->session();

        return [
            'account_id'       => $accountId,
            'character_id'     => self::toInt($session->get(self::KEY_CHARACTER)),
            'telegram_user_id' => self::toInt($session->get(self::KEY_TG_USER)),
        ];
    }

    /**
     * @return array{account_id:int, character_id:?int, telegram_user_id:?int}|null
     */
    private function restoreFromCookie(): ?array
    {
        $parsed = $this->parseCookie();
        if ($parsed === null) {
            return null;
        }
        [$selector, $validator] = $parsed;

        $row = $this->row(
            "SELECT id, account_id, validator_hash, expires_at FROM account_tokens WHERE selector = ? AND purpose = 'remember' LIMIT 1",
            [$selector]
        );
        if ($row === null) {
            $this->expireCookie();

            return null;
        }

        $tokenId   = self::toInt($row['id'] ?? null) ?? 0;
        $accountId = self::toInt($row['account_id'] ?? null) ?? 0;
        $hash      = is_string($row['validator_hash'] ?? null) ? $row['validator_hash'] : '';
        $expiresAt = is_string($row['expires_at'] ?? null) ? strtotime($row['expires_at']) : false;

        $valid = $expiresAt !== false && $expiresAt > time() && hash_equals($hash, hash('sha256', $validator));

        // Одноразовость: токен удаляется в любом случае (ротация, просрочка или подбор/кража);
        // affectedRows = 1 — только один из параллельных запросов с той же cookie восстановит вход.
        $this->db->table('account_tokens')->where('id', $tokenId)->delete();
        $won = $this->db->affectedRows() === 1;

        if (! $valid || ! $won || $accountId <= 0 || ! $this->accountExists($accountId)) {
            $this->expireCookie();

            return null;
        }

        $this->login($accountId, true);

        return $this->snapshot($accountId);
    }

    private function issueRememberToken(int $accountId): void
    {
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $lifetime  = $this->config->rememberLifetimeSeconds;
        $now       = time();

        $this->db->table('account_tokens')->insert([
            'account_id'     => $accountId,
            'purpose'        => 'remember',
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at'     => date('Y-m-d H:i:s', $now + $lifetime),
            'created_at'     => date('Y-m-d H:i:s', $now),
        ]);

        $this->response()->setCookie(
            $this->config->rememberCookie,
            $selector . ':' . $validator,
            $lifetime,
            '',
            '/',
            '',
            config(Cookie::class)->secure, // Secure — по Config\Cookie (true в production)
            true,   // HttpOnly
            'Lax'
        );
    }

    /**
     * @return array{0:string, 1:string}|null
     */
    private function parseCookie(): ?array
    {
        $request = Services::request();
        if (! $request instanceof IncomingRequest) {
            return null;
        }
        $raw = $request->getCookie($this->config->rememberCookie);
        if (! is_string($raw) || ! str_contains($raw, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $raw, 2);
        if (preg_match('/^[a-f0-9]{24}$/', $selector) !== 1 || preg_match('/^[a-f0-9]{64}$/', $validator) !== 1) {
            return null;
        }

        return [$selector, $validator];
    }

    private function expireCookie(): void
    {
        $this->response()->deleteCookie($this->config->rememberCookie);
    }

    private function accountExists(int $accountId): bool
    {
        return $this->row('SELECT id FROM accounts WHERE id = ?', [$accountId]) !== null;
    }

    private function session(): SessionInterface
    {
        return Services::session();
    }

    private function response(): ResponseInterface
    {
        return Services::response();
    }

    /**
     * @param list<int|string> $binds
     *
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $binds): ?array
    {
        $res = $this->db->query($sql, $binds);
        if (! $res instanceof ResultInterface) {
            return null;
        }
        $r = $res->getRowArray();
        if (! is_array($r)) {
            return null;
        }
        $out = [];
        foreach ($r as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function toInt(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
