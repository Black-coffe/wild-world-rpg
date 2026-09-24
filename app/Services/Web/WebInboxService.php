<?php

declare(strict_types=1);

namespace App\Services\Web;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Config\WebPlay;

/**
 * web-bridge-p1-04 (ADR-189 §5) — входящие на сайте (`web_inbox`).
 *
 * Сюда ложатся фоновые сообщения: виртуальному персонажу (`virtual`) вместо Telegram и копия
 * привязанному игроку (`mirror`) рядом с Telegram. Хранится не больше `inboxKeep` последних строк
 * на персонажа: лишние удаляются при каждой записи (plan A7, вместо крона ADR-189 §5).
 *
 * @phpstan-import-type Msg from WebScreenStore
 */
class WebInboxService
{
    public const SOURCE_VIRTUAL = 'virtual';
    public const SOURCE_MIRROR  = 'mirror';

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    private WebPlay $config;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?WebPlay $config = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = $config ?? new WebPlay();
    }

    /**
     * @param Msg $msg
     * @return int id строки
     */
    public function append(int $characterId, array $msg, string $source): int
    {
        if (! in_array($source, [self::SOURCE_VIRTUAL, self::SOURCE_MIRROR], true)) {
            throw new \InvalidArgumentException("WebInboxService: unknown source {$source}");
        }

        $this->db->table('web_inbox')->insert([
            'character_id' => $characterId,
            'message_id'   => $msg['message_id'],
            'source'       => $source,
            'payload'      => json_encode($msg, JSON_UNESCAPED_UNICODE),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        $raw = $this->db->insertID();
        $id  = is_numeric($raw) ? (int) $raw : 0;

        $this->prune($characterId);

        return $id;
    }

    public function unreadCount(int $characterId): int
    {
        $row = $this->row(
            'SELECT COUNT(*) AS n FROM web_inbox WHERE character_id = ? AND read_at IS NULL',
            [$characterId]
        );

        return is_array($row) && is_numeric($row['n'] ?? null) ? (int) $row['n'] : 0;
    }

    /** @return list<array{msg:Msg, created_at:string, read:bool}> */
    public function latest(int $characterId, int $limit): array
    {
        $rows = $this->rows(
            'SELECT payload, created_at, read_at FROM web_inbox WHERE character_id = ? ORDER BY id DESC LIMIT ?',
            [$characterId, max(0, $limit)]
        );

        $out = [];
        foreach ($rows as $row) {
            $msg = self::decodeMsg($row['payload'] ?? null);
            if ($msg === null) {
                continue;
            }
            $created = $row['created_at'] ?? '';
            $out[]   = [
                'msg'        => $msg,
                'created_at' => is_string($created) ? $created : '',
                'read'       => ($row['read_at'] ?? null) !== null,
            ];
        }

        return $out;
    }

    public function markAllRead(int $characterId): void
    {
        $this->db->query(
            'UPDATE web_inbox SET read_at = ? WHERE character_id = ? AND read_at IS NULL',
            [date('Y-m-d H:i:s'), $characterId]
        );
    }

    /** @return Msg|null */
    public function findMessage(int $characterId, int $messageId): ?array
    {
        $row = $this->row(
            'SELECT payload FROM web_inbox WHERE character_id = ? AND message_id = ? LIMIT 1',
            [$characterId, $messageId]
        );

        return is_array($row) ? self::decodeMsg($row['payload'] ?? null) : null;
    }

    /** Есть ли кнопка с этим `callback_data` во входящих персонажа (белый список callback). */
    public function hasCallback(int $characterId, string $data): bool
    {
        $rows = $this->rows(
            'SELECT payload FROM web_inbox WHERE character_id = ?',
            [$characterId]
        );
        foreach ($rows as $row) {
            $msg = self::decodeMsg($row['payload'] ?? null);
            if ($msg !== null && WebScreenStore::hasCallback($msg, $data)) {
                return true;
            }
        }

        return false;
    }

    private function prune(int $characterId): void
    {
        $keep = max(1, $this->config->inboxKeep);
        $row  = $this->row(
            'SELECT id FROM web_inbox WHERE character_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?',
            [$characterId, $keep]
        );
        if (is_array($row) && is_numeric($row['id'] ?? null)) {
            $this->db->query(
                'DELETE FROM web_inbox WHERE character_id = ? AND id <= ?',
                [$characterId, (int) $row['id']]
            );
        }
    }

    /** @return Msg|null */
    private static function decodeMsg(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_int($decoded['message_id'] ?? null)) {
            return null;
        }

        /** @var Msg $decoded */
        return $decoded;
    }

    /**
     * @param list<int|string|null> $binds
     * @return array<string,mixed>|null
     */
    private function row(string $sql, array $binds = []): ?array
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

    /**
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        $res = $this->db->query($sql, $binds);
        if (! $res instanceof ResultInterface) {
            return [];
        }
        $out = [];
        foreach ($res->getResultArray() as $r) {
            $row = [];
            foreach ($r as $k => $v) {
                $row[(string) $k] = $v;
            }
            $out[] = $row;
        }

        return $out;
    }
}
