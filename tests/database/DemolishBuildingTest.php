<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Camp\DemolishBuildingAction;
use App\Controllers\Telegram\Commands\Actions\Camp\DemolishBuildingConfirmAction;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * chat-requests-batch-07 — «Снос одной постройки» (Ярик, 14.06.2026: «настроил
 * лишних думая что бонус добычи будет», «сломать можно лишние?»).
 *
 * До этой story убрать ОДНУ ошибочную постройку было нельзя вообще — снос был
 * только «всей базой» (`DeleteBaseAction`). Тесты бьют по реальному `handle()`
 * трёх экранов (список → подтверждение → исполнение), все они текстовые
 * (`Request::sendMessage`, без фото) — фотографического сетевого ограничения
 * story 04/10 здесь нет, весь путь прогоняется end-to-end.
 *
 * Схема разреженная: `claimed_cells`/`characters`/`telegram_users`/`character_buildings`/
 * `buildings`/`action_log` создаются под `tableExists()`-гардом (создаём только если
 * отсутствует — соседние тесты вроде `VehicleCraftWiringTest` временно DROP+CREATE
 * общие таблицы под свою изолированную схему, и к моменту запуска этого теста
 * `claimed_cells` может не существовать) и удаляются в tearDown() только если
 * создавались этим тестом.
 *
 * @internal
 */
