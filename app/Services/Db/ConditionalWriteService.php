<?php

declare(strict_types=1);

namespace App\Services\Db;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;

/**
 * exploit-fix-01 (ADR-181 §3, tracer story) — единственный дом правила «списание —
 * это условный `UPDATE` с проверкой `affectedRows`, а не чтение с последующей
 * записью». Раньше почти идентичный SQL был продублирован в
 * {@see \App\Models\BaseStorageModel::withdrawRow()} и
 * {@see \App\Services\Player\ResourcePoolService::withdrawBackpack()} — оба
 * остаются как есть (их перевод на примитив — story 05/06, волна 2), но третий
 * вызывающий и все последующие пишутся уже через этот сервис.
 *
 * Сырой `$db->query()` + `$db->affectedRows()` — не `Model::update()`: его `bool`
 * не говорит, совпал ли `WHERE`, только упал ли сам запрос.
 *
 * Примитив НЕ открывает и не завершает транзакцию — границы держит вызывающий
 * (образец — `ResourcePoolService::consume()`, который намеренно полагается на
 * транзакцию вызывающего). Внутри общее соединение CI4, поэтому сервис
 * автоматически оказывается внутри транзакции вызывающего, если она открыта.
 *
 * exploit-fix-18 — контракт внутри чужой транзакции: дубль в `insertUnique()`
 * НЕ меняет `transStatus` вызывающего (нет упавшего запроса на уровне
 * драйвера — см. докблок метода) и `Refused` не обрекает транзакцию на откат
 * в `transComplete()`. Любая другая ошибка записи (NOT NULL, FK) по-прежнему
 * пробрасывается исключением и ведёт себя как обычный упавший запрос внутри
 * транзакции. Условного `DELETE` и CAS (compare-and-swap на произвольном
 * условии) в примитиве нет — здесь только формы условного `UPDATE`
 * (`decrementIfAtLeast`, `transitionIfCurrent`), относительный `increment` и
 * условная вставка `insertUnique`; всё остальное — инлайн у вызывающих.
 */
final class ConditionalWriteService
{
    /**
     * @var BaseConnection<object, object>
     */
    private BaseConnection $db;

    /**
     * Контракт story (`docs/specs/exploit-fix/plan.md` → Contracts) называет тип
     * `?ConnectionInterface` — но интерфейс не объявляет `prefixTable()`/
     * `affectedRows()`, на которых стоит весь примитив (phpstan level 9 валит их
     * как `method.notFound`). Берём `BaseConnection` — единственный тип, который
     * их реально несёт и которым уже инжектируется соединение везде в проекте
     * (`VehicleActivationService`, `ClosestCellFinder`, `WipeService` и другие).
     *
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Списывает `$amount` из `$column` строки `$rowId`, только если в строке сейчас
     * реально есть хотя бы `$amount` — прочитанный до записи остаток не участвует
     * в решении, решает `WHERE {$column} >= ?` и `affectedRows()`.
     *
     * `$deleteWhenEmpty` существует потому, что и `character_resources`, и
     * `base_storage` не различают «нет строки» и «ноль»: нулевой остаток был бы
     * вторым представлением одного состояния.
     */
    public function decrementIfAtLeast(
        string $table,
        int $rowId,
        string $column,
        int $amount,
        bool $deleteWhenEmpty = false
    ): WriteOutcome {
        $prefixed = $this->db->prefixTable($table);

        $this->db->query(
            "UPDATE {$prefixed} SET {$column} = {$column} - ? WHERE id = ? AND {$column} >= ?",
            [$amount, $rowId, $amount]
        );

        if ($this->db->affectedRows() < 1) {
            return $this->rowExists($table, $rowId) ? WriteOutcome::Refused : WriteOutcome::Missing;
        }

        if ($deleteWhenEmpty) {
            $this->db->query("DELETE FROM {$prefixed} WHERE id = ? AND {$column} <= 0", [$rowId]);
        }

        return WriteOutcome::Applied;
    }

    /**
     * Переводит `$column` строки `$rowId` из `$from` в `$to` — только если сейчас в
     * ней действительно ещё `$from`. Тот же примитив во второй форме: резерв
     * кулдауна до выдачи лута, `status='in_work' → 'completed'`.
     */
    public function transitionIfCurrent(
        string $table,
        int $rowId,
        string $column,
        string $from,
        string $to
    ): WriteOutcome {
        $prefixed = $this->db->prefixTable($table);

        $this->db->query(
            "UPDATE {$prefixed} SET {$column} = ? WHERE id = ? AND {$column} = ?",
            [$to, $rowId, $from]
        );

        if ($this->db->affectedRows() < 1) {
            return $this->rowExists($table, $rowId) ? WriteOutcome::Refused : WriteOutcome::Missing;
        }

        return WriteOutcome::Applied;
    }

