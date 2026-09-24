<?php

declare(strict_types=1);

namespace App\Services\Web;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Accounts;
use Config\Database;

/**
 * web-accounts-p0-05 (ADR-188) — вход и регистрация по email + пароль.
 *
 * Пароль хранится только как `password_hash()` в `account_identities.secret_hash`
 * (provider = 'email', subject = email в нижнем регистре без пробелов по краям).
 * Проверка — `password_verify`; неизвестный email тоже прогоняет `password_verify` по
 * фиктивному хэшу, чтобы время ответа не выдавало, есть ли такой email.
 */
class AccountAuthService
{
    public const ERR_INVALID_EMAIL  = 'invalid_email';
    public const ERR_WEAK_PASSWORD  = 'weak_password';
    public const ERR_EMAIL_TAKEN    = 'email_taken';
    public const ERR_HAS_OTHER_MAIL = 'account_has_email';

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

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** accountId при верной паре email+пароль, иначе null (без различия «нет email» / «не тот пароль»). */
    public function verifyPassword(string $email, string $password): ?int
    {
        $row = $this->identity(self::normalizeEmail($email));
        $hash = is_string($row['secret_hash'] ?? null) ? $row['secret_hash'] : null;

        if ($row === null || $hash === null || $hash === '') {
            password_verify($password, self::dummyHash());

            return null;
        }
        if (! password_verify($password, $hash)) {
            return null;
        }

        $identityId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        $update     = ['last_used_at' => date('Y-m-d H:i:s')];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $update['secret_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->db->table('account_identities')->where('id', $identityId)->update($update);

        return is_numeric($row['account_id'] ?? null) ? (int) $row['account_id'] : null;
    }

    /** Новый аккаунт (acquisition_source = web) с email-identity; иначе код ошибки ERR_*. */
    public function registerWithEmail(string $email, string $password): int|string
    {
        $subject = self::normalizeEmail($email);
        $error   = $this->validate($subject, $password);
        if ($error !== null) {
            return $error;
        }
        if ($this->accounts->findByIdentity('email', $subject) !== null) {
            return self::ERR_EMAIL_TAKEN;
        }

        $this->db->transBegin();
        $accountId = $this->accounts->createAccount('web');
        if (! $this->accounts->addIdentity($accountId, 'email', $subject, password_hash($password, PASSWORD_DEFAULT), trim($email))) {
            $this->db->transRollback();

            return self::ERR_EMAIL_TAKEN;
        }
        $this->db->transCommit();

        return $accountId;
    }

    /**
     * Добавить email+пароль к аккаунту или сменить пароль у его же email. Ошибка — код ERR_*:
     * email занят другим аккаунтом, либо у аккаунта уже другой email.
     */
    public function setEmailPassword(int $accountId, string $email, string $password): true|string
    {
        $subject = self::normalizeEmail($email);
        $error   = $this->validate($subject, $password);
        if ($error !== null) {
            return $error;
        }

        $hash  = password_hash($password, PASSWORD_DEFAULT);
        $owner = $this->accounts->findByIdentity('email', $subject);
        if ($owner !== null && $owner !== $accountId) {
            return self::ERR_EMAIL_TAKEN;
        }
        if ($owner === $accountId) {
            $this->db->table('account_identities')
                ->where('provider', 'email')
                ->where('subject', $subject)
                ->update(['secret_hash' => $hash]);

            return true;
        }

        foreach ($this->accounts->identities($accountId) as $identity) {
            if (($identity['provider'] ?? null) === 'email') {
                return self::ERR_HAS_OTHER_MAIL;
            }
        }

        return $this->accounts->addIdentity($accountId, 'email', $subject, $hash, trim($email))
            ? true
            : self::ERR_EMAIL_TAKEN;
    }

    private function validate(string $subject, string $password): ?string
    {
        if ($subject === '' || mb_strlen($subject) > 191 || filter_var($subject, FILTER_VALIDATE_EMAIL) === false) {
            return self::ERR_INVALID_EMAIL;
        }
        if (mb_strlen($password) < $this->config->passwordMinLength) {
            return self::ERR_WEAK_PASSWORD;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function identity(string $subject): ?array
    {
        if ($subject === '') {
            return null;
        }
        $res = $this->db->query(
            "SELECT id, account_id, secret_hash FROM account_identities WHERE provider = 'email' AND subject = ? LIMIT 1",
            [$subject]
        );
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

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash('ww-dummy-password-for-timing', PASSWORD_DEFAULT);
    }
}
