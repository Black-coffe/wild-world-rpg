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
 *
 * exploit-fix-24 — границы примитива:
 *  - `$table`/`$column` (и ключи `$where`/`$row` у `increment()`/`insertUnique()`)
 *    валидируются `^[A-Za-z_][A-Za-z0-9_]*$` до попадания в сырой SQL —
 *    `InvalidArgumentException` на невалидном имени. Значения по-прежнему идут
 *    только параметрами `query()`, это защита от иного класса дыры: вызывающего,
 *    который сам собрал имя колонки из чужого ввода.
 *  - `decrementIfAtLeast(amount: 0)` и `transitionIfCurrent(from === to)`
 *    отвергаются `InvalidArgumentException`, а не выполняются как SQL: оба —
 *    UPDATE, который не меняет значение колонки, а MySQL без
 *    `MYSQLI_CLIENT_FOUND_ROWS` на этом соединении ({@see insertUnique()})
 *    возвращает `affectedRows() === 0` для не изменившей строки — неотличимо от
 *    настоящего отказа условия. Различить «условие не выполнено» и «условие уже
 *    и так выполнено» без лишнего `SELECT` эти два примитива не могут — вызывающий
 *    обязан не звать их с no-op аргументами.
 *  - `deleteWhenEmpty`: раньше `UPDATE` и последующий `DELETE WHERE column <= 0`
 *    были двумя отдельными операторами — между ними другое соединение могло
 *    прочитать строку с нулевым остатком, которая по инварианту метода не должна
 *    существовать вовсе. Теперь при точном опустошении (`column = $amount` до
 *    вычитания, то есть после — ровно ноль) строка удаляется ОДНИМ `DELETE`, а
 *    `UPDATE` вообще не выполняется для этого случая; частичное списание
 *    по-прежнему один `UPDATE`. Ни одна операция не порождает промежуточного
 *    состояния «строка с нулём» — оно физически никогда не пишется.
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
     *
     * exploit-fix-24: `$amount === 0` бросает `InvalidArgumentException` — см.
     * докблок класса. При `$deleteWhenEmpty` точное опустошение (`$column ===
     * $amount` до вычитания) уходит одним `DELETE`, минуя промежуточный `UPDATE`
     * в ноль — строка с нулевым остатком никогда не пишется, окна для чужого
     * чтения между двумя инструкциями больше нет.
     *
     * @throws \InvalidArgumentException невалидное имя таблицы/колонки или `$amount === 0`
     */
    public function decrementIfAtLeast(
        string $table,
        int $rowId,
        string $column,
        int $amount,
        bool $deleteWhenEmpty = false
    ): WriteOutcome {
        $this->assertValidIdentifier($table);
        $this->assertValidIdentifier($column);

        if ($amount === 0) {
            throw new \InvalidArgumentException(
                'decrementIfAtLeast: $amount === 0 не имеет однозначного исхода — UPDATE, не '
                . 'меняющий значение колонки, неотличим по affectedRows() от настоящего отказа'
            );
        }

        $prefixed = $this->db->prefixTable($table);

        if ($deleteWhenEmpty) {
            $this->db->query(
                "DELETE FROM {$prefixed} WHERE id = ? AND {$column} = ?",
                [$rowId, $amount]
            );

            if ($this->db->affectedRows() >= 1) {
                return WriteOutcome::Applied;
            }
        }

        $this->db->query(
            "UPDATE {$prefixed} SET {$column} = {$column} - ? WHERE id = ? AND {$column} >= ?",
            [$amount, $rowId, $amount]
        );

        if ($this->db->affectedRows() < 1) {
            return $this->rowExists($table, $rowId) ? WriteOutcome::Refused : WriteOutcome::Missing;
        }

        return WriteOutcome::Applied;
    }

    /**
     * Переводит `$column` строки `$rowId` из `$from` в `$to` — только если сейчас в
     * ней действительно ещё `$from`. Тот же примитив во второй форме: резерв
     * кулдауна до выдачи лута, `status='in_work' → 'completed'`.
     *
     * exploit-fix-24: `$from === $to` бросает `InvalidArgumentException` — см.
     * докблок класса.
     *
     * @throws \InvalidArgumentException невалидное имя таблицы/колонки или `$from === $to`
     */
    public function transitionIfCurrent(
        string $table,
        int $rowId,
        string $column,
        string $from,
        string $to
    ): WriteOutcome {
        $this->assertValidIdentifier($table);
        $this->assertValidIdentifier($column);

        if ($from === $to) {
            throw new \InvalidArgumentException(
                'transitionIfCurrent: $from === $to не имеет однозначного исхода — UPDATE, не '
                . 'меняющий значение колонки, неотличим по affectedRows() от настоящего отказа'
            );
        }

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
     * @throws \InvalidArgumentException невалидное имя таблицы/колонки
     */
    public function increment(string $table, array $where, string $column, int $amount): WriteOutcome
    {
        $this->assertValidIdentifier($table);
        $this->assertValidIdentifier($column);

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
     * формой `INSERT … ON DUPLICATE KEY UPDATE col = col` — self-reference на
     * ПЕРВУЮ колонку переданного `$row` (exploit-fix-17: не литеральный `id` —
     * `telegram_updates_seen` не несёт суррогатного `id` вовсе, PK у неё сам
     * `update_id`; `col = col` — no-op для любой колонки любого типа, поэтому
     * замена не задевает ни один существующий вызов с суррогатным `id`), который
     * у дубля не меняется. `$db->foundRows` (см.
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
     * @throws \InvalidArgumentException невалидное имя таблицы или колонки `$row`
     */
    public function insertUnique(string $table, array $row): WriteOutcome
    {
        $this->assertValidIdentifier($table);

        $prefixed = $this->db->prefixTable($table);
        $columns  = array_keys($row);
        foreach ($columns as $column) {
            $this->assertValidIdentifier((string) $column);
        }
        // exploit-fix-17 — self-reference на ПЕРВУЮ колонку `$row`, не литеральный `id`:
        // `telegram_updates_seen` не несёт колонки `id` вовсе (PK — сам `update_id`).
        // Бэктики — колонка приходит от вызывающего, а не от игрока, но `INSERT …
        // ({$columns})` уже собирается тем же способом чуть ниже — риск не новый.
        $selfRefColumn = '`' . $columns[0] . '`';
        $sql           = "INSERT INTO {$prefixed} (" . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . " ON DUPLICATE KEY UPDATE {$selfRefColumn} = {$selfRefColumn}";

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
            $this->assertValidIdentifier((string) $column);
            $parts[]  = "{$column} = ?";
            $params[] = $value;
        }

        return [implode(' AND ', $parts), $params];
    }

    /**
     * exploit-fix-24: имена таблиц/колонок в этом сервисе интерполируются в сырой
     * SQL напрямую (значения — только параметрами `query()`). До этой story ничто
     * не мешало вызывающему передать имя, собранное из чужого ввода — валидация
     * закрывает эту границу примитива на уровне сигнатуры, а не по договорённости.
     *
     * @throws \InvalidArgumentException
     */
    private function assertValidIdentifier(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("ConditionalWriteService: невалидное имя таблицы/колонки: \"{$name}\"");
        }
    }
}