    /**
     * Относительный инкремент `$column` вместо чтения и абсолютной записи (F13):
     * `SET col = col + ?` не может потерять параллельную запись той же строки.
     * У этой операции нет предусловия сверх «строка существует», поэтому
     * `Refused` она не возвращает — только `Applied`/`Missing`.
     *
     * @param array<string,int|string> $where пары колонка => значение, склеиваются через AND
     */
    public function increment(string $table, array $where, string $column, int $amount): WriteOutcome
    {
        $prefixed            = $this->db->prefixTable($table);
        [$whereSql, $params] = $this->buildWhere($where);

        $this->db->query(
            "UPDATE {$prefixed} SET {$column} = {$column} + ? WHERE {$whereSql}",
            [$amount, ...$params]
        );

        return $this->db->affectedRows() < 1 ? WriteOutcome::Missing : WriteOutcome::Applied;
    }

    /**
     * exploit-fix-09 (ADR-181 §5), контракт внутри чужой транзакции переписан
     * в exploit-fix-18: раньше дубль ловился как MySQL 1062 (`DatabaseException`
     * при `DBDebug=true`, `query() === false` при `DBDebug=false`) — но упавший
     * запрос внутри `transStart()` вызывающего проводит через
     * `handleTransStatus()` и делает `transStatus=false` НАВСЕГДА для этой
     * транзакции: штатный `Refused`, возвращённый вызывающему, на деле
     * незаметно обрекал всю транзакцию на откат в `transComplete()`.
     *
     * Теперь дубль не порождает ошибки на уровне драйвера вовсе: вставка идёт
     * формой `INSERT … ON DUPLICATE KEY UPDATE id = id` — self-reference на
     * первичный ключ, который у дубля не меняется. `$db->foundRows` (см.
     * `app/Config/Database.php`) нигде в проекте не включён, поэтому
     * `MYSQLI_CLIENT_FOUND_ROWS` не выставляется на соединении, и MySQL
     * возвращает `affectedRows() === 0` для дубля, у которого `UPDATE` не
     * изменил ни одного значения (доказано `ConditionalWriteServiceTest`). Если
     * бы `foundRows` включили — `affectedRows()` стал бы `1` и на дубле, метод
     * сломался бы молча; тест это тоже фиксирует явно в докстроке своего кейса.
     *
     * Прочие ошибки (NOT NULL, FK и любая другая поломка записи) `ON DUPLICATE
     * KEY UPDATE` не гасит — `query()` по-прежнему упадёт исключением или
     * вернёт `false`, и метод пробрасывает это наружу как есть: глотать можно
     * только дубль, не любую поломку записи.
     *
     * До появления самого `UNIQUE`-индекса (story 10, `character_tasks` /
     * `quest_steps`) дублей физически не бывает — метод просто вставляет и
     * отдаёт `Applied`.
     *
     * `Missing` этот метод не возвращает — у вставки нет «строки нет».
     *
     * Условного `DELETE`/CAS в примитиве нет и не появится здесь — они
     * остаются инлайн у вызывающих (Non-goals story 18); это ровно две формы
     * условного `UPDATE`, разобранные выше (`decrementIfAtLeast`,
     * `transitionIfCurrent`), плюс относительный `increment` и эта условная
     * вставка.
     *
     * @param array<string,mixed> $row
     */
    public function insertUnique(string $table, array $row): WriteOutcome
    {
        $prefixed = $this->db->prefixTable($table);
        $columns  = array_keys($row);
        $sql      = "INSERT INTO {$prefixed} (" . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE id = id';

        $result = $this->db->query($sql, array_values($row));

        if ($result === false) {
            $error   = $this->db->error();
            $code    = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : 0;
            $message = isset($error['message']) ? $error['message'] : 'insertUnique: insert failed';

            throw new DatabaseException($message, $code);
        }

        return $this->db->affectedRows() < 1 ? WriteOutcome::Refused : WriteOutcome::Applied;
    }

    /**
     * Диагностический запрос, выполняется ТОЛЬКО на пути отказа (решение уже
     * принято по `affectedRows()`) — различает `Refused` от `Missing` для
     * формулировки ответа игроку.
     */
    private function rowExists(string $table, int $rowId): bool
    {
        $prefixed = $this->db->prefixTable($table);
        $result   = $this->db->query("SELECT id FROM {$prefixed} WHERE id = ? LIMIT 1", [$rowId]);
        if (!$result instanceof BaseResult) {
            return false;
        }

        return $result->getRow() !== null;
    }

    /**
     * @param array<string,int|string> $where
     * @return array{0:string,1:list<int|string>}
     */
    private function buildWhere(array $where): array
    {
        $parts  = [];
        $params = [];
        foreach ($where as $column => $value) {
            $parts[]  = "{$column} = ?";
            $params[] = $value;
        }

        return [implode(' AND ', $parts), $params];
    }
}
