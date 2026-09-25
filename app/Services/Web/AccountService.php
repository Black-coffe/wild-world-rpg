<?php

declare(strict_types=1);

namespace App\Services\Web;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

/**
 * web-accounts-p0-01 (ADR-188) — операции над аккаунтом игрока: корнем, которому принадлежат
 * персонаж (`characters.account_id`) и способы входа (`account_identities`).
 *
 * Инварианты:
 *   - `(provider, subject)` принадлежит ровно одному аккаунту (UNIQUE в БД + проверка здесь);
 *   - у аккаунта нельзя отвязать последний способ входа;
 *   - способ входа никогда не переходит из аккаунта в аккаунт: слияний нет (ADR-188, инв. 4).
 *
 * Telegram-subject = `telegram_users.telegram_id` десятичной строкой.
 */
class AccountService
{
    public const PROVIDERS = ['email', 'google', 'yandex', 'telegram'];

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Find-or-create аккаунт Telegram-пользователя (`telegram_users.id`) через его telegram-identity;
     * непривязанный персонаж этого пользователя привязывается к аккаунту. Идемпотентно.
     */
    public function ensureForTelegram(int $telegramUserId): int
    {
        $row = $this->row('SELECT telegram_id FROM telegram_users WHERE id = ?', [$telegramUserId]);
        $raw = $row['telegram_id'] ?? null;
        if (! is_numeric($raw)) {
            throw new InvalidArgumentException("AccountService: telegram_users.id={$telegramUserId} not found");
        }
        $subject = (string) $raw;

        $accountId = $this->findByIdentity('telegram', $subject);
        if ($accountId === null) {
            $this->db->transBegin();
            $newId = $this->createAccount('telegram');
            if ($this->addIdentity($newId, 'telegram', $subject)) {
                $this->db->transCommit();
                $accountId = $newId;
            } else {
                // Гонка: identity успел создать параллельный запрос — берём его аккаунт.
                $this->db->transRollback();
                $accountId = $this->findByIdentity('telegram', $subject)
                    ?? throw new RuntimeException("AccountService: telegram identity {$subject} vanished");
            }
        }

        $this->db->table('characters')
            ->where('telegram_user_id', $telegramUserId)
            ->where('account_id', null)
            ->update(['account_id' => $accountId]);

        return $accountId;
    }

    /**
     * Аккаунт персонажа; для персонажа с Telegram без аккаунта — создаётся через
     * {@see ensureForTelegram()}. null — у персонажа нет ни аккаунта, ни Telegram (или его нет);
     * виртуальная строка (ADR-189) Telegram'ом не считается.
     */
    public function ensureForCharacter(int $characterId): ?int
    {
        $row = $this->row('SELECT account_id, telegram_user_id FROM characters WHERE id = ?', [$characterId]);
        if ($row === null) {
            return null;
        }
        $accountId = self::toInt($row['account_id'] ?? null);
        if ($accountId !== null) {
            return $accountId;
        }
        $telegramUserId = self::toInt($row['telegram_user_id'] ?? null);
        if ($telegramUserId === null || $this->isVirtualTelegramUser($telegramUserId)) {
            return null;
        }

        return $this->ensureForTelegram($telegramUserId);
    }

    /**
     * web-bridge-p1-01 (ADR-189 §3): строка `telegram_users` из виртуального диапазона — это
     * адрес web-only персонажа, а не Telegram. Для сессии и аккаунтов она = «Telegram нет».
     */
    public function isVirtualTelegramUser(int $telegramUserId): bool
    {
        $row = $this->row('SELECT telegram_id FROM telegram_users WHERE id = ?', [$telegramUserId]);
        $raw = $row['telegram_id'] ?? null;

        return is_numeric($raw) && VirtualChat::is((int) $raw);
    }

