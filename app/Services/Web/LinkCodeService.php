<?php

declare(strict_types=1);

namespace App\Services\Web;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Accounts;
use Config\Database;
use RuntimeException;

/**
 * web-accounts-p0-06 (ADR-188) — одноразовый код из бота для входа на сайт и привязки персонажа.
 *
 * Код выдаёт бот (`/web`, кнопка в «⚙️ Настройках»), вводит игрок на `/account/link`.
 * В `account_link_codes` лежит только sha256 нормализованного кода. Новый код удаляет прежние
 * неиспользованные коды того же персонажа. Срок жизни и время — по часам БД (`NOW()`).
 *
 * Правило плана A2 ({@see link()}):
 *   - гость → вход в аккаунт персонажа;
 *   - вошёл в аккаунт без персонажа → его способы входа вливаются в аккаунт персонажа;
 *   - вошёл в аккаунт с другим персонажем → отказ, код НЕ тратится.
 */
class LinkCodeService
{
    /** Алфавит кода: без 0/O/1/I/L — чтобы не путать при вводе с экрана телефона. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const STATUS_LOGIN   = 'login';
    public const STATUS_MERGED  = 'merged';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_INVALID = 'invalid';

    public const MSG_NOT_FOUND = 'Такого кода нет. Проверь буквы или запроси новый в боте: /web. Если ты уже запрашивал код ещё раз, работает только последний.';
    public const MSG_USED      = 'Этот код уже использован. Запроси новый в боте: /web.';
    public const MSG_EXPIRED   = 'Срок действия кода истёк. Запроси новый в боте: /web.';
    public const MSG_OTHER     = 'Ты вошёл в аккаунт с другим персонажем. Выйди из него и введи код снова.';
    public const MSG_FAILED    = 'Не удалось привязать персонажа. Запроси новый код в боте: /web.';

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    private AccountService $accounts;

    private Accounts $config;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?AccountService $accounts = null, ?Accounts $config = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->accounts = $accounts ?? new AccountService($this->db);
        $this->config   = $config ?? new Accounts();
    }

    /**
     * Новый код персонажу; прежние неиспользованные коды персонажа удаляются.
     *
     * @return array{code:string, expires_at:string, ttl_minutes:int}
     */
    public function issue(int $characterId): array
    {
        $ttl  = max(60, $this->config->linkCodeTtlSeconds);
        $code = self::generate(max(6, $this->config->linkCodeLength));

        $this->db->transBegin();
        $this->db->table('account_link_codes')
            ->where('character_id', $characterId)
            ->where('used_at', null)
            ->delete();
        $this->db->query(
            'INSERT INTO account_link_codes (character_id, code_hash, expires_at, created_at) '
            . 'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())',
            [$characterId, self::hash($code), $ttl]
        );
        $this->db->transCommit();

        $row       = $this->row('SELECT expires_at FROM account_link_codes WHERE code_hash = ?', [self::hash($code)]);
        $expiresAt = is_string($row['expires_at'] ?? null) ? $row['expires_at'] : '';
        if ($expiresAt === '') {
            throw new RuntimeException('LinkCodeService: link code insert failed');
        }

        return [
            'code'        => $code,
            'expires_at'  => $expiresAt,
            'ttl_minutes' => intdiv($ttl + 59, 60),
        ];
    }

    /**
     * Атомарно гасит живой код (affected_rows = 1) и возвращает персонажа и его аккаунт.
     * null — код неизвестен, использован или просрочен.
     *
     * @return array{character_id:int, account_id:int}|null
     */
    public function redeem(string $code): ?array
    {
        $hash = self::hash(self::normalize($code));

        $this->db->query(
            'UPDATE account_link_codes SET used_at = NOW() '
            . 'WHERE code_hash = ? AND used_at IS NULL AND expires_at > NOW()',
            [$hash]
        );
        if ($this->db->affectedRows() !== 1) {
            return null;
        }

        $row         = $this->row('SELECT character_id FROM account_link_codes WHERE code_hash = ?', [$hash]);
        $characterId = self::toInt($row['character_id'] ?? null);
        if ($characterId === null) {
            return null;
        }

        $accountId = $this->accounts->ensureForCharacter($characterId);
        if ($accountId === null) {
            $accountId = $this->accounts->createAccount('telegram');
            $this->accounts->attachCharacter($accountId, $characterId);
        }

        return ['character_id' => $characterId, 'account_id' => $accountId];
    }

    /**
     * Правило A2 целиком. Отказ проверяется ДО траты кода. `account_id` — аккаунт, в который
     * вызывающий должен войти (`AccountSession::login()`), null — при отказе.
     *
     * @return array{status:string, message:string, account_id:?int}
     */
    public function link(string $code, ?int $currentAccountId): array
    {
        $hash = self::hash(self::normalize($code));
        $peek = $this->row(
            'SELECT character_id, used_at, (expires_at > NOW()) AS live FROM account_link_codes WHERE code_hash = ?',
            [$hash]
        );
        if ($peek === null) {
            return self::fail(self::STATUS_INVALID, self::MSG_NOT_FOUND);
        }
        if ($peek['used_at'] !== null) {
            return self::fail(self::STATUS_INVALID, self::MSG_USED);
        }
        if ((int) (is_numeric($peek['live']) ? $peek['live'] : 0) !== 1) {
            return self::fail(self::STATUS_INVALID, self::MSG_EXPIRED);
        }

        $characterId = self::toInt($peek['character_id'] ?? null) ?? 0;
        $targetId    = $this->accounts->ensureForCharacter($characterId);
        $merge       = false;
        if ($currentAccountId !== null && $currentAccountId !== $targetId) {
            if ($this->accounts->characterForAccount($currentAccountId) !== null) {
                return self::fail(self::STATUS_REFUSED, self::MSG_OTHER);
            }
            $merge = true;
        }

        $claimed = $this->redeem($code);
        if ($claimed === null) {
            // Гонка: код потратил параллельный запрос или он истёк между проверкой и UPDATE.
            return self::fail(self::STATUS_INVALID, self::MSG_USED);
        }
        $accountId = $claimed['account_id'];

        if ($merge && $currentAccountId !== null) {
            if (! $this->accounts->mergeInto($currentAccountId, $accountId)) {
                return self::fail(self::STATUS_REFUSED, self::MSG_FAILED);
            }

            return [
                'status'     => self::STATUS_MERGED,
                'message'    => 'Персонаж привязан: твои способы входа теперь ведут к нему.',
                'account_id' => $accountId,
            ];
        }

        return ['status' => self::STATUS_LOGIN, 'message' => 'Ты вошёл в аккаунт персонажа.', 'account_id' => $accountId];
    }

    /** Верхний регистр, без пробелов и дефисов — как бы игрок ни перепечатал код. */
    public static function normalize(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    public static function hash(string $normalizedCode): string
    {
        return hash('sha256', $normalizedCode);
    }

    private static function generate(int $length): string
    {
        $max  = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * @return array{status:string, message:string, account_id:null}
     */
    private static function fail(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message, 'account_id' => null];
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
