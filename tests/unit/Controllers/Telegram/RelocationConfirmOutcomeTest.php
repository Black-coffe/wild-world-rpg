<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\BaseShiftingCommand;
use App\Services\Db\WriteOutcome;
use App\Services\Player\Relocation\RelocationTaskCreator;
use App\Services\Tasks\ActiveTasksService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;
use ReflectionClass;

/**
 * exploit-fix-12 (ADR-181 §5) — `BaseShiftingCommand::handleCallback()` перестаёт слепо
 * рапортовать успех: читает `WriteOutcome` от `RelocationTaskCreator::createTask()` (story 09)
 * и ветвится на `Applied`/`Refused`, отвечая общим текстом отказа `ActiveTasksService`.
 *
 * ⚠️ Про то, как форсится `Refused` независимо от `character_tasks` на стенде. Реальная гонка
 * story 10 — TOCTOU: два почти одновременных подтверждения проходят `checkPreconditions()` ДО
 * того, как любое из них записало строку. Последовательный двойной вызов `handleCallback()` её
 * не докажет (первый вызов физически вставляет `in_work`-строку, и ВТОРОЙ вызов
 * `checkPreconditions()` эту же строку увидит и honestly отобьётся СТАРЫМ гейтом «У вас уже идёт
 * полный переезд!», `RelocationValidator.php:87-89`, даже не дойдя до `createTask()`), а
 * коллизия внутри `insertUnique()` зависит от формы `UNIQUE` — на реальной миграции story 10 он
 * висит на generated-столбце, который у завершённых задач `NULL`, и `NULL`-ы друг с другом не
 * коллизируют. Поэтому предмет проверки этой story — ветвление ВЫЗЫВАЮЩЕГО по исходу, а не факт
 * гонки (гонку доказывает `GapAuditTest::testConfirmationCreatesOneRelocationTask`, story 10):
 * `Refused` получен подменой `BaseShiftingCommand::$taskCreator` (приватное поле, инъекция через
 * `ReflectionProperty`, паттерн уже устоялся — `ObjectDiscoveryRegistryConsistencyTest`) на
 * тест-двойник `FixedOutcomeRelocationTaskCreator`, который возвращает заданный `WriteOutcome` без
 * единого обращения к БД. Результат не зависит от того, есть ли на стенде `character_tasks` и
 * какой она формы — двойник вообще не видит эту таблицу.
 *
 * Таблицы `tasks`/`character_tasks` всё ещё нужны (своя минимальная схема, если на стенде их
 * нет) — но только чтобы `RelocationValidator::checkPreconditions()` смог прочитать их и
 * пропустить вызов дальше, до `createTask()`; никакого `UNIQUE`-трюка и предсеянных строк для
 * форсирования исхода они больше не несут.
 *
 * @internal
 */
final class RelocationConfirmOutcomeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<string> таблицы, которые СОЗДАЛ этот тест (и поэтому вправе дропнуть). */
    private array $createdTables = [];

    /** @var list<int> id персонажей, заведённых этим тестом — ключ чистки общих таблиц. */
    private array $ownCharacterIds = [];

    /** Таблица → колонка-владелец персонажа, для чистки строк в общих таблицах (НЕ созданных нами). */
    private const CHAR_LINKED = [
        'characters'      => 'id',
        'claimed_cells'   => 'character_id',
        'explored_cells'  => 'character_id',
        // `character_tasks` персистентна на этом стенде (как и `tasks`/`claimed_cells`/
        // `explored_cells`) — без построчной чистки `testAppliedOutcomeSendsTaskStartedAndCreatesOneRow`
        // оставляет `in_work`-строку на целевую ячейку навсегда, и следующий прогон того же теста
        // (та же цель X=10,Y=20) честно получает отказ RelocationValidator «туда уже переезжает
        // другой игрок» — не баг обвиняемого кода, а грязь предыдущего прогона этого же файла.
        'character_tasks' => 'character_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Нужен, чтобы App\Services\Telegram\Request коротил на фейковый ServerResponse
        // (урок feedback_taskhandler_telegram_init_in_tests).
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        // Кэш списка таблиц живёт на соединении между тестами — без сброса `tableExists()` ниже
        // может увидеть устаревшее «есть»/«нет», если другой тест репозитория только что создал
        // или дропнул одну из этих же таблиц (урок story exploit-fix-11, GapAuditTest).
        $this->db()->resetDataCache();

        // Другие тесты репозитория дропают общие таблицы (`telegram_users`, `characters`,
        // `claimed_cells`, `explored_cells`) и не восстанавливают их — story exploit-fix-13.
        // Своя минимальная схема (та же форма, что уже сверена точечно в
        // GapAuditTest/CancelQueuedCraftConditionalDeleteTest/DuplicationTest), а не рукописное
        // изобретение с нуля.
        $this->createTableIfMissing('telegram_users', '
            CREATE TABLE telegram_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL
            )
        ');
        $this->createTableIfMissing('characters', '
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                name VARCHAR(64) NULL,
                gold INT NULL DEFAULT 0,
                health DECIMAL(10,2) NULL DEFAULT 100,
                tired DECIMAL(10,2) NULL DEFAULT 100,
                cell_number INT NULL,
                biome_id INT NULL,
                level INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) AUTO_INCREMENT=' . random_int(4_000_000, 4_999_999));
        // AUTO_INCREMENT со случайного высокого значения — тот же приём, что в
        // CancelQueuedCraftConditionalDeleteTest: fallback на случай отсутствия `characters` на
        // стенде, сдвиг не даёт новому персонажу столкнуться со строками прошлых прогонов в
        // персистентных character-linked таблицах (см. CHAR_LINKED).
        $this->createTableIfMissing('claimed_cells', '
            CREATE TABLE claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                map_cell_id INT NULL,
                status VARCHAR(16) NULL DEFAULT "active",
                claimed_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('explored_cells', '
            CREATE TABLE explored_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                telegram_user_id INT NULL,
                map_cell_id INT NULL,
                biome_id INT NULL,
                character_level INT NULL,
                cell_status VARCHAR(255) NULL,
                notes TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        // `tasks`/`character_tasks` отсутствуют сегодня в общей `wildworld_tests` — своя минимальная
        // схема (та же форма, что уже сверена точечно в GapAuditTest/CancelQueuedCraftConditionalDeleteTest).
        $this->createTableIfMissing('tasks', '
            CREATE TABLE tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NULL,
                handler_key VARCHAR(64) NULL,
                name_rus VARCHAR(64) NULL,
                description VARCHAR(255) NULL,
                min_duration INT NULL,
                max_duration INT NULL,
                type VARCHAR(32) NULL,
                difficulty_level INT NULL,
                execution_limit INT NULL,
                parallel_execution_allowed TINYINT NULL DEFAULT 1,
                interruptible TINYINT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        // Никакого UNIQUE — форсирование `Refused` больше не зависит от коллизии внутри
        // `insertUnique()` (см. докблок класса), эта таблица нужна только чтобы
        // `checkPreconditions()` смог её прочитать и пропустить вызов дальше.
        $this->createTableIfMissing('character_tasks', '
            CREATE TABLE character_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                telegram_user_id INT NULL,
                task_id INT NULL,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                status VARCHAR(16) NULL,
                task_settings TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $this->enableDBDebug();
        $this->cleanCache();
    }

    protected function tearDown(): void
    {
        try {
            foreach (self::CHAR_LINKED as $table => $col) {
                if ($this->ownCharacterIds === []) {
                    continue;
                }
                try {
                    $this->db()->table($table)->whereIn($col, $this->ownCharacterIds)->delete();
                } catch (\Throwable $e) {
                    // Таблица общая и уже не наша — не мешаем соседнему воркеру фаталом здесь.
                }
            }
        } finally {
            foreach (array_reverse($this->createdTables) as $t) {
                $this->db()->query("DROP TABLE IF EXISTS {$t}");
            }
        }

        $this->cleanCache();
        parent::tearDown();
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    private function createTableIfMissing(string $table, string $ddl): void
    {
        if (! $this->db()->tableExists($table)) {
            $this->db()->query($ddl);
            $this->createdTables[] = $table;
        }
    }

    private function cleanCache(): void
    {
        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] персонаж с единственной активной базой. */
    private function seedCharacterOnSingleBase(int $baseCellId): array
    {
        $tgId = random_int(730_000_000, 739_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();

        $this->db()->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'gold' => 0, 'health' => 100, 'tired' => 100,
            'cell_number' => $baseCellId,
        ]);
        $charId = (int) $this->db()->insertID();
        $this->ownCharacterIds[] = $charId;

        $this->db()->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => $baseCellId,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active',
        ]);

        return [$tgId, $charId];
    }

    private function markExplored(int $charId, int $tgUid, int $mapCellId): void
    {
        $this->db()->table('explored_cells')->insert([
            'character_id' => $charId, 'telegram_user_id' => $tgUid, 'map_cell_id' => $mapCellId,
            'biome_id' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function cbq(int $tgId, string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_' . random_int(1, 999999999),
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1, 'date' => time(),
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'placeholder',
            ],
            'chat_instance' => 'ci_' . $tgId,
            'data' => $data,
        ]);
    }

    /**
     * `$taskCreatorDouble` — если задан, подменяет приватное поле
     * `BaseShiftingCommand::$taskCreator` через reflection ДО вызова колбэка (см. докблок
     * класса — так `Refused` форсируется без обращения к `character_tasks`).
     */
    private function handleConfirm(
        int $tgId,
        int $x,
        int $y,
        int $mapCellId,
        ?RelocationTaskCreator $taskCreatorDouble = null
    ): ServerResponse {
        $telegram = new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');
        $data     = "StartRelocationConfirm_{$x}_{$y}_{$mapCellId}";
        $command  = new BaseShiftingCommand($telegram);

        if ($taskCreatorDouble !== null) {
            $prop = (new ReflectionClass($command))->getProperty('taskCreator');
            $prop->setAccessible(true);
            $prop->setValue($command, $taskCreatorDouble);
        }

        return $command->handleCallback($this->cbq($tgId, $data));
    }

    public function testAppliedOutcomeSendsTaskStartedAndCreatesOneRow(): void
    {
        [$tgId, $charId] = $this->seedCharacterOnSingleBase(100);
        $tgUid = (int) $this->db()->table('telegram_users')->where('telegram_id', $tgId)->get()->getRowArray()['id'];
        $this->markExplored($charId, $tgUid, 500);

        $response = $this->handleConfirm($tgId, 10, 20, 500);

        $this->assertTrue($response->isOk());
        $result = $response->getResult();
        $this->assertNotNull($result);
        $this->assertSame(
            "🚀 Поехали! Переезд в X=10,Y=20 запущен.\nПродлится 24 часа.",
            $result->getText(),
            'Applied обязан по-прежнему давать прежний текст успеха'
        );

        $rows = $this->db()->table('character_tasks')
            ->where('character_id', $charId)->where('status', 'in_work')->countAllResults();
        $this->assertSame(1, (int) $rows, 'Applied обязан оставить ровно одну строку переезда');
    }

    public function testRefusedOutcomeSendsAlreadyStartedTextAndCreatesNoNewRow(): void
    {
        [$tgId, $charId] = $this->seedCharacterOnSingleBase(200);
        $tgUid = (int) $this->db()->table('telegram_users')->where('telegram_id', $tgId)->get()->getRowArray()['id'];
        $this->markExplored($charId, $tgUid, 600);

        // `Refused` форсируется тест-двойником `RelocationTaskCreator` (см. докблок класса) —
        // никакого предсеивания character_tasks и никакой зависимости от формы её UNIQUE.
        $response = $this->handleConfirm(
            $tgId,
            30,
            40,
            600,
            new FixedOutcomeRelocationTaskCreator(WriteOutcome::Refused)
        );

        $this->assertTrue($response->isOk(), 'ответ вебхука обязан остаться успешным — 500 плодит дубли');
        $result = $response->getResult();
        $this->assertNotNull($result);
        $this->assertSame(
            (new ActiveTasksService())->alreadyStartedExclusiveTaskText(),
            $result->getText(),
            'Refused обязан отвечать общим текстом отказа, не своим'
        );

        $total = $this->db()->table('character_tasks')->where('character_id', $charId)->countAllResults();
        $this->assertSame(0, (int) $total, 'Refused обязан не создавать новую строку — сообщение не должно врать');
    }
}

/**
 * Тест-двойник `RelocationTaskCreator` — возвращает заданный `WriteOutcome` без единого
 * обращения к БД, поэтому исход не зависит от наличия/формы `character_tasks` на стенде
 * (см. докблок `RelocationConfirmOutcomeTest`). Паттерн `Fake*Service extends *Service` в этом
 * же файле уже устоялся — `BuildingGateServiceTest::FakeGateService`.
 */
class FixedOutcomeRelocationTaskCreator extends RelocationTaskCreator
{
    public function __construct(private readonly WriteOutcome $outcome)
    {
    }

    public function createTask(
        int $charId,
        int $telegramUserId,
        int $frTaskId,
        int $mapCellId,
        int $targetX,
        int $targetY,
        int $sourceMapCellId = 0
    ): WriteOutcome {
        return $this->outcome;
    }
}
