<?php

declare(strict_types=1);

namespace App\Services\Web;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use RuntimeException;

/**
 * web-bridge-p1-01 (ADR-189 §3) — виртуальная строка `telegram_users` персонажа без Telegram.
 *
 * Строка несёт `telegram_id = VirtualChat::idForAccount(account_id)`; через неё web-only персонаж
 * адресуется той же цепочкой `from.id → telegram_users → characters`, что и бот-игрок.
 * Строки `account_identities` для виртуального id не создаётся никогда: это не способ входа.
 */
class VirtualIdentityService
{
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
     * Find-or-create виртуальной строки аккаунта; возвращает `telegram_users.id`. Идемпотентно.
     */
    public function ensureForAccount(int $accountId, string $firstName): int
    {
        $virtualId = VirtualChat::idForAccount($accountId);

        $existing = $this->row('SELECT id FROM telegram_users WHERE telegram_id = ? ORDER BY id LIMIT 1', [$virtualId]);
        if ($existing !== null && is_numeric($existing['id'] ?? null)) {
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('telegram_users')->insert([
            'telegram_id' => $virtualId,
            'first_name'  => $firstName === '' ? null : mb_substr($firstName, 0, 100),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $id = $this->db->insertID();
        if (! is_numeric($id) || (int) $id <= 0) {
            throw new RuntimeException("VirtualIdentityService: virtual row insert failed for account {$accountId}");
        }

        return (int) $id;
    }

    /**
     * Telegram-личность персонажа (настоящая или виртуальная) или null, если строки нет.
     *
     * @return array{telegram_user_id:int, telegram_id:int, first_name:string, username:?string, language_code:?string, virtual:bool}|null
     */
    public function identityForCharacter(int $characterId): ?array
    {
        $row = $this->row(
            'SELECT tu.* FROM characters c JOIN telegram_users tu ON tu.id = c.telegram_user_id WHERE c.id = ? LIMIT 1',
            [$characterId]
        );
        if ($row === null || ! is_numeric($row['id'] ?? null) || ! is_numeric($row['telegram_id'] ?? null)) {
            return null;
        }

        $telegramId = (int) $row['telegram_id'];

        return [
            'telegram_user_id' => (int) $row['id'],
            'telegram_id'      => $telegramId,
            'first_name'       => is_string($row['first_name'] ?? null) ? $row['first_name'] : '',
            'username'         => is_string($row['username'] ?? null) ? $row['username'] : null,
            'language_code'    => is_string($row['language_code'] ?? null) ? $row['language_code'] : null,
            'virtual'          => VirtualChat::is($telegramId),
        ];
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