final class DemolishBuildingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    /** @var list<string> таблицы, созданные ЭТИМ тестом — только их и дропаем. */
    private array $createdTables = [];

    private int $telegramUserId = 0;
    private int $characterId    = 0;
    private int $cellNumber     = 0;
    private int $claimedCellId  = 0;
    private int $buildingTypeId = 0;

    /**
     * 🔴 Найдено при массовой гонке на общей БД в этой сессии: фиксированный
     * `telegram_id` через все прогоны собирал «Пользователь или персонаж не
     * найден» — если ПРЕДЫДУЩИЙ прогон свалился на гонке ДО tearDown() (что при
     * такой нагрузке было НОРМОЙ), в `telegram_users` копились чужие строки со
     * ЗНАЧЕНИЕМ ЭТОГО ЖЕ `telegram_id`, `first()` без `orderBy` (db-schema.md)
     * находил ЛЮБУЮ из них, а не мою свежую — и подходящего `character` для
     * НЕЙ не было. Случайный `tgId` на каждый прогон убирает саму возможность
     * столкновения с осиротевшими строками прошлых прогонов.
     */
    private int $tgId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

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
                cell_number INT NULL,
                level INT NOT NULL DEFAULT 1
            )
        ');
        // Соседние тесты (`VehicleCraftWiringTest` и др.) временно DROP+CREATE общие
        // таблицы под свою изолированную схему — гвард спасает воспроизводимость
        // независимо от порядка/параллельности прогона (см. story 10 `## Findings`).
        $this->createTableIfMissing('claimed_cells', '
            CREATE TABLE claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                map_cell_id INT NOT NULL,
                status VARCHAR(50) NOT NULL,
                claimed_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('buildings', '
            CREATE TABLE buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_ru VARCHAR(150) NULL,
                name_en VARCHAR(150) NULL
            )
        ');
        $this->createTableIfMissing('character_buildings', '
            CREATE TABLE character_buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                building_id INT NULL,
                map_cell_id INT NULL,
                level INT NULL DEFAULT 1,
                tax INT NULL,
                amount INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('action_log', "
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
        // 🔴 Найдено при отладке ложных «Не получилось снести»: без этой таблицы
        // ЛЮБОЙ вызов `GameSettingsService::get()` (например, `cooldownRemainingMinutes()`
        // ВНУТРИ транзакции `execute()`) шлёт запрос к несуществующей `game_settings`.
        // Сервис сам ловит исключение и отдаёт default — но CI4 к этому моменту УЖЕ
        // пометил `transStatus` соединения как FALSE («The query() function will set
        // this flag to FALSE in the event that a query failed», BaseConnection.php) —
        // это флаг СОЕДИНЕНИЯ, а не try/catch вызывающего кода. `transComplete()`
        // видит «была неудача» и откатывает ВСЮ транзакцию, включая уже успешные
        // UPDATE/DELETE — ответ выглядит успешным текстом, а в БД ничего не менялось.
        // Таблица должна существовать, чтобы штатный путь чтения не порождал
        // ошибку на уровне соединения вовсе.
        $this->createTableIfMissing('game_settings', '
            CREATE TABLE game_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(191) NOT NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float DECIMAL(15,5) NULL,
                value_bool TINYINT NULL,
                value_string TEXT NULL,
                default_value_text TEXT NULL,
                rationale_text TEXT NULL,
                effect_text TEXT NULL,
                above_effect_text TEXT NULL,
                below_effect_text TEXT NULL,
                recommended_min VARCHAR(64) NULL,
                recommended_max VARCHAR(64) NULL,
                hard_min VARCHAR(64) NULL,
                hard_max VARCHAR(64) NULL,
                updated_by VARCHAR(128) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');

        $this->tgId = random_int(600_000_000, 699_999_999);
        $this->conn->table('telegram_users')->insert(['telegram_id' => $this->tgId]);
        $this->telegramUserId = (int) $this->conn->insertID();

        $this->cellNumber = 424242;

        $this->conn->table('characters')->insert([
            'telegram_user_id' => $this->telegramUserId,
            'cell_number'      => $this->cellNumber,
        ]);
        $this->characterId = (int) $this->conn->insertID();

        $this->conn->table('claimed_cells')->insert([
            'character_id' => $this->characterId,
            'map_cell_id'  => $this->cellNumber,
            'status'       => 'active',
        ]);
        $this->claimedCellId = (int) $this->conn->insertID();

        $this->conn->table('buildings')->insert(['name_ru' => 'Скважина', 'name_en' => 'Borehole']);
        $this->buildingTypeId = (int) $this->conn->insertID();
    }

    protected function tearDown(): void
    {
        $this->conn->table('claimed_cells')->where('id', $this->claimedCellId)->delete();
        $this->conn->table('buildings')->where('id', $this->buildingTypeId)->delete();
        $this->conn->table('character_buildings')->where('character_id', $this->characterId)->delete();
        $this->conn->table('action_log')->where('character_id', $this->characterId)->delete();
        $this->conn->table('characters')->where('id', $this->characterId)->delete();
        $this->conn->table('telegram_users')->where('id', $this->telegramUserId)->delete();

        foreach (array_reverse($this->createdTables) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }

        parent::tearDown();
    }

    private function createTableIfMissing(string $table, string $ddl): void
    {
        if (! $this->conn->tableExists($table)) {
            $this->conn->query($ddl);
            $this->createdTables[] = $table;
        }
    }

    /** @return int id строки character_buildings */
    private function seedBuilding(int $tax = 10, int $level = 1, int $amount = 1): int
    {
        $this->conn->table('character_buildings')->insert([
            'character_id' => $this->characterId,
            'building_id'  => $this->buildingTypeId,
            'map_cell_id'  => $this->cellNumber,
            'level'        => $level,
            'tax'          => $tax,
            'amount'       => $amount,
        ]);

        return (int) $this->conn->insertID();
    }

    private function buildingRowCount(): int
    {
        return (int) $this->conn->table('character_buildings')->where('character_id', $this->characterId)->countAllResults();
    }

    private function amountOf(int $rowId): ?int
    {
        $row = $this->conn->table('character_buildings')->where('id', $rowId)->get()->getRowArray();

        return $row === null ? null : (int) $row['amount'];
    }

    /** Кулдаун-запись «в прошлом» — обходит гейт для тестов, которым сам кулдаун не интересен. */
    private function ageLastDemolishLog(int $minutesAgo): void
    {
        $this->conn->table('action_log')
            ->where('character_id', $this->characterId)
            ->where('action_name', 'DEMOLISH_BUILDING')
            ->set('created_at', date('Y-m-d H:i:s', time() - $minutesAgo * 60))
            ->update();
    }

    // ── 1) Список: дубли того же типа на той же базе — ОДНА строка со стеком (реальная модель игры) ──

    /**
     * Ревью 24.08.2026 (BLOCK): вторая постройка ТОГО ЖЕ типа на ТОЙ ЖЕ базе не создаёт
     * новую строку — `GenericBuildingCompletionHandler::updateCharacterBuildings()`
     * инкрементит `amount` на существующей. Раньше этот тест сеял ДВЕ сырые строки —
     * состояние, которого игра не создаёт, — и был зелёным на несуществующем сценарии.
     * Реальный сценарий: одна строка, `amount=3`, список обязан назвать фактическое
     * количество, а не подсунуть одну кнопку без указания «это стек».
     */
    public function testDemolishListShowsStackedAmountNotSeparateRows(): void
    {
        $id = $this->seedBuilding(15, 1, 3); // одна строка, стек из 3

        $response = (new DemolishBuildingAction($this->callbackQuery('demolishBuilding')))->handle();
        $this->assertTrue($response->isOk());

        $text      = $this->responseText($response);
        $callbacks = array_column($this->flattenButtons($response), 'callback_data');

        $this->assertStringContainsString('Скважина', $text);
        $this->assertStringContainsString('×3', $text, 'список обязан называть фактическое количество построек в стеке');
        $this->assertContains("demolishBuildingConfirm_{$id}", $callbacks);
        $this->assertCount(1, array_filter($callbacks, static fn (string $c): bool => str_starts_with($c, 'demolishBuildingConfirm_')), 'на стек — ОДНА кнопка, не по кнопке на штуку');
    }

    // ── 2) Подтверждение: ресурсы не возвращаются, есть путь назад ──

    public function testConfirmScreenWarnsResourcesAreNotReturned(): void
    {
        $id1 = $this->seedBuilding(15);
        $this->seedBuilding(15); // вторая постройка — эта НЕ последняя на базе

        $response = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingConfirm_{$id1}")))->handle();
        $this->assertTrue($response->isOk());

        $text = $this->responseText($response);
        $this->assertStringContainsString('не возвращаются', $text);

        $callbacks = array_column($this->flattenButtons($response), 'callback_data');
        $this->assertContains("demolishBuildingGo_{$id1}", $callbacks);
        $this->assertContains('demolishBuilding', $callbacks, 'обязана быть кнопка отмены назад в список');
    }

    // ── 3) Последняя постройка на базе — снос базы, не подстановка DeleteBaseAction ──

    public function testLastBuildingOnBaseRedirectsToDeleteBaseInsteadOfDemolishing(): void
    {
        $id1 = $this->seedBuilding(15); // единственная постройка

        $response = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingConfirm_{$id1}")))->handle();
        $this->assertTrue($response->isOk());

        $callbacks = array_column($this->flattenButtons($response), 'callback_data');
        $this->assertNotContains(
            "demolishBuildingGo_{$id1}",
            $callbacks,
            'последнюю постройку нельзя предлагать снести отдельно — это скрытый снос базы',
        );
        $this->assertContains('DeleteBase', $callbacks, 'должен быть путь на настоящий поток сноса базы');

        // Race-safety: даже прямой вызов execute-callback на последней постройке не удаляет её.
        $before = $this->buildingRowCount();
        $goResponse = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id1}")))->handle();
        $this->assertTrue($goResponse->isOk());
        $this->assertSame($before, $this->buildingRowCount(), 'снос последней постройки не должен исполниться даже при прямом вызове execute');
    }

    // ── 4) Исполнение: строка удаляется, ресурсы не трогаются, лог пишется ──

    public function testExecuteDeletesOnlyTargetRowAndLogsWithoutRefund(): void
    {
        $id1 = $this->seedBuilding(15);
        $id2 = $this->seedBuilding(20);

        $response = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id1}")))->handle();
        $this->assertTrue($response->isOk());
        $this->assertStringContainsString('не возвращены', $this->responseText($response));

        $remaining = $this->conn->table('character_buildings')->where('character_id', $this->characterId)->get()->getResultArray();
        $remainingIds = array_column($remaining, 'id');
        $this->assertNotContains((string) $id1, array_map('strval', $remainingIds));
        $this->assertContains((string) $id2, array_map('strval', $remainingIds), 'соседняя постройка не должна пострадать');

        $logRow = $this->conn->table('action_log')
            ->where('character_id', $this->characterId)
            ->where('action_name', 'DEMOLISH_BUILDING')
            ->where('action_status', 'Completed')
            ->get()->getRowArray();
        $this->assertNotNull($logRow, 'снос обязан оставить след в action_log');
    }

    /**
     * Ревью 24.08.2026 (BLOCK): снос стека снимает РОВНО ОДНУ штуку — строка остаётся
     * (с уменьшенным `amount`) и удаляется только когда счётчик доходит до нуля.
     * Каждый следующий снос обходит кулдаун искусственным «состариванием» лог-записи —
     * сам кулдаун проверен отдельно в других тестах, здесь важна ТОЛЬКО арифметика стека.
     */
    public function testExecuteDecrementsStackByOneAndDeletesRowOnlyAtZero(): void
    {
        $id = $this->seedBuilding(15, 1, 3);
        $this->seedBuilding(20); // соседняя — чтобы гейт «последняя» не мешал считать стек

        $r1 = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id}")))->handle();
        $this->assertTrue($r1->isOk());
        $this->assertSame(2, $this->amountOf($id), 'после первого сноса из стека 3 должно остаться 2');
        $this->assertStringContainsString('Осталось', $this->responseText($r1));

        $this->ageLastDemolishLog(2000); // за пределами дефолтного кулдауна (1440 мин)
        $r2 = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id}")))->handle();
        $this->assertTrue($r2->isOk());
        $this->assertSame(1, $this->amountOf($id), 'после второго сноса должно остаться 1 — строка ещё жива');

        $this->ageLastDemolishLog(2000);
        $r3 = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id}")))->handle();
        $this->assertTrue($r3->isOk());
        $this->assertNull($this->amountOf($id), 'последняя штука стека снесена — строка обязана исчезнуть');
        $this->assertStringContainsString('последняя штука', $this->responseText($r3));
    }

    // ── 5) Кулдаун: повторный снос сразу после первого отклоняется ──

    public function testCooldownBlocksImmediateSecondDemolish(): void
    {
        $id1 = $this->seedBuilding(15);
        $id2 = $this->seedBuilding(20);
        $this->seedBuilding(25); // третья — чтобы вторая попытка не упёрлась в гейт «последняя»

        $first = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id1}")))->handle();
        $this->assertTrue($first->isOk());
        $this->assertSame(2, $this->buildingRowCount());

        $second = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id2}")))->handle();
        $this->assertTrue($second->isOk());
        $this->assertStringContainsString('⏳', $this->responseText($second));
        $this->assertSame(2, $this->buildingRowCount(), 'второй снос в пределах кулдауна не должен исполниться');
    }

    /**
     * Кулдаун обязан матчить ОБА: `action_name=DEMOLISH_BUILDING` И `character_id`
     * этого персонажа — чужой снос или соседняя запись другого действия того же
     * персонажа не имеют права его включать.
     */
    public function testCooldownDoesNotLeakAcrossCharactersOrActionNames(): void
    {
        $id1 = $this->seedBuilding(15);
        $this->seedBuilding(20); // вторая — чтобы снос первой не упёрся в гейт «последняя»

        $otherCharacterId = $this->characterId + 900000;
        $now = date('Y-m-d H:i:s');

        // Чужой снос (другой character_id) — не должен считаться кулдауном этого персонажа.
        $this->conn->table('action_log')->insert([
            'character_id'  => $otherCharacterId,
            'chat_id'       => $this->tgId,
            'action_name'   => 'DEMOLISH_BUILDING',
            'action_status' => 'Completed',
            'description'   => 'чужой снос',
            'created_at'    => $now,
        ]);

        // Соседнее действие ЭТОГО ЖЕ персонажа (другой action_name) — тоже не считается.
        $this->conn->table('action_log')->insert([
            'character_id'  => $this->characterId,
            'chat_id'       => $this->tgId,
            'action_name'   => 'SOME_OTHER_ACTION',
            'action_status' => 'Completed',
            'description'   => 'не снос',
            'created_at'    => $now,
        ]);

        try {
            $response = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id1}")))->handle();

            $this->assertTrue($response->isOk());
            $this->assertStringNotContainsString('⏳', $this->responseText($response), 'чужая/несвязанная запись не должна включать кулдаун');
            $this->assertSame(1, $this->buildingRowCount(), 'снос обязан пройти — кулдауна для ЭТОГО персонажа/действия нет');
        } finally {
            $this->conn->table('action_log')->where('character_id', $otherCharacterId)->delete();
        }
    }

    // ── 6) Дискаверабилити: вход виден на экране «🏠 База», ровно там, где сказали Notes ──

    /**
     * Team-lead после моего вопроса (нет файла с клавиатурой экрана «🏠 База» в
     * `## Files`) добавил `BaseServiceMessageFormatter.php` в story. Тест проверяет
     * ровно то, из-за чего вопрос возник: кнопка `demolishBuilding` обязана реально
     * появляться в `reply_markup` этого экрана, а не только существовать как
     * зарегистрированный, но никуда не воткнутый callback.
     */
    /**
     * Кнопка БЕЗУСЛОВНАЯ (правило владельца, уточнено team-lead после моего вопроса):
     * видна и при 0 построек — «нечего сносить» объясняется ПОСЛЕ нажатия текстом
     * (см. {@see testDemolishListOnEmptyBaseExplainsInsteadOfHidingEntry}), а не
     * исчезновением кнопки. UX-discoverability: игрок обязан видеть, что такая
     * возможность вообще есть.
     */
    public function testBaseScreenAlwaysShowsDemolishEntryRegardlessOfBuildingCount(): void
    {
        $formatter = new \App\Services\Bases\BaseServiceMessageFormatter();

        $withBuildings = $formatter->baseBuildings(10, 20, 'Лес', 2, 30, "- Скважина\n- Скважина\n");
        $markupWith    = json_decode((string) $withBuildings['reply_markup'], true);
        $this->assertIsArray($markupWith);
        $callbacksWith = array_column(array_merge(...$markupWith['inline_keyboard']), 'callback_data');
        $this->assertContains('demolishBuilding', $callbacksWith, 'вход в снос обязан быть виден на экране «🏠 База»');

        $withoutBuildings = $formatter->baseBuildings(10, 20, 'Лес', 0, 0, '');
        $markupWithout     = json_decode((string) $withoutBuildings['reply_markup'], true);
        $this->assertIsArray($markupWithout);
        $callbacksWithout = array_column(array_merge(...$markupWithout['inline_keyboard']), 'callback_data');
        $this->assertContains(
            'demolishBuilding',
            $callbacksWithout,
            'кнопка не должна исчезать при 0 построек — отказ объясняется после клика, не пряткой входа',
        );
    }

    /** Клик по безусловной кнопке на пустой базе — понятный текст, а не тупик/ошибка. */
    public function testDemolishListOnEmptyBaseExplainsInsteadOfHidingEntry(): void
    {
        // Постройки не сеем — на этой базе их 0.
        $response = (new DemolishBuildingAction($this->callbackQuery('demolishBuilding')))->handle();

        $this->assertTrue($response->isOk());
        $this->assertStringContainsString('нет построек', $this->responseText($response));
    }

    // ── 7) Подтверждение и исполнение требуют стоять на БАЗЕ ПОСТРОЙКИ — как и список ──

    /**
     * Ревью 24.08.2026 (помельче, но обязательно): подтверждение и исполнение раньше
     * не проверяли позицию персонажа вовсе, хотя список — проверяет
     * (`ClaimedCellModel::resolveTargetBaseCell()`). Мультибазовый игрок мог снести
     * постройку базы, на которой физически не стоит. Заводим ВТОРУЮ базу — тогда
     * `resolveTargetBaseCell()` не может угадать однозначно, если персонаж не стоит
     * ни на одной, и обе стороны (confirm/execute) обязаны честно отказать.
     */
    public function testConfirmAndExecuteRequireStandingOnTheRowsBase(): void
    {
        $id = $this->seedBuilding(15);
        $this->seedBuilding(20); // сосед на той же базе — гейт «последняя» не мешает

        $secondCell = $this->cellNumber + 1000;
        $this->conn->table('claimed_cells')->insert([
            'character_id' => $this->characterId,
            'map_cell_id'  => $secondCell,
            'status'       => 'active',
        ]);
        $secondClaimedCellId = (int) $this->conn->insertID();

        // Персонаж физически не на базе A и не на базе Б — две базы делают выбор неоднозначным.
        $this->conn->table('characters')->where('id', $this->characterId)->set('cell_number', $this->cellNumber + 999999)->update();

        try {
            $confirmResp = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingConfirm_{$id}")))->handle();
            $this->assertTrue($confirmResp->isOk());
            $this->assertStringContainsString('Нужно быть на этой базе', $this->responseText($confirmResp));

            $before  = $this->buildingRowCount();
            $goResp  = (new DemolishBuildingConfirmAction($this->callbackQuery("demolishBuildingGo_{$id}")))->handle();
            $this->assertTrue($goResp->isOk());
            $this->assertStringContainsString('Нужно быть на этой базе', $this->responseText($goResp));
            $this->assertSame($before, $this->buildingRowCount(), 'снос не должен исполняться, пока персонаж не на нужной базе');
        } finally {
            $this->conn->table('claimed_cells')->where('id', $secondClaimedCellId)->delete();
        }
    }

    // ── 8) Миграция: инвариантные поля заполнены, идемпотентна ──

    public function testMigrationSeedsInvariantFieldsAndIsIdempotent(): void
    {
        // `game_settings` уже создана в setUp() (нужна каждому тесту, не только этому —
        // см. комментарий там), поэтому здесь только чистим ЗА СОБОЙ строку.
        try {
            // `Database/Migrations` исключена из classmap (composer.json) — обычный
            // `use` её не найдёт, CI4 грузит миграции своим локатором по timestamp-
            // имени файла. Подключаем файл напрямую по пути.
            require_once APPPATH . 'Database/Migrations/2026-08-24-110000_SeedDemolishCooldownSetting.php';
            $migrationClass = '\\App\\Database\\Migrations\\SeedDemolishCooldownSetting';
            $migration       = new $migrationClass();
            $migration->up();
            $migration->up(); // идемпотентность — второй прогон не дублирует строку

            $rows = $this->conn->table('game_settings')->where('setting_key', 'buildings.demolish.cooldown_minutes')->get()->getResultArray();
            $this->assertCount(1, $rows, 'повторный up() не должен дублировать запись');

            $row = $rows[0];
            foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text', 'default_value_text'] as $field) {
                $this->assertNotSame('', trim((string) ($row[$field] ?? '')), "{$field} — инвариантное поле, пустым быть не может");
            }
            $this->assertSame('1440', (string) $row['default_value_text']);
            $this->assertLessThanOrEqual((int) $row['recommended_max'], (int) $row['recommended_min'], 'recommended_min <= recommended_max');
            $this->assertLessThanOrEqual((int) $row['hard_max'], (int) $row['recommended_max'], 'recommended_max <= hard_max');
            $this->assertGreaterThanOrEqual((int) $row['hard_min'], (int) $row['recommended_min'], 'recommended_min >= hard_min');
        } finally {
            $this->conn->table('game_settings')->where('setting_key', 'buildings.demolish.cooldown_minutes')->delete();
        }
    }

    /** Настоящий CallbackQuery — как из реального вебхука клика по кнопке. */
    private function callbackQuery(string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_1',
            'from' => ['id' => $this->tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1,
                'date'       => time(),
                'chat'       => ['id' => $this->tgId, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_1',
            'data'          => $data,
        ]);
    }

    private function responseText(ServerResponse $response): string
    {
        $result = $response->getResult();
        if (! is_object($result) || ! method_exists($result, 'getText')) {
            return '';
        }

        return (string) ($result->getText() ?? '');
    }

    /**
     * @return list<array{text:string,callback_data:string}>
     */
    private function flattenButtons(ServerResponse $response): array
    {
        $result = $response->getResult();
        $raw    = is_object($result) ? $result->reply_markup : null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded) || ! isset($decoded['inline_keyboard']) || ! is_array($decoded['inline_keyboard'])) {
            return [];
        }

        $flat = [];
        foreach ($decoded['inline_keyboard'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $button) {
                if (is_array($button)) {
                    $flat[] = $button;
                }
            }
        }

        return $flat;
    }
}