    public function createAccount(string $acquisitionSource): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('accounts')->insert([
            'acquisition_source' => $acquisitionSource,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $id = self::toInt($this->db->insertID());
        if ($id === null || $id <= 0) {
            throw new RuntimeException('AccountService: account insert failed');
        }

        return $id;
    }

    public function findByIdentity(string $provider, string $subject): ?int
    {
        $row = $this->row(
            'SELECT account_id FROM account_identities WHERE provider = ? AND subject = ? LIMIT 1',
            [$provider, $subject]
        );

        return self::toInt($row['account_id'] ?? null);
    }

    /**
     * false — `(provider, subject)` уже занят (любым аккаунтом) или провайдер неизвестен.
     */
    public function addIdentity(
        int $accountId,
        string $provider,
        string $subject,
        ?string $secretHash = null,
        ?string $email = null
    ): bool {
        if (! in_array($provider, self::PROVIDERS, true) || $subject === '') {
            return false;
        }
        if ($this->findByIdentity($provider, $subject) !== null) {
            return false;
        }

        try {
            $ok = $this->db->table('account_identities')->insert([
                'account_id'  => $accountId,
                'provider'    => $provider,
                'subject'     => $subject,
                'secret_hash' => $secretHash,
                'email'       => $email,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (DatabaseException) {
            // UNIQUE(provider, subject) — гонка между проверкой и вставкой.
            return false;
        }

        return $ok !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function identities(int $accountId): array
    {
        return $this->rows(
            'SELECT id, account_id, provider, subject, email, created_at, last_used_at
               FROM account_identities WHERE account_id = ? ORDER BY id',
            [$accountId]
        );
    }

    /**
     * false — это последний способ входа аккаунта или identity не принадлежит аккаунту.
     */
    public function unlinkIdentity(int $accountId, int $identityId): bool
    {
        $this->db->transBegin();
        // Блокировка строки аккаунта сериализует параллельные отвязки: иначе две одновременные
        // отвязки двух последних способов обе увидели бы count=2 и оставили аккаунт без входа.
        $this->rows('SELECT id FROM accounts WHERE id = ? FOR UPDATE', [$accountId]);

        $count = self::toInt($this->row(
            'SELECT COUNT(*) AS n FROM account_identities WHERE account_id = ?',
            [$accountId]
        )['n'] ?? null) ?? 0;

        if ($count <= 1) {
            $this->db->transRollback();

            return false;
        }

        $this->db->table('account_identities')
            ->where('id', $identityId)
            ->where('account_id', $accountId)
            ->delete();
        $deleted = $this->db->affectedRows() === 1;

        if (! $deleted) {
            $this->db->transRollback();

            return false;
        }
        $this->db->transCommit();

        return true;
    }

    /**
     * Аккаунт для входа через Telegram (виджет, апгрейд legacy-сессии): аккаунт, которому
     * принадлежит telegram-identity. null — identity нет, но персонаж этого Telegram-пользователя
     * уже сидит на аккаунте (Telegram отвязан, A3): новый пустой аккаунт не создаётся, иначе он
     * тихо заслонил бы аккаунт персонажа. Иначе — {@see ensureForTelegram()}.
     */
    public function accountForTelegramLogin(int $telegramUserId): ?int
    {
        $row = $this->row('SELECT telegram_id FROM telegram_users WHERE id = ?', [$telegramUserId]);
        $raw = $row['telegram_id'] ?? null;
        if (! is_numeric($raw)) {
            throw new InvalidArgumentException("AccountService: telegram_users.id={$telegramUserId} not found");
        }

        $accountId = $this->findByIdentity('telegram', (string) $raw);
        if ($accountId !== null) {
            return $accountId;
        }

        $attached = $this->row(
            'SELECT id FROM characters WHERE telegram_user_id = ? AND account_id IS NOT NULL LIMIT 1',
            [$telegramUserId]
        );

        return $attached !== null ? null : $this->ensureForTelegram($telegramUserId);
    }

    /**
     * P0: один персонаж на аккаунт.
     *
     * @return array<string, mixed>|null
     */
    public function characterForAccount(int $accountId): ?array
    {
        return $this->row('SELECT * FROM characters WHERE account_id = ? ORDER BY id LIMIT 1', [$accountId]);
    }

    public function attachCharacter(int $accountId, int $characterId): void
    {
        $this->db->table('characters')->where('id', $characterId)->update(['account_id' => $accountId]);
    }

    /**
     * @param list<int|string> $binds
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $binds): array
    {
        $res = $this->db->query($sql, $binds);
        if (! $res instanceof ResultInterface) {
            return [];
        }

        $out = [];
        foreach ($res->getResultArray() as $r) {
            if (is_array($r)) {
                $row = [];
                foreach ($r as $k => $v) {
                    $row[(string) $k] = $v;
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<int|string> $binds
     *
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $binds): ?array
    {
        return $this->rows($sql, $binds)[0] ?? null;
    }

    private static function toInt(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
