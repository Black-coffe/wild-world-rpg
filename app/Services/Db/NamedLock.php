<?php

declare(strict_types=1);

namespace App\Services\Db;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * exploit-fix-01 (ADR-181 §4, tracer story) — единственный дом именованного лока
 * MySQL/MariaDB (`GET_LOCK`/`RELEASE_LOCK`) для инвариантов, которые не выражаются
 * одним условным `UPDATE` (см. лестницу выбора в ADR-181: индекс → условная
 * запись → именованный лок, в порядке предпочтения). Раньше единственная пара
 * `acquireLock()`/`releaseLock()` во всём `app/` жила прямо в
 * `CraftShortfallBuyAction` — переезд сюда не «красивее», а конкретный: `finally`
 * с освобождением живёт внутри примитива и его нельзя забыть на call site.
 *
 * Лок — сериализатор, а не память: он запрещает одновременность, но не
 * повторность (действие, пришедшее ПОСЛЕ освобождения лока, выполнится). Если
 * нужно «не дважды», это индекс или дедуп `update_id`, а не лок.
 *
 * Правила использования (ADR-181 §4): всегда `GET_LOCK(?, 0)` non-blocking —
 * ожидание держит воркер PHP-FPM, честный отказ игроку дешевле; не более одного
 * лока на запрос (вложенность запрещена — сессия MySQL умеет держать несколько
 * именованных локов одновременно, и два имени в разном порядке у двух воркеров
 * дают взаимную блокировку).
 */
final class NamedLock
{
    /**
     * @var BaseConnection<object, object>
     */
    private BaseConnection $db;

    /**
     * Тип `BaseConnection`, не голый `ConnectionInterface` — та же причина, что
     * у {@see \App\Services\Db\ConditionalWriteService::__construct()}: только он
     * несёт методы, которыми реально пользуется этот класс.
     *
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Захватывает non-blocking именованный лок `$name` и выполняет `$fn` под ним,
     * освобождая лок в `finally` независимо от исхода `$fn`. Если лок занят другим
     * соединением — `$fn` не выполняется вовсе, а вызывающему сообщается об этом
     * через `null` (единственный способ сообщить об исходе при возврате `mixed`
     * без искажения самого результата `$fn`): вызывающий обязан отличать «лок не
     * получен» от результата `$fn` — либо `$fn` по контракту никогда не
     * возвращает `null` сам, либо вызывающий проверяет исход иначе до вызова.
     *
     * @template TResult
     * @param callable(): TResult $fn
     * @return TResult|null
     */
    public function withLock(string $name, callable $fn): mixed
    {
        if (!$this->acquire($name)) {
            return null;
        }

        try {
            return $fn();
        } finally {
            $this->release($name);
        }
    }

    private function acquire(string $name): bool
    {
        $result = $this->db->query('SELECT GET_LOCK(?, 0) AS locked', [$name]);
        if (!$result instanceof BaseResult) {
            return false;
        }
        $row = $result->getRow();

        return $row !== null && (int) $row->locked === 1;
    }

    private function release(string $name): void
    {
        $this->db->query('SELECT RELEASE_LOCK(?)', [$name]);
    }
}
