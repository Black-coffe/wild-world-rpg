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
 *  - `deleteWhenEmpty` (exploit-fix-27, после ревью круга 2 M2·MAJOR №1): условный
 *    `UPDATE` списывает как обычно, затем безусловный `DELETE … WHERE id = ? AND
 *    {$column} <= 0` подчищает строку, если она после этого вызова оказалась
 *    пустой. Раньше (story 24) `DELETE` шёл ПЕРВЫМ и точным условием (`column =
 *    $amount` до вычитания) — под autocommit несовпавший `DELETE` (например, чужое
 *    параллельное списание уже сдвинуло остаток) проваливался в `UPDATE`, который
 *    доводил колонку до нуля БЕЗ последующей подчистки: постоянная нулевая строка,
 *    окно не закрыто, а перенесено. Нынешняя форма — самолечение: какая бы строка
 *    ни довела колонку до нуля (эта или чужая параллельная), её собственный
 *    хвостовой `DELETE` уходит СЛЕДОМ и убирает ноль независимо от того, кто именно
 *    его написал; выжить в БД нулевая строка не может ни при каком чередовании двух
 *    соединений (доказано `ConditionalWriteServiceTest` — тест двух соединений под
 *    autocommit). Цена — лишний `DELETE`-запрос на КАЖДЫЙ вызов с флагом (а не
 *    только на точное опустошение), это сознательный обмен: примитив не открывает
 *    транзакций (Non-goals story 27, ADR-181 §3), поэтому не может полагаться на
 *    X-lock вызывающего как единственную защиту от промежуточного чтения.
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
     * докблок класса. exploit-fix-27: отрицательный `$amount` отвергается тем же
     * исключением — `{$column} = {$column} - ?` с отрицательным `?` превращает
     * списание в начисление, а условие `{$column} >= ?` с отрицательным правым
     * операндом почти всегда истинно, то есть отказ по недостаче становится
     * практически недостижим. При `$deleteWhenEmpty` строка, доведённая ЭТИМ или
     * ЛЮБЫМ параллельным вызовом до `{$column} <= 0`, подчищается хвостовым
     * `DELETE` — см. докблок класса, раздел `deleteWhenEmpty`.
     *
     * @throws \InvalidArgumentException невалидное имя таблицы/колонки или `$amount <= 0`
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

        if ($amount <= 0) {
            throw new \InvalidArgumentException(
                'decrementIfAtLeast: $amount <= 0 не имеет однозначного исхода — при amount=0 '
                . 'UPDATE, не меняющий значение колонки, неотличим по affectedRows() от настоящего '
                . 'отказа; при amount<0 списание становится начислением, а условие "не меньше" '
                . 'почти всегда истинно'
            );
        }

        $prefixed = $this->db->prefixTable($table);

        $this->db->query(
            "UPDATE {$prefixed} SET {$column} = {$column} - ? WHERE id = ? AND {$column} >= ?",
            [$amount, $rowId, $amount]
        );

        if ($this->db->affectedRows() < 1) {
            return $this->rowExists($table, $rowId) ? WriteOutcome::Refused : WriteOutcome::Missing;
        }

        if ($deleteWhenEmpty) {
            // Самолечение (exploit-fix-27): безусловный, идемпотентный хвостовой DELETE —
            // подчищает строку, если ИМЕННО ЭТОТ UPDATE (или чужой параллельный, успевший
            // проскочить между ними) довёл колонку до нуля. Если строку уже подчистил чужой
            // такой же хвостовой DELETE — этот запрос находит 0 строк и не делает ничего.
            $this->db->query(
                "DELETE FROM {$prefixed} WHERE id = ? AND {$column} <= 0",
                [$rowId]
            );
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
     * только дубль, не любую поломку записи. exploit-fix-27: на соединениях
     * ЭТОГО проекта (`app/Config/Database.php:42,67` — `strictOn = false` для
     * групп `default` и `tests`) пропущенная `NOT NULL`-колонка БЕЗ `DEFAULT`
     * не даёт исключения вовсе — CI4 на коннекте явно снимает
     * `STRICT_TRANS_TABLES`/`STRICT_ALL_TABLES` из `sql_mode` соединения
     * ({@see \CodeIgniter\Database\MySQLi\Connection::connect()}), MySQL молча
     * подставляет implicit default (`0` у целого, `''` у строки), запрос
     * проходит, метод отдаёт `Applied`. Исключение из этого абзаца прилетит
     * только если сам вызывающий поднимет `STRICT_TRANS_TABLES` на сессии —
     * доказано `InsertUniqueContractTest` для обоих случаев явно.
     *
     * До появления самого `UNIQUE`-индекса (story 10, `character_tasks` /
     * `quest_steps`) дублей физически не бывает — метод просто вставляет и
     * отдаёт `Applied`.
     *
     * `Missing` этот метод не возвращает — у вставки нет «строки нет».
     *
     * exploit-fix-27: `ON DUPLICATE KEY UPDATE` на СУЩЕСТВУЮЩЕЙ строке — не
     * бесплатный no-op. InnoDB берёт X-lock на найденную строку до конца
     * транзакции вызывающего (как и любой `UPDATE`), И сжигает значение
     * `AUTO_INCREMENT` таблицы на каждом таком вызове (проверено на MySQL
     * 8.0.30: id 1, затем 3 — при `id INT UNSIGNED` на реалистичных объёмах
     * несущественно). Метод не предназначен для горячего пути «строка почти
     * всегда уже есть» — для него сначала `first()`/`increment()`, а
     * `insertUnique()` только на путь «строки обычно нет».
     *
     * Условного `DELETE`/CAS в примитиве нет и не появится здесь — они
     * остаются инлайн у вызывающих (Non-goals story 18); это ровно две формы
     * условного `UPDATE`, разобранные выше (`decrementIfAtLeast`,
     * `transitionIfCurrent`), плюс относительный `increment` и эта условная
     * вставка.
     *
     * @param array<string,mixed> $row
     * @throws \InvalidArgumentException невалидное имя таблицы или колонки `$row`, либо `$row === []`
     */
    public function insertUnique(string $table, array $row): WriteOutcome
    {
        $this->assertValidIdentifier($table);

        if ($row === []) {
            throw new \InvalidArgumentException(
                'insertUnique: $row не может быть пустым — self-reference ON DUPLICATE KEY UPDATE '
                . 'требует хотя бы одной колонки, а $columns[0] на пустом массиве не существует'
            );
        }

        $prefixed = $this->db->prefixTable($table);
        $columns  = array_keys($row);
        foreach ($columns as $column) {
            $this->assertValidIdentifier((string) $column);
        }
        // exploit-fix-17 — self-reference на ПЕРВУЮ колонку `$row`, не литеральный `id`:
        // `telegram_updates_seen` не несёт колонки `id` вовсе (PK — сам `update_id`).
        // exploit-fix-27 (m10): бэктики теперь и в списке колонок INSERT, и в
        // самоссылке — раньше расходилось внутри одного запроса (обе стороны уже
        // проходят assertValidIdentifier(), инъекции не было, но форма была
        // непоследовательной).
        $quotedColumns = array_map(static fn ($column): string => '`' . (string) $column . '`', $columns);
        $selfRefColumn = $quotedColumns[0];
        $sql           = "INSERT INTO {$prefixed} (" . implode(', ', $quotedColumns) . ') VALUES ('
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
