<?php

declare(strict_types=1);

namespace App\Services\Web;

use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Accounts;
use Config\Database;
use Config\Email as EmailConfig;
use Config\Services;
use Throwable;

/**
 * web-accounts-p0-08 (ADR-188) — сброс пароля email-входа по ссылке из письма.
 *
 * Токен — строка `account_tokens` с purpose `password_reset`: `selector` открыто, в БД только
 * sha256(validator); ссылка несёт `selector-validator`. Токен одноразовый (удаляется при любой
 * попытке погасить) и живёт `Config\Accounts::$passwordResetTtlSeconds`.
 *
 * Неизвестный email получает тот же ответ `sent`, что и известный (без перечисления аккаунтов).
 * Если письмо не ушло (SMTP на проде не проверен, plan A10), ответ — `mail_failed`, токен
 * удаляется, а операторам уходит одна строка `log_message('error', …)` без адреса почты.
 * Story 10: результат — только для этого лога; страница одна на любой исход (иначе отказ почты
 * выдавал бы, какие адреса заведены), и она всегда называет вход кодом из бота.
 */
class PasswordResetService
{
    public const SENT        = 'sent';
    public const MAIL_FAILED = 'mail_failed';

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    private Accounts $config;

    /** @var Closure(string, string, string): bool */
    private Closure $mailer;

    /**
     * @param BaseConnection<object, object>|null              $db
     * @param (Closure(string, string, string): bool)|null      $mailer (to, subject, body) → отправлено ли
     */
    public function __construct(?BaseConnection $db = null, ?Accounts $config = null, ?Closure $mailer = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = $config ?? new Accounts();
        $this->mailer = $mailer ?? self::defaultMailer(...);
    }

    /**
     * Выпустить токен и отправить письмо со ссылкой.
     *
     * @return 'sent'|'mail_failed'
     */
    public function request(string $email): string
    {
        $identity = $this->row(
            "SELECT account_id, subject, email FROM account_identities WHERE provider = 'email' AND subject = ? LIMIT 1",
            [AccountAuthService::normalizeEmail($email)]
        );
        $accountId = is_numeric($identity['account_id'] ?? null) ? (int) $identity['account_id'] : 0;
        if ($identity === null || $accountId <= 0) {
            return self::SENT;
        }

        // Раньше выданные неиспользованные ссылки аккаунта гаснут: жива только последняя.
        $this->db->table('account_tokens')
            ->where('account_id', $accountId)
            ->where('purpose', 'password_reset')
            ->delete();

        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $now       = time();
        $ttl       = $this->config->passwordResetTtlSeconds;

        $this->db->table('account_tokens')->insert([
            'account_id'     => $accountId,
            'purpose'        => 'password_reset',
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at'     => date('Y-m-d H:i:s', $now + $ttl),
            'created_at'     => date('Y-m-d H:i:s', $now),
        ]);

        $to = is_string($identity['email'] ?? null) && $identity['email'] !== ''
            ? $identity['email']
            : (is_string($identity['subject'] ?? null) ? $identity['subject'] : '');
        $link    = base_url('account/reset/' . $selector . '-' . $validator);
        $minutes = max(1, intdiv($ttl, 60));
        $body    = "Кто-то (надеемся, ты) запросил сброс пароля для входа на сайт Wild World.\n\n"
            . "Новый пароль можно задать по ссылке (действует {$minutes} мин., один раз):\n{$link}\n\n"
            . "Если это был не ты — просто проигнорируй письмо, пароль останется прежним.";

        $reason = 'transport returned false';
        try {
            $sent = ($this->mailer)($to, 'Wild World — сброс пароля', $body);
        } catch (Throwable $e) {
            $sent   = false;
            $reason = $e::class . ': ' . $e->getMessage();
        }

        if (! $sent) {
            $this->db->table('account_tokens')->where('selector', $selector)->delete();
            // Адрес не пишем: причина может его цитировать (SMTP «RCPT TO …») — вырезаем.
            $reason = $to !== '' ? str_ireplace($to, '[email]', $reason) : $reason;
            log_message('error', '[PasswordReset] reset mail failed for account {account}: {reason}', [
                'account' => $accountId,
                'reason'  => $reason,
            ]);

            return self::MAIL_FAILED;
        }

        return self::SENT;
    }

    /**
     * Погасить токен и задать новый пароль email-входа. False — ссылка неверна, устарела или
     * уже использована, либо пароль короче минимума (тогда токен не тратится).
     */
    public function complete(string $selector, string $validator, string $newPassword): bool
    {
        if (mb_strlen($newPassword) < $this->config->passwordMinLength) {
            return false;
        }
        if (preg_match('/^[a-f0-9]{24}$/', $selector) !== 1 || preg_match('/^[a-f0-9]{64}$/', $validator) !== 1) {
            return false;
        }

        $row = $this->row(
            "SELECT id, account_id, validator_hash, expires_at FROM account_tokens WHERE selector = ? AND purpose = 'password_reset' LIMIT 1",
            [$selector]
        );
        if ($row === null) {
            return false;
        }

        $tokenId   = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        $accountId = is_numeric($row['account_id'] ?? null) ? (int) $row['account_id'] : 0;
        $hash      = is_string($row['validator_hash'] ?? null) ? $row['validator_hash'] : '';
        $expiresAt = is_string($row['expires_at'] ?? null) ? strtotime($row['expires_at']) : false;

        // Одноразовость: токен удаляется при любой попытке; affectedRows = 1 — выигрывает один запрос.
        $this->db->table('account_tokens')->where('id', $tokenId)->delete();
        $won = $this->db->affectedRows() === 1;

        if (! $won || $accountId <= 0 || $expiresAt === false || $expiresAt <= time()
            || ! hash_equals($hash, hash('sha256', $validator))) {
            return false;
        }

        // Email-вход могли отвязать, пока письмо шло, — тогда менять нечего.
        if ($this->row("SELECT id FROM account_identities WHERE account_id = ? AND provider = 'email' LIMIT 1", [$accountId]) === null) {
            return false;
        }
        $this->db->table('account_identities')
            ->where('account_id', $accountId)
            ->where('provider', 'email')
            ->update(['secret_hash' => password_hash($newPassword, PASSWORD_DEFAULT)]);

        // Новый пароль выкидывает запомненные устройства: старые remember-токены больше не входят.
        $this->db->table('account_tokens')
            ->where('account_id', $accountId)
            ->where('purpose', 'remember')
            ->delete();

        return true;
    }

    /**
     * Отправка через штатный CI4 Email (Config\Email); false — транспорт не справился.
     * Общий экземпляр + clear(true): тесты подменяют его `Services::injectMock('email', …)`.
     */
    private static function defaultMailer(string $to, string $subject, string $body): bool
    {
        $config = config(EmailConfig::class);
        if ($to === '' || $config->fromEmail === '') {
            return false;
        }

        $email = Services::email();
        $email->clear(true);
        $email->setFrom($config->fromEmail, $config->fromName);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMailType('text');
        $email->setMessage($body);

        return $email->send(false);
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
}
