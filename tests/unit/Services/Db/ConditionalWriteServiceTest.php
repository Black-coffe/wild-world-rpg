<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Db;

use App\Database\Migrations\Adr176CreateCommunityMessagesTable;
use App\Database\Migrations\W3aCreateBaseStorage;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * exploit-fix-01 (tracer story, ADR-181 §3) — `ConditionalWriteService`: три метода,
 * общий контракт `WriteOutcome`. Схема таблиц — прогон реальных миграций на группу
 * `tests` (`base_storage` для числового `quantity`, `community_messages` для
 * строкового `status`), а не ручной `CREATE TABLE`
 * (урок `feedback_test_schema_must_come_from_migration`): расхождение с продовой
 * схемой красит зелёным поведение, которого прод не допускает.
 *
 * @internal
 */
final class ConditionalWriteServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private bool $createdBaseStorage      = false;
    private bool $createdCommunityMessage = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');

        if (! $db->tableExists('base_storage')) {
            $this->requireBaseStorageMigrationClass();
            $forge = Database::forge('tests');
            (new W3aCreateBaseStorage($forge instanceof Forge ? $forge : null))->up();
            $this->createdBaseStorage = true;
        }

        if (! $db->tableExists('community_messages')) {
            $this->requireCommunityMessagesMigrationClass();
            $forge = Database::forge('tests');
            (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->up();
            $this->createdCommunityMessage = true;
        }
    }

    protected function tearDown(): void
    {
        // exploit-fix-33: страхует тесты с Events::on('DBQuery', …) ниже — слушатель не должен
        // пережить свой тест и мешать соседним (образец — CargoDroneAutoSendChargeTest::tearDown()).
        Events::removeAllListeners('DBQuery');

        $db = Database::connect('tests');

        if ($this->createdBaseStorage) {
            $this->requireBaseStorageMigrationClass();
            $forge = Database::forge('tests');
            (new W3aCreateBaseStorage($forge instanceof Forge ? $forge : null))->down();
        } else {
            $db->table('base_storage')->truncate();
        }

        if ($this->createdCommunityMessage) {
            $this->requireCommunityMessagesMigrationClass();
            $forge = Database::forge('tests');
            (new Adr176CreateCommunityMessagesTable($forge instanceof Forge ? $forge : null))->down();
        } else {
            $db->table('community_messages')->truncate();
        }

        parent::tearDown();
    }

    private function requireBaseStorageMigrationClass(): void
    {
        if (! class_exists(W3aCreateBaseStorage::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-05-29-500000_W3aCreateBaseStorage.php';
        }
    }

    private function requireCommunityMessagesMigrationClass(): void
    {
        if (! class_exists(Adr176CreateCommunityMessagesTable::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-08-25-100000_Adr176CreateCommunityMessagesTable.php';
        }
    }

    private function insertStorageRow(int $characterId, int $resourceId, int $qty): int
    {
        $db = Database::connect('tests');
        $db->table('base_storage')->insert([
            'character_id' => $characterId,
            'resource_id'  => $resourceId,
            'quantity'     => $qty,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string,mixed>|null */
    private function storageRow(int $id): ?array
    {
        $row = Database::connect('tests')->table('base_storage')->where('id', $id)->get()->getRowArray();

        return $row;
    }

    private function insertMessageRow(int $messageId, string $status): int
    {
        $db = Database::connect('tests');
        $db->table('community_messages')->insert([
            'chat_id'          => -1001,
            'message_id'       => $messageId,
            'telegram_user_id' => 555,
            'sent_at'          => date('Y-m-d H:i:s'),
            'status'           => $status,
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string,mixed>|null */
    private function messageRow(int $id): ?array
    {
        $row = Database::connect('tests')->table('community_messages')->where('id', $id)->get()->getRowArray();

        return $row;
    }

    // ── decrementIfAtLeast: три исхода, три теста (Acceptance) ──

    public function testDecrementIfAtLeastAppliesAndDecreasesRowOnSuccess(): void
    {
        $id = $this->insertStorageRow(1, 5, 10);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 4);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame(6, (int) $this->storageRow($id)['quantity']);
    }

    public function testDecrementIfAtLeastRefusesAndLeavesRowUntouchedWhenNotEnough(): void
    {
        $id = $this->insertStorageRow(2, 5, 3);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 10);

        $this->assertSame(WriteOutcome::Refused, $outcome);
        $this->assertSame(3, (int) $this->storageRow($id)['quantity'], 'недостача не выдумывается — строка не тронута');
    }

    public function testDecrementIfAtLeastReturnsMissingWhenRowDoesNotExist(): void
    {
        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', 999999, 'quantity', 1);

        $this->assertSame(WriteOutcome::Missing, $outcome);
    }

    public function testDecrementIfAtLeastDeletesRowThatReachesZeroWhenFlagIsSet(): void
    {
        $id = $this->insertStorageRow(3, 5, 7);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 7, deleteWhenEmpty: true);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertNull($this->storageRow($id), 'нулевой остаток — не второе представление отсутствия строки');
    }

    public function testDecrementIfAtLeastKeepsZeroRowWhenFlagIsNotSet(): void
    {
        $id = $this->insertStorageRow(4, 5, 7);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 7);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame(0, (int) $this->storageRow($id)['quantity']);
    }

    /**
     * Acceptance 🔴: `amount=0` — граница примитива. Докблок класса выбирает
     * `\InvalidArgumentException`: UPDATE, не меняющий значение колонки, даёт
     * `affectedRows() === 0` неотличимо от настоящего отказа условия.
     */
    public function testDecrementIfAtLeastRejectsZeroAmount(): void
    {
        $id = $this->insertStorageRow(41, 5, 10);

        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 0);
    }

    /**
     * Acceptance 🔴 (exploit-fix-27, R2-minor m3): отрицательный `amount` превращал бы
     * списание в начисление (`quantity = quantity - (-N)`) при условии «не меньше»,
     * которое с отрицательным правым операндом почти всегда истинно — отказ по
     * недостаче становится практически недостижим. Отвергается тем же исключением,
     * что и `amount=0`.
     */
    public function testDecrementIfAtLeastRejectsNegativeAmount(): void
    {
        $id = $this->insertStorageRow(45, 5, 10);

        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', -3);
    }

    /**
     * Acceptance 🔴 (exploit-fix-27, R2-major №1 обоих ревьюеров): раньше (story 24)
     * `deleteWhenEmpty` уходил точным `DELETE ... WHERE quantity = ?` ДО `UPDATE` —
     * под autocommit несовпавший `DELETE` (чужое параллельное списание уже сдвинуло
     * остаток) проваливался в `UPDATE`, который доводил колонку до нуля БЕЗ
     * последующей подчистки: постоянная нулевая строка. Теперь `UPDATE` всегда идёт
     * первым, а хвостовой `DELETE ... WHERE quantity <= 0` — безусловный и
     * самолечащий: он подчищает строку независимо от того, ЧЬЁ соединение довело
     * колонку до нуля. Два РЕАЛЬНЫХ соединения (`Database::connect('tests', false)`,
     * не общий shared-инстанс), никаких транзакций — чистый autocommit, ровно
     * условие story: «независимо от того, открыта ли транзакция вызывающим».
     *
     * Чередование «UPDATE A → декремент B до нуля → DELETE A»: A выполняет только
     * половину-`UPDATE` своего вызова (частичное списание, не опустошает), затем B
     * успевает выполнить ПОЛНЫЙ вызов и опустошает строку сам (свой `UPDATE` +
     * свой хвостовой `DELETE`), и только затем A наконец добирается до своего
     * отложенного хвостового `DELETE` — он находит строку уже удалённой, идемпотентный
     * no-op. Нулевая строка нигде не задерживается.
     */
    public function testDecrementIfAtLeastDeleteWhenEmptySelfHealsWhenForeignConnectionDrainsFirst(): void
    {
        $id = $this->insertStorageRow(46, 5, 7);

        $dbA = Database::connect('tests', false);
        $dbB = Database::connect('tests', false);

        // A: только UPDATE-половина decrementIfAtLeast(amount: 3, deleteWhenEmpty: true) —
        // собственный хвостовой DELETE придерживаем, чтобы вклиниться соединением B.
        $dbA->query(
            'UPDATE base_storage SET quantity = quantity - ? WHERE id = ? AND quantity >= ?',
            [3, $id, 3]
        );
        $this->assertSame(1, $dbA->affectedRows());
        $this->assertSame(4, (int) $this->storageRow($id)['quantity']);

        // B: полный decrementIfAtLeast(amount: 4, deleteWhenEmpty: true) — опустошает и
        // подчищает строку целиком сам, пока хвостовой DELETE A ещё не выполнен.
        $outcomeB = (new ConditionalWriteService($dbB))
            ->decrementIfAtLeast('base_storage', $id, 'quantity', 4, deleteWhenEmpty: true);
        $this->assertSame(WriteOutcome::Applied, $outcomeB);
        $this->assertNull($this->storageRow($id), 'B обязан был опустошить и удалить строку сам');

        // Отложенный хвостовой DELETE A выполняется последним — строки уже нет.
        $dbA->query('DELETE FROM base_storage WHERE id = ? AND quantity <= 0', [$id]);
        $this->assertSame(
            0,
            $dbA->affectedRows(),
            'хвостовой DELETE A обязан быть идемпотентным no-op — строку уже удалило чужое соединение'
        );

        $this->assertNull($this->storageRow($id), 'нулевая строка не пережила это чередование двух соединений');
    }

    /**
     * Acceptance 🔴 (exploit-fix-33, R3-major): два теста выше собирали чередование
     * ВРУЧНУЮ — сырыми SQL-половинками через `dbA`/`dbB`, не вызывая
     * `decrementIfAtLeast()` вовсе на стороне A и не различая текущую форму
     * (UPDATE, затем хвостовой самолечащий DELETE) от старой (exploit-fix-24: точный
     * `DELETE … WHERE column = $amount` ПЕРВЫМ, `UPDATE`-фолбэк без подчистки) —
     * старый тест на этих числах (`amount` B точно равен остатку после A) давал
     * `Applied` на ОБЕИХ формах одинаково.
     *
     * Этот тест перехватывает ПЕРВЫЙ SQL-оператор РЕАЛЬНОГО вызова
     * `decrementIfAtLeast()` через `Events::on('DBQuery', …)` (образец —
     * `CargoDroneAutoSendChargeTest::testConcurrentChargeDrainRefusesSecondLaunchAndRollsBackDelivery`,
     * перехват WRITE-, а не READ-запроса — тот же приём) и вклинивает МЕЖДУ ним и
     * вторым оператором ТОГО ЖЕ вызова чужое списание с отдельного, реально не
     * разделяемого соединения (`Database::connect('tests', false)`, автокоммит, без
     * транзакций). Фильтр ловит и `UPDATE`, и `DELETE` — какой из них форма шлёт
     * первым, тесту знать не нужно; `$fired` гарантирует ровно одно срабатывание,
     * чтобы запись конкурента (тоже `UPDATE … base_storage`) не вызвала
     * перехватчик рекурсивно.
     *
     * Текущая форма (доказывается зелёным): `UPDATE` списывает 3 из 7 → 4,
     * перехватчик срабатывает, конкурент списывает оставшиеся 4 → 0 своим
     * отдельным условным `UPDATE`, затем безусловный хвостовой `DELETE` реального
     * вызова находит `quantity <= 0` и убирает строку — `Applied`, строки больше
     * нет.
     *
     * Старая форма (exploit-fix-24, доказывается красным — см. `## Implementation
     * notes` story exploit-fix-33: временная ручная правка на месте, SHA256
     * до/после, восстановлено вручную без `git checkout/stash`): точный `DELETE
     * WHERE quantity = 3` не совпадает (остаток ещё 7) → 0 затронутых строк,
     * перехватчик срабатывает ПОСЛЕ этого DELETE, конкурент списывает 4 из 7 → 3,
     * и только ПОТОМ старая форма проваливается в `UPDATE SET quantity = quantity
     * - 3 WHERE quantity >= 3` — на текущих 3 условие проходит, остаток становится
     * 0, но хвостовой подчистки в старой форме нет вовсе: нулевая строка
     * переживает вызов, и `assertNull()` ниже красит тест.
     */
    public function testDecrementIfAtLeastDeleteWhenEmptySelfHealsWhenForeignWriteInterleavesWithTheRealCallsOwnStatements(): void
    {
        $id = $this->insertStorageRow(47, 5, 7);

        $concurrentDb = Database::connect('tests', false);
        $fired        = false;

        Events::on('DBQuery', function (Query $query) use ($id, $concurrentDb, &$fired): void {
            if ($fired) {
                return;
            }

            $sql                     = $query->getOriginalQuery();
            $isFirstStatementOfCall = str_contains($sql, 'base_storage')
                && (str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE'));
            if (! $isFirstStatementOfCall) {
                return;
            }

            $fired = true;

            // Чужое списание вклинивается между первым и вторым оператором реального
            // вызова — отдельное соединение, автокоммит, никаких транзакций.
            $concurrentDb->query(
                'UPDATE base_storage SET quantity = quantity - ? WHERE id = ? AND quantity >= ?',
                [4, $id, 4]
            );
        });

        try {
            $outcome = (new ConditionalWriteService(Database::connect('tests')))
                ->decrementIfAtLeast('base_storage', $id, 'quantity', 3, deleteWhenEmpty: true);
        } finally {
            Events::removeAllListeners('DBQuery');
            $concurrentDb->close();
        }

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertNull(
            $this->storageRow($id),
            'чужое списание до нуля между двумя операторами реального вызова не должно оставить нулевую строку'
        );
    }

    // ── валидация имён таблиц/колонок ──

    public function testDecrementIfAtLeastRejectsInvalidTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage; DROP TABLE base_storage', 1, 'quantity', 1);
    }

    public function testDecrementIfAtLeastRejectsInvalidColumnName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->decrementIfAtLeast('base_storage', 1, 'quantity = 0 -- ', 1);
    }

    public function testIncrementRejectsInvalidWhereColumnName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->increment('base_storage', ['character_id; DROP TABLE base_storage' => 1], 'quantity', 1);
    }

    public function testInsertUniqueRejectsInvalidRowColumnName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->insertUnique('community_messages', ['chat_id; DROP TABLE community_messages' => -1001]);
    }

    /**
     * Acceptance 🔴 (exploit-fix-27, R2-minor m9 ревьюера 2): `$columns[0]` на пустом
     * `$row` — `Warning` и пустые бэктики в SQL, а не однозначный отказ. Пустой `$row`
     * не имеет смысла для self-reference `ON DUPLICATE KEY UPDATE` — отвергается
     * `InvalidArgumentException` до попадания в SQL.
     */
    public function testInsertUniqueRejectsEmptyRow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->insertUnique('community_messages', []);
    }

    // ── transitionIfCurrent ──

    public function testTransitionIfCurrentAppliesWhenStatusMatches(): void
    {
        $id = $this->insertMessageRow(101, 'new');

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->transitionIfCurrent('community_messages', $id, 'status', 'new', 'answered');

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame('answered', $this->messageRow($id)['status']);
    }

    public function testTransitionIfCurrentRefusesWhenStatusAlreadyMoved(): void
    {
        $id = $this->insertMessageRow(102, 'answered');

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->transitionIfCurrent('community_messages', $id, 'status', 'new', 'ignored');

        $this->assertSame(WriteOutcome::Refused, $outcome);
        $this->assertSame('answered', $this->messageRow($id)['status'], 'чужой переход не должен затирать статус');
    }

    public function testTransitionIfCurrentReturnsMissingWhenRowDoesNotExist(): void
    {
        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->transitionIfCurrent('community_messages', 999999, 'status', 'new', 'answered');

        $this->assertSame(WriteOutcome::Missing, $outcome);
    }

    /**
     * Acceptance 🔴: `from === to` — граница примитива, тот же класс дефекта, что
     * `amount=0` у `decrementIfAtLeast()`. Докблок класса выбирает
     * `\InvalidArgumentException`.
     */
    public function testTransitionIfCurrentRejectsSameFromAndTo(): void
    {
        $id = $this->insertMessageRow(103, 'new');

        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->transitionIfCurrent('community_messages', $id, 'status', 'new', 'new');
    }

    // ── increment ──

    public function testIncrementAppliesRelativeDeltaOnMatchingRow(): void
    {
        $id = $this->insertStorageRow(6, 9, 5);

        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->increment('base_storage', ['character_id' => 6, 'resource_id' => 9], 'quantity', 3);

        $this->assertSame(WriteOutcome::Applied, $outcome);
        $this->assertSame(8, (int) $this->storageRow($id)['quantity']);
    }

    public function testIncrementReturnsMissingWhenWhereMatchesNoRow(): void
    {
        $outcome = (new ConditionalWriteService(Database::connect('tests')))
            ->increment('base_storage', ['character_id' => 777, 'resource_id' => 888], 'quantity', 1);

        $this->assertSame(WriteOutcome::Missing, $outcome);
    }

    /**
     * Acceptance 🔴 (exploit-fix-33, R3-major m2): граница `increment()`, тот же класс
     * дефекта, что `amount=0` у `decrementIfAtLeast()` — `col = col + 0` неотличим по
     * `affectedRows()` от настоящего отказа. Докблок метода выбирает
     * `\InvalidArgumentException`.
     */
    public function testIncrementRejectsZeroAmount(): void
    {
        $id = $this->insertStorageRow(48, 5, 10);

        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->increment('base_storage', ['id' => $id], 'quantity', 0);
    }

    /**
     * Acceptance 🔴 (exploit-fix-33, R3-major m2): отрицательный `amount` превращает
     * инкремент в декремент без единой проверки достатка — `increment()` её
     * сознательно не делает (для этого есть `decrementIfAtLeast()`). Отвергается тем
     * же исключением, что и `amount=0`.
     */
    public function testIncrementRejectsNegativeAmount(): void
    {
        $id = $this->insertStorageRow(49, 5, 10);

        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->increment('base_storage', ['id' => $id], 'quantity', -2);
    }

    /**
     * Acceptance 🔴 (exploit-fix-33, R3-major m2): пустой `$where` собрал бы `UPDATE`
     * без единого условия (`buildWhere([])` даёт пустую строку) — задело бы КАЖДУЮ
     * строку таблицы, а не одну.
     */
    public function testIncrementRejectsEmptyWhere(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConditionalWriteService(Database::connect('tests')))
            ->increment('base_storage', [], 'quantity', 1);
    }

    // ── insertUnique внутри чужой транзакции (exploit-fix-18) ──

    /** @return array<string,mixed> */
    private function communityMessageRow(int $messageId): array
    {
        return [
            'chat_id'          => -1001,
            'message_id'       => $messageId,
            'telegram_user_id' => 555,
            'sent_at'          => date('Y-m-d H:i:s'),
            'status'           => 'new',
        ];
    }

    /**
     * Acceptance 🔴: раньше `insertUnique()` ловил дубль как MySQL 1062 через
     * `DatabaseException`/`query() === false` — упавший на уровне драйвера
     * запрос внутри `transStart()` вызывающего проводит через
     * `handleTransStatus()` и делает `transStatus=false` НАВСЕГДА для этой
     * транзакции, даже когда вызывающий получает штатный `Refused` и решает
     * продолжать. Теперь дубль идёт формой `INSERT … ON DUPLICATE KEY UPDATE
     * id = id` — запрос не падает вовсе, `transStatus` дубль не трогает.
     */
    public function testInsertUniqueDuplicateInsideForeignTransactionDoesNotPoisonTransStatusAndCommits(): void
    {
        $db = Database::connect('tests');
        $db->resetTransStatus();
        $service = new ConditionalWriteService($db);

        $db->transStart();

        $first  = $service->insertUnique('community_messages', $this->communityMessageRow(301));
        $second = $service->insertUnique('community_messages', $this->communityMessageRow(301));

        $this->assertSame(WriteOutcome::Applied, $first);
        $this->assertSame(WriteOutcome::Refused, $second);
        $this->assertTrue(
            $db->transStatus(),
            'дубль внутри чужой транзакции не должен переводить transStatus в false'
        );

        // соседняя запись той же транзакции — доказывает, что transComplete() не откатит её
        $neighborId = $this->insertMessageRow(302, 'new');

        $db->transComplete();

        $this->assertTrue($db->transStatus(), 'transComplete() обязан закоммитить, а не откатить, транзакцию');
        $this->assertSame(
            1,
            (int) $db->table('community_messages')->where(['chat_id' => -1001, 'message_id' => 301])->countAllResults(),
            'дубль не должен был породить вторую строку'
        );
        $this->assertNotNull($this->messageRow($neighborId), 'соседняя запись той же транзакции обязана пережить commit');
    }

    /**
     * Acceptance 🔴: `ON DUPLICATE KEY UPDATE id = id` не гасит другие ошибки
     * записи — только дубль по УНИКАЛЬНОМУ ключу (`ON DUPLICATE KEY UPDATE`
     * ловит и `PRIMARY`, и любой secondary `UNIQUE`, но не FK-нарушение — оно
     * другой категории и InnoDB его проверяет независимо от `sql_mode`, в
     * отличие от `NOT NULL`: на этом соединении `sql_mode` не несёт
     * `STRICT_TRANS_TABLES`, и пропущенный `NOT NULL`-столбец без default тихо
     * получает MySQL-дефолт вместо ошибки — FK честно доказывает «другая
     * ошибка» независимо от режима строгости). Временная таблица со
     * scratch-FK: вставка с несуществующим `character_id` обязана пробросить
     * исключение, а не `Refused`.
     *
     * exploit-fix-24: FK-родитель — своя scratch-таблица
     * `insert_unique_fk_scratch_parent`, созданная и удалённая этим же тестом,
     * а не чужая `characters` — на общем стенде `wildworld_tests` `characters`
     * не гарантированно существует (03.09.2026 тест падал `Failed to open the
     * referenced table 'characters'`, когда соседний тест её снёс).
     */
    public function testInsertUniqueThrowsOnForeignKeyViolationInsteadOfRefusing(): void
    {
        $db = Database::connect('tests');
        $db->query(
            'CREATE TABLE insert_unique_fk_scratch_parent ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            . ') ENGINE=InnoDB'
        );
        $db->query(
            'CREATE TABLE insert_unique_fk_scratch ('
            . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
            . 'character_id INT UNSIGNED NOT NULL,'
            . 'slot VARCHAR(32) NOT NULL,'
            . 'UNIQUE KEY uq_character_slot (character_id, slot),'
            . 'CONSTRAINT fk_insert_unique_fk_scratch_character FOREIGN KEY (character_id) '
            . 'REFERENCES insert_unique_fk_scratch_parent (id)'
            . ') ENGINE=InnoDB'
        );

        try {
            $this->expectException(DatabaseException::class);

            (new ConditionalWriteService($db))->insertUnique('insert_unique_fk_scratch', [
                'character_id' => 999999999,
                'slot'         => 'head',
            ]);
        } finally {
            $db->query('DROP TABLE IF EXISTS insert_unique_fk_scratch');
            $db->query('DROP TABLE IF EXISTS insert_unique_fk_scratch_parent');
        }
    }

    /**
     * Acceptance 🔴: `affectedRows()` на дубле — `0`, а не `1` (был бы `1`, если
     * бы соединение несло `MYSQLI_CLIENT_FOUND_ROWS`; `Config\Database::$foundRows`
     * нигде в проекте не включён — доказано напрямую на соединении `tests`).
     */
    public function testInsertUniqueDuplicateLeavesZeroAffectedRowsOnConnection(): void
    {
        $db      = Database::connect('tests');
        $service = new ConditionalWriteService($db);

        $service->insertUnique('community_messages', $this->communityMessageRow(303));
        $outcome = $service->insertUnique('community_messages', $this->communityMessageRow(303));

        $this->assertSame(WriteOutcome::Refused, $outcome);
        $this->assertSame(
            0,
            $db->affectedRows(),
            'дубль обязан оставлять 0 affectedRows на этом соединении — иначе foundRows включён и метод сломан'
        );
    }

    /**
     * exploit-fix-17 — до этой story `insertUnique()` жёстко ссылался на литеральную
     * колонку `id` в `ON DUPLICATE KEY UPDATE id = id`. `telegram_updates_seen`
     * (ADR-181) намеренно не несёт суррогатного `id` — PK у неё сам `update_id`.
     * Временная таблица здесь воспроизводит ровно эту форму (PK — содержательная
     * колонка, никакого `id` в схеме), чтобы доказать, что self-reference теперь
     * идёт по ПЕРВОЙ колонке `$row`, а не по литералу `id`, который на этой схеме
     * дал бы `Unknown column 'id'` даже на первой вставке (проверено вручную до
     * фикса — `DatabaseException` на обоих вызовах, дедуп не отличим от «не
     * дубль»). `transStatus` внутри чужой транзакции остаётся нетронутым — тот же
     * контракт exploit-fix-18, теперь и для таблиц без суррогатного `id`.
     */
    public function testInsertUniqueWorksOnTableWithoutIdColumnAndDoesNotPoisonTransStatus(): void
    {
        $db = Database::connect('tests');
        $db->query(
            'CREATE TABLE insert_unique_no_id_scratch ('
            . 'update_id BIGINT UNSIGNED PRIMARY KEY,'
            . 'created_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB'
        );

        try {
            $db->resetTransStatus();
            $service = new ConditionalWriteService($db);
            $row     = ['update_id' => 910099, 'created_at' => date('Y-m-d H:i:s')];

            $db->transStart();

            $first  = $service->insertUnique('insert_unique_no_id_scratch', $row);
            $second = $service->insertUnique('insert_unique_no_id_scratch', $row);

            $this->assertSame(WriteOutcome::Applied, $first, 'первая вставка на схеме без id обязана пройти');
            $this->assertSame(WriteOutcome::Refused, $second, 'повтор PK обязан распознаваться как дубль, а не падать на Unknown column id');
            $this->assertTrue(
                $db->transStatus(),
                'дубль на таблице без id не должен переводить transStatus в false'
            );

            $db->transComplete();

            $this->assertTrue($db->transStatus(), 'transComplete() обязан закоммитить, а не откатить, транзакцию');
            $this->assertSame(
                1,
                (int) $db->table('insert_unique_no_id_scratch')->where('update_id', 910099)->countAllResults(),
                'дубль не должен был породить вторую строку'
            );
        } finally {
            $db->query('DROP TABLE IF EXISTS insert_unique_no_id_scratch');
        }
    }
}
