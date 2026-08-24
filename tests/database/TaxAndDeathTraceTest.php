<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\ActiveEventModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\EventModel;
use App\Services\Bases\BaseLifecycleService;
use App\Services\Player\Death\PlayerRespawner;
use App\TaskHandlers\TaxCollectionHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * Story chat-requests-batch-05 — «лога движения средств тоже нету и не понятно нихера»
 * (08.06.2026, Ivan Divan) / «У меня исчезло 50% ресурсов... все время был на базе»
 * (09.08.2026, Max Syskov). Два источника убыли (сбор налога, смерть) начинают писать
 * строку в `action_log`.
 *
 * `TaxCollectionHandler::handle()` гейтит запуск окном 10 минут после `taxCollectionHour`
 * реальных часов (`new DateTime()`, не seedable) — недоступно для не-flaky теста. Вместо
 * этого, как и уже существующий `tests/database/TaxCollectionPerBaseTest.php`, зовём
 * `collectBuildingTaxPerBase()` напрямую рефлексией — это call-site #4 из четырёх
 * (~стр. 517), он же единственный per-base путь и единственный, вызываемый без
 * time-gate. Остальные 3 call-site'а (~217, 279, 335 — агрегатный путь и оба ветвления
 * маяков) используют ТУ ЖЕ приватную `logTaxDeduction()` — тем же кодом, что покрыт здесь,
 * но отдельно рантайм не прогнаны (gate — существующее производственное поведение, не
 * трогается по Non-goals; см. `## Findings` story-файла).
 *
 * Ревью денежного пути (после первой сдачи story 05/11) добавил три требования, все
 * закрыты в этом файле:
 *  §1 — сумма в `description` обязана быть ПОДТВЕРЖДЁННОЙ (before−after из `adjust()`),
 *       не заказанной (`testTaxLogsConfirmedDeltaNotRequestedAmountUnderRace`).
 *  §2 — фикстура персонажа не может зависеть от того, кто создал `characters` первым в
 *       общем прогоне `tests/database` (`ensureTable()` ниже: патчит недостающие колонки
 *       ALTER'ом, а не полагается на то, что таблицы нет вообще).
 *  §3 — тест не должен дропать `action_log`, которую не создавал сам (survives-failure
 *       тесты дропают её, только если создали САМИ; иначе `markTestSkipped()`).
 *  §4 — недобор (частичная оплата) и снос постройки за неуплату тоже пишут след.
 *
 * `characters`/`telegram_users`/`character_buildings`/`action_log` — реальные имена (их
 * читают хардкодом `resolveChatIdFromTelegramUserId()`/`CharacterStatsService`/
 * `collectBuildingTaxPerBase()`, DI на них нет). Для `PlayerRespawner` — `claimed_cells`/
 * `map`/`events`, которые он же теоретически мог бы задеть, подменены изолированными
 * `tdt_*`-таблицами через DI-конструктор (модели инжектируются), чтобы не касаться
 * реальных данных вообще.
 *
 * @internal
 */
final class TaxAndDeathTraceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /**
     * §3 ревью, доследование (team-lead отклонил RENAME TABLE как обход): известный
     * пробел покрытия, не устранённый в этой story. `action_log` — не нейтральное имя:
     * `tests/database/AchievementServiceTest.php` и `tests/database/DailyTaskServiceTest.php`
     * (оба вне `## Files` этой story) безусловно дропают и пересоздают её в КАЖДОМ
     * setUp()/tearDown() (общий `TABLES`-массив вперемешку с `characters`/`game_settings`).
     * RENAME TABLE сюда/обратно уже ломался на ровно такой конкуренции за имя — see
     * `GreenhouseProductionWaterTest.php` докблок + коммит `1e05e24b` («таблица могла
     * исчезнуть под нами прямо между проверкой существования и переименованием»).
     * Симуляцию сбоя записи здесь безопасно провести МОЖНО только когда `action_log`
     * физически создал этот же тест — иначе `markTestSkipped()`.
     */
    private const ACTION_LOG_SKIP_REASON = 'action_log существует не по созданию этого теста — '
        . 'она принадлежит чужой фикстуре (см. AchievementServiceTest/DailyTaskServiceTest, '
        . 'которые её безусловно дропают/пересоздают; RENAME TABLE здесь небезопасен по прецеденту '
        . 'GreenhouseProductionWaterTest/1e05e24b). Симуляция сбоя записи в этом прогоне НЕ проведена — '
        . 'try/catch-контракт logTaxDeduction()/logTaxEvent() НЕ проверен этим запуском.';

    protected $migrate = false;

    /** @var list<string> таблицы, которые СОЗДАЛ этот тест целиком — дропаются в tearDown. */
    private array $createdTables = [];
    /** @var list<array{0:string,1:string}> [table, column], добавленные ALTER'ом к таблице, которую создал НЕ этот тест. */
    private array $addedColumns = [];
    private bool $createdActionLog = false;

    /** Пул character_id/telegram_user_id, которыми пользуется этот файл. */
    private const CHAR_IDS = [101, 102, 103, 104, 106, 107, 201, 202];
    private const TG_IDS   = [501, 601, 707];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        // `\Config\Database::connect()` (без аргумента) под ENVIRONMENT=testing тоже
        // резолвится в группу 'tests' (`Config\Database::__construct()`) — тот же
        // connection-объект, что и здесь. `TaxCollectionHandler` читает/пишет через
        // него хардкодом, и его `tableExists()`/`fieldExists()` кэшируют список таблиц
        // на объекте соединения: если ДРУГОЙ тест в этом же процессе успел его прогреть
        // раньше — кэш врёт до конца процесса. Сброс — дешёвая защита.
        $db->resetDataCache();

        // §2 ревью: фикстура персонажа НЕ должна зависеть от того, кто первым создал
        // `characters` в общем прогоне `tests/database` — другой тест-класс мог создать
        // её раньше со СВОЕЙ, более узкой схемой (реальный найденный кейс: без `gold`).
        // `ensureTable()` создаёт таблицу целиком, только если её нет вообще, и ДОБАВЛЯЕТ
        // недостающие колонки ALTER'ом, если таблица уже была — никогда не дропает и не
        // трогает существующие данные чужой/более полной схемы (CI/testbot/прод-дамп).
        $this->ensureTable($db, 'characters', [
            'telegram_user_id'  => 'INT NULL',
            'gold'              => 'INT NULL DEFAULT 0',
            'tax_unpaid_streak' => 'INT NULL DEFAULT 0',
            'cell_number'       => 'INT NULL',
            'last_respawn_at'   => 'DATETIME NULL',
            'created_at'        => 'DATETIME NULL',
            'updated_at'        => 'DATETIME NULL',
        ]);
        $this->ensureTable($db, 'telegram_users', [
            'telegram_id' => 'BIGINT NULL',
        ]);
        $this->ensureTable($db, 'character_buildings', [
            'character_id'          => 'INT NULL',
            'building_id'            => 'INT NULL',
            'map_cell_id'             => 'INT NULL',
            'tax'                     => 'INT NULL',
            'last_tax_collected'      => 'DATETIME NULL',
            'tax_collection_status'   => 'VARCHAR(16) NULL',
            'created_at'              => 'DATETIME NULL',
            'updated_at'              => 'DATETIME NULL',
        ]);
        // Локальная разреженная `wildworld_tests` уже несёт РЕАЛЬНУЮ `claimed_cells` без
        // колонки `camp_name` (устаревший дамп) — `baseNameMap()` читает её хардкодом.
        $this->ensureTable($db, 'claimed_cells', [
            'camp_name' => 'VARCHAR(255) NULL',
        ]);

        if (! $db->tableExists('action_log')) {
            // Схема 1:1 с CreateActionLogTable + ExtendActionLogStatusEnum (без FK — не
            // предмет теста, а лишняя строгость типов между ad-hoc таблицами).
            $db->query("
                CREATE TABLE action_log (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    character_id INT UNSIGNED NOT NULL,
                    chat_id BIGINT UNSIGNED NOT NULL,
                    action_name VARCHAR(255) NOT NULL,
                    action_status ENUM('Pending','Completed','Skipped','REJECTED') NOT NULL DEFAULT 'Pending',
                    description TEXT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL
                )
            ");
            $this->createdActionLog = true;
        }

        // tdt_* — изолированные таблицы для PlayerRespawner, всегда свежие (уникальный
        // префикс, ни с чем не пересекаются).
        $db->query('DROP TABLE IF EXISTS tdt_claimed_cells');
        $db->query('DROP TABLE IF EXISTS tdt_events');
        $db->query('
            CREATE TABLE tdt_claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                status VARCHAR(16) NULL,
                map_cell_id INT NULL
            )
        ');
        $db->query('
            CREATE TABLE tdt_events (
                event_id INT NULL,
                effect_type VARCHAR(32) NULL
            )
        ');
    }

    /**
     * §2 ревью — гарантирует таблицу+колонки НЕЗАВИСИМО от того, кто её создал первым.
     * Таблицы нет вообще → создаём с id PK (полностью наша, дропается в tearDown целиком).
     * Таблица уже есть (другой тест/полный дамп) → патчим ТОЛЬКО недостающие колонки
     * ALTER'ом (существующие/чужие данные не трогаем), снимаем их в tearDown поколоночно.
     *
     * @param array<string,string> $columns колонка => DDL-тип
     */
    private function ensureTable(BaseConnection $db, string $table, array $columns): void
    {
        $createdNow = false;
        if (! $db->tableExists($table)) {
            $db->query("CREATE TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY)");
            $this->createdTables[] = $table;
            $createdNow = true;
        }
        foreach ($columns as $column => $ddlType) {
            if ($createdNow) {
                // Таблица только что создана нами целиком — все колонки заведомо новые,
                // отдельно снимать их не нужно (тело таблицы уйдёт целиком в tearDown).
                $db->query("ALTER TABLE {$table} ADD COLUMN {$column} {$ddlType}");
            } elseif (! $db->fieldExists($column, $table)) {
                $db->query("ALTER TABLE {$table} ADD COLUMN {$column} {$ddlType}");
                $this->addedColumns[] = [$table, $column];
            }
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS tdt_claimed_cells');
        $db->query('DROP TABLE IF EXISTS tdt_events');

        // Точечная очистка СВОИХ строк по id-пулу — НЕЗАВИСИМО от того, создавал ли этот
        // тест саму таблицу целиком (если `character_buildings` уже существовала — её
        // никто не дропает, а без явной очистки постройки накапливаются между прогонами
        // и раздувают собранный налог следующего теста; поймано эмпирически).
        $db->table('character_buildings')->whereIn('character_id', self::CHAR_IDS)->delete();
        $db->table('characters')->whereIn('id', self::CHAR_IDS)->delete();
        $db->table('telegram_users')->whereIn('id', self::TG_IDS)->delete();

        // §3 ревью: action_log дропается ТОЛЬКО если этот тест сам её создал.
        if ($this->createdActionLog) {
            $db->query('DROP TABLE IF EXISTS action_log');
        }

        foreach (array_reverse($this->addedColumns) as [$table, $column]) {
            $db->query("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
        foreach (array_reverse($this->createdTables) as $table) {
            $db->query("DROP TABLE IF EXISTS {$table}");
        }
    }

    // ------------------------------------------------------------------
    // Фикстуры
    // ------------------------------------------------------------------

    private function seedChar(int $id, int $gold, ?int $telegramUserId = null): void
    {
        Database::connect('tests')->table('characters')->insert([
            'id' => $id, 'gold' => $gold, 'telegram_user_id' => $telegramUserId,
        ]);
    }

    private function seedTelegramUser(int $id, int $telegramId): void
    {
        Database::connect('tests')->table('telegram_users')->insert([
            'id' => $id, 'telegram_id' => $telegramId,
        ]);
    }

    private function seedBuilding(int $charId, int $cell, int $tax, string $status = 'SUCCESS', ?string $createdAt = null): void
    {
        Database::connect('tests')->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => 1, 'map_cell_id' => $cell,
            'tax' => $tax, 'tax_collection_status' => $status, 'created_at' => $createdAt ?? date('Y-m-d H:i:s'),
        ]);
    }

    private function seedActiveBase(int $charId, int $mapCellId): void
    {
        Database::connect('tests')->table('tdt_claimed_cells')->insert([
            'character_id' => $charId, 'status' => 'active', 'map_cell_id' => $mapCellId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function actionLogRows(int $characterId, string $actionName): array
    {
        $q = Database::connect('tests')->table('action_log')
            ->where('character_id', $characterId)
            ->where('action_name', $actionName)
            ->get();
        return $q === false ? [] : $q->getResultArray();
    }

    private function goldOf(int $charId): int
    {
        $q = Database::connect('tests')->table('characters')->where('id', $charId)->get();
        $row = $q === false ? null : $q->getRowArray();
        return (int) ($row['gold'] ?? -1);
    }

    /**
     * @return array{0:int,1:int,2:list<array{cell:int,name:string,tax:int,paid:int,status:string}>}
     */
    private function runPerBase(int $charId, int $gold): array
    {
        $handler = new class extends TaxCollectionHandler {
            // Заглушаем Telegram-уведомления (CI без валидного API-ключа) — не предмет теста.
            protected function sendTelegramNotification(array|\App\Entities\CharacterEntity $character, string $message): void
            {
            }
            protected function notifyCharacterById(int $characterId, string $message): void
            {
            }
        };

        $m = new ReflectionMethod(TaxCollectionHandler::class, 'collectBuildingTaxPerBase');
        $m->setAccessible(true);

        /** @var array{0:int,1:int,2:list<array{cell:int,name:string,tax:int,paid:int,status:string}>} $res */
        $res = $m->invoke($handler, $charId, $gold, new CharacterModel(), false, new BaseLifecycleService(), date('Y-m-d H:i:s'), 0);
        return $res;
    }

    /** Тот же вызов, но с явным telegram_user_id (для проверки chat_id в след-записях). */
    private function runPerBaseAs(int $charId, int $gold, int $telegramUserId): array
    {
        $handler = new class extends TaxCollectionHandler {
            protected function sendTelegramNotification(array|\App\Entities\CharacterEntity $character, string $message): void
            {
            }
            protected function notifyCharacterById(int $characterId, string $message): void
            {
            }
        };

        $m = new ReflectionMethod(TaxCollectionHandler::class, 'collectBuildingTaxPerBase');
        $m->setAccessible(true);

        return $m->invoke($handler, $charId, $gold, new CharacterModel(), false, new BaseLifecycleService(), date('Y-m-d H:i:s'), $telegramUserId);
    }

    private function respawner(): PlayerRespawner
    {
        $claimedCellModel = (new ClaimedCellModel())->setTable('tdt_claimed_cells');
        $eventModel       = (new EventModel())->setTable('tdt_events');

        return new PlayerRespawner(
            new CharacterModel(),
            $claimedCellModel,
            null, // MapModel — не задет: активная база всегда найдена в фикстурах ниже
            new ActiveEventModel(), // не задет: tdt_events всегда пуст
            $eventModel
        );
    }

    // ------------------------------------------------------------------
    // Налог — call-site #4 (collectBuildingTaxPerBase)
    // ------------------------------------------------------------------

    public function testTaxDeductionWritesActionLogTrace(): void
    {
        $this->seedChar(101, 10000, 501);
        $this->seedTelegramUser(501, 555000111);
        $this->seedBuilding(101, 100, 3000);

        $this->runPerBaseAs(101, 10000, 501);

        $rows = $this->actionLogRows(101, 'TAX_BUILDINGS');
        $this->assertCount(1, $rows, 'ровно одна строка следа на один прогон сбора');
        $this->assertSame('Completed', $rows[0]['action_status']);
        $this->assertSame(555000111, (int) $rows[0]['chat_id'], 'chat_id — telegram_id владельца, не 0/null');
        $this->assertStringContainsString('3000', (string) $rows[0]['description']);
        $this->assertStringContainsString('Налог за', (string) $rows[0]['description'], 'человеческий текст — читает экран story 06');

        $this->assertSame(7000, $this->goldOf(101), 'золото списано независимо от следа');
    }

    public function testTaxSkipsZeroAmountTrace(): void
    {
        // Здание с налогом 0 — нечего трассировать, строка не пишется.
        $this->seedChar(102, 10000, null);
        $this->seedBuilding(102, 100, 0);

        $this->runPerBase(102, 10000);

        $this->assertSame([], $this->actionLogRows(102, 'TAX_BUILDINGS'));
    }

    /**
     * §1 ревью (главное) — сумма в description обязана быть ПОДТВЕРЖДЁННОЙ (before−after
     * из `adjust()`), не заказанной. Симулируем гонку БЕЗ реального многопоточного теста:
     * `$availableGold` — параметр метода, читаемый снаружи ДО вызова (как в реальном
     * `handle()`, снимок в начале цикла персонажа); передаём его УСТАРЕВШИМ (3000), а
     * РЕАЛЬНОЕ золото в БД на момент фактического списания — уже 500 (как будто игрок
     * потратил 2500 параллельным действием между чтением и списанием). Метод, видя
     * снимок 3000 ≥ налог 3000, решает «база оплачена полностью» (SUCCESS, НЕ FAILURE —
     * его собственная бухгалтерия тут ни при чём, гонка проявляется только на шаге
     * ФАКТИЧЕСКОГO `adjust()`), но `CharacterStatsService::adjust()` читает СВЕЖЕЕ
     * золото (500) под row-lock и клампит на полу `gold>=0`: спишет только 500, а не
     * заказанные 3000. Пре-фикс код логировал `$collectedTotal`=3000 — теперь логирует
     * подтверждённые 500.
     */
    public function testTaxLogsConfirmedDeltaNotRequestedAmountUnderRace(): void
    {
        $this->seedChar(104, 500, null); // РЕАЛЬНОЕ золото на момент списания
        $this->seedBuilding(104, 100, 3000);

        // Устаревший снимок availableGold=3000 (на самом деле в БД уже 500).
        [, $collectedTotal] = $this->runPerBase(104, 3000);

        $this->assertSame(3000, $collectedTotal, 'метод посчитал базу оплачиваемой по своему (устаревшему) снимку');

        $rows = $this->actionLogRows(104, 'TAX_BUILDINGS');
        $this->assertCount(1, $rows);
        $description = (string) $rows[0]['description'];
        $this->assertStringContainsString('-500 золота', $description, 'подтверждённая (before−after) сумма');
        $this->assertStringNotContainsString('-3000', $description, 'НЕ заказанная величина — гонка её срезала');
        $this->assertSame(0, $this->goldOf(104), 'золото зажато полом >=0 (CharacterStatsService), не ушло в минус');
    }

    /** §4 ревью — недобор (частичная оплата) отличим в тексте, не только у маяков. */
    public function testTaxPartialPaymentTaggedInDescription(): void
    {
        $this->seedChar(106, 1000, null);
        $this->seedBuilding(106, 100, 3000); // налог 3000, золота реально только 1000

        $this->runPerBase(106, 1000);

        $rows = $this->actionLogRows(106, 'TAX_BUILDINGS');
        $this->assertCount(1, $rows);
        $description = (string) $rows[0]['description'];
        $this->assertStringContainsString('-1000 золота', $description, 'подтверждённая частичная сумма');
        $this->assertStringContainsString('(частично)', $description, 'недобор отличим от полной оплаты в тексте');
    }

    public function testTaxDeductionSurvivesActionLogFailure(): void
    {
        // §3 ревью: дропаем ТОЛЬКО ту action_log, которую создали сами — иначе на полной
        // схеме (CI/testbot/прод-дамп) это было бы уничтожением чужого объекта.
        if (! $this->createdActionLog) {
            $this->markTestSkipped(self::ACTION_LOG_SKIP_REASON);

            return;
        }
        Database::connect('tests')->query('DROP TABLE IF EXISTS action_log');

        $this->seedChar(103, 10000, null);
        $this->seedBuilding(103, 100, 4500);

        [$goldAfter, $collected] = $this->runPerBase(103, 10000);

        $this->assertSame(5500, $goldAfter, 'списание прошло целиком, несмотря на сбой лога');
        $this->assertSame(4500, $collected);
        $this->assertSame(5500, $this->goldOf(103), 'золото в БД действительно списано — не откат');
    }

    /**
     * §4 ревью — снос постройки за неуплату (самая болезненная потеря налогового пути)
     * тоже пишет след. Реакция `reactBaseTaxFailureLegacy()`: база уже была FAILURE в
     * прошлый прогон + снова недобор в этом → сносит новейшую постройку этой базы.
     */
    public function testTaxBuildingDemolitionWritesActionLogTrace(): void
    {
        $this->seedChar(107, 0, 707);
        $this->seedTelegramUser(707, 555000555);
        $this->seedBuilding(107, 100, 3000, 'FAILURE', '2020-01-01 00:00:00');

        $this->runPerBaseAs(107, 0, 707);

        $rows = $this->actionLogRows(107, 'TAX_BUILDING_DESTROYED');
        $this->assertCount(1, $rows, 'снос постройки за 2-й недобор подряд пишет след');
        $this->assertSame('Completed', $rows[0]['action_status']);
        $this->assertSame(555000555, (int) $rows[0]['chat_id']);
        $this->assertStringContainsString('снесено', (string) $rows[0]['description']);
    }

    // ------------------------------------------------------------------
    // Смерть — PlayerRespawner::respawn()
    // ------------------------------------------------------------------

    public function testDeathRespawnWritesActionLogTrace(): void
    {
        $this->seedChar(201, 500, 601);
        $this->seedTelegramUser(601, 555000222);
        $this->seedActiveBase(201, 777);

        $cell = $this->respawner()->respawn(201);

        $this->assertSame(777, $cell, 'респаун на клетку активной базы');

        $rows = $this->actionLogRows(201, 'DEATH_RESPAWN');
        $this->assertCount(1, $rows);
        $this->assertSame('Completed', $rows[0]['action_status']);
        $this->assertSame(555000222, (int) $rows[0]['chat_id']);
        $this->assertStringContainsString('777', (string) $rows[0]['description']);
        $this->assertStringContainsString('Смерть', (string) $rows[0]['description'], 'человеческий текст — читает экран story 06');
    }

    public function testDeathRespawnSurvivesActionLogFailure(): void
    {
        if (! $this->createdActionLog) {
            $this->markTestSkipped(self::ACTION_LOG_SKIP_REASON);

            return;
        }
        Database::connect('tests')->query('DROP TABLE IF EXISTS action_log');

        $this->seedChar(202, 0, null);
        $this->seedActiveBase(202, 888);

        $cell = $this->respawner()->respawn(202);

        $this->assertSame(888, $cell, 'респаун отработал целиком, несмотря на сбой лога');

        $q = Database::connect('tests')->table('characters')->where('id', 202)->get();
        $row = $q === false ? null : $q->getRowArray();
        $this->assertSame(888, (int) ($row['cell_number'] ?? -1), 'cell_number реально записан — не откат');
        $this->assertNotNull($row['last_respawn_at'] ?? null, 'cleanupAfterDeath отработал до попытки лога');
    }
}
