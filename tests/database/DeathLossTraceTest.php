<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\ActiveEventModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\EventModel;
use App\Models\MapModel;
use App\Services\Player\Death\PlayerRespawner;
use App\Services\Player\DeathService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use ReflectionMethod;

/**
 * Story chat-requests-batch-11 — «У меня исчезло 50% ресурсов, сравнивал "сегодня
 * 15:03" и "сейчас". все время был на базе» (09.08.2026, Max Syskov). Story 05 завела
 * `DEATH_RESPAWN` (факт смерти + клетка), но НЕ сумму — вопрос Max был именно про
 * состав потерь. Эта story добавляет отдельную запись `DEATH_LOSS` (золото + ресурсы +
 * крафт-предметы), рождающуюся в `DeathService::handlePlayerDeathAndReward()` сразу
 * после `applyLosses()`/`applyCraftLosses()` (шаг 5) — там, где потери реально списаны
 * и ещё известны, в отличие от `PlayerRespawner::respawn()` (шаг 7), который их уже не
 * видит (см. story 05 `## Findings`).
 *
 * Ревью денежного пути (после первой сдачи story 05/11) добавил требования, закрытые
 * в этом файле:
 *  §1 — сумма обязана быть ПОДТВЕРЖДЁННОЙ (before−after ПОСЛЕ списания), не заказанной
 *       (`testConfirmedGoldLossReflectsFreshStateNotStaleBefore` и парные тесты для
 *       ресурсов/крафт-предметов — `DeathService::confirmedGoldLoss()`/
 *       `confirmedResourceLoss()`/`confirmedCraftedItemLoss()` вызываются напрямую
 *       рефлексией: `handlePlayerDeathAndReward()` сам читает состояние на шаге 3,
 *       внешне впрыснуть гонку МЕЖДУ шагом 3 и списанием нельзя без реальной
 *       многопоточности — поэтому проверяем сам механизм подтверждения дельты
 *       изолированно, как уже делали для `collectBuildingTaxPerBase` в story 05).
 *  §2 — фикстура персонажа не может зависеть от того, кто создал `characters` первым
 *       в общем прогоне `tests/database` (`ensureTable()` — патчит недостающие колонки
 *       ALTER'ом, а не полагается на то, что таблицы нет вообще).
 *  §3 — тест не дропает `action_log`, которую не создавал сам (иначе `markTestSkipped`).
 *  §5 (первый пункт) — `LootProcessor::applyCraftLosses()::186` молча пропускает
 *       строку, исчезнувшую между расчётом и списанием («continue» без следа) —
 *       `confirmedCraftedItemLoss()` перечитывает состояние ПОСЛЕ списания и логирует
 *       фактическую, а не воображаемую величину; тест это подтверждает напрямую.
 *
 * `DeathService`/`LootProcessor`/`PlayerRespawner`/`CharacterModel`/
 * `CharacterResourceModel`/`CraftedItemsLogModel` DI-friendly ТОЛЬКО частично:
 * `DeathService` инжектирует `InsuranceCalculator`/`DeathPenaltyCalculator`/
 * `LootProcessor`/`PlayerRespawner`/`GameSettingsService`, но СВОИ
 * `characterModel`/`characterResourceModel`/`craftedItemsLogModel` (шаг 3 «сбор
 * имущества») создаёт хардкодом внутри конструктора — DI на них нет. `LootProcessor`
 * по умолчанию (не переопределяем) указывает на ТЕ ЖЕ реальные имена таблиц — иначе
 * шаг 3 (DeathService) и шаг 5 (LootProcessor) читали/писали бы РАЗНЫЕ таблицы.
 * Поэтому `characters`/`character_resources`/`crafted_items_log`/`crafted_items`/
 * `resources`/`telegram_users`/`action_log` — реальные имена. `PlayerRespawner`
 * (полностью DI-friendly) инжектируется с изолированными `dlt_*`-таблицами — не
 * задевает реальные `claimed_cells`/`map`/`events` вообще.
 *
 * @internal
 */
final class DeathLossTraceTest extends CIUnitTestCase
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
        . 'try/catch-контракт logDeathLoss() НЕ проверен этим запуском.';

    protected $migrate = false;

    /** @var list<string> таблицы, которые СОЗДАЛ этот тест целиком — дропаются в tearDown. */
    private array $createdTables = [];
    /** @var list<array{0:string,1:string}> [table, column], добавленные ALTER'ом к таблице, которую создал НЕ этот тест. */
    private array $addedColumns = [];
    private bool $createdActionLog = false;

    /** Пул character_id/telegram_user_id/resource_id/crafted_item_id, которыми пользуется этот файл. */
    private const CHAR_IDS         = [301, 302, 303, 304, 305, 306, 307];
    private const TG_IDS           = [701];
    private const RESOURCE_IDS     = [1, 2, 3];
    private const CRAFTED_ITEM_IDS = [901, 902];

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        $db->resetDataCache();

        // §2 ревью: фикстура персонажа НЕ должна зависеть от того, кто первым создал
        // `characters` в общем прогоне `tests/database` — `ensureTable()` создаёт таблицу
        // целиком, только если её нет вообще, и ДОБАВЛЯЕТ недостающие колонки ALTER'ом,
        // если таблица уже была (другой тест-класс/CI-дамп) — данные не трогает.
        $this->ensureTable($db, 'characters', [
            'telegram_user_id'      => 'INT NULL',
            'gold'                  => 'INT NULL DEFAULT 0',
            'level'                 => 'INT NULL DEFAULT 1',
            'insurance'             => 'INT NULL DEFAULT 0',
            'active_vehicle_log_id' => 'INT NULL',
            'cell_number'           => 'INT NULL',
            'last_respawn_at'       => 'DATETIME NULL',
            'created_at'            => 'DATETIME NULL',
            'updated_at'            => 'DATETIME NULL',
        ]);
        $this->ensureTable($db, 'telegram_users', [
            'telegram_id' => 'BIGINT NULL',
        ]);
        $this->ensureTable($db, 'character_resources', [
            'id_characters' => 'INT NULL',
            'id_resources'  => 'INT NULL',
            'quantity'      => 'INT NULL DEFAULT 0',
            'custom_data'   => 'TEXT NULL',
            'created_at'    => 'DATETIME NULL',
            'updated_at'    => 'DATETIME NULL',
        ]);
        $this->ensureTable($db, 'crafted_items_log', [
            'character_id'      => 'INT NULL',
            'crafted_item_id'   => 'INT NULL',
            'quantity'          => 'INT NULL DEFAULT 0',
            'insured'           => 'INT NULL DEFAULT 0',
            'type'              => 'VARCHAR(32) NULL',
            'durability_count'  => 'INT NULL',
            'task_id'           => 'INT NULL',
            'direction_craft'   => 'VARCHAR(32) NULL',
            'crafting_location' => 'VARCHAR(64) NULL',
            'created_at'        => 'DATETIME NULL',
            'updated_at'        => 'DATETIME NULL',
        ]);
        $this->ensureTable($db, 'crafted_items', [
            'name_rus' => 'VARCHAR(255) NULL',
            'name_eng' => 'VARCHAR(255) NULL',
        ]);
        $this->ensureTable($db, 'resources', [
            'name' => 'VARCHAR(191) NULL',
        ]);

        if (! $db->tableExists('action_log')) {
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

        // dlt_* — изолированные таблицы для PlayerRespawner, всегда свежие.
        $db->query('DROP TABLE IF EXISTS dlt_claimed_cells');
        $db->query('DROP TABLE IF EXISTS dlt_map');
        $db->query('DROP TABLE IF EXISTS dlt_events');
        $db->query('CREATE TABLE dlt_claimed_cells (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, status VARCHAR(16) NULL, map_cell_id INT NULL)');
        $db->query('CREATE TABLE dlt_map (cell_number INT NULL, coordinate_y INT NULL, biome_id INT NULL)');
        $db->query('CREATE TABLE dlt_events (event_id INT NULL, effect_type VARCHAR(32) NULL)');
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

        // Точечная очистка СВОИХ строк по id — НЕЗАВИСИМО от того, создавал ли этот
        // тест саму таблицу (`applyLosses()` МЕНЯЕТ значения после вставки — трекинг по
        // исходным данным не нашёл бы изменившуюся строку; см. находку story 11).
        $db->table('character_resources')->whereIn('id_characters', self::CHAR_IDS)->delete();
        $db->table('crafted_items_log')->whereIn('character_id', self::CHAR_IDS)->delete();
        $db->table('crafted_items')->whereIn('id', self::CRAFTED_ITEM_IDS)->delete();
        $db->table('resources')->whereIn('id', self::RESOURCE_IDS)->delete();
        $db->table('telegram_users')->whereIn('id', self::TG_IDS)->delete();
        $db->table('characters')->whereIn('id', self::CHAR_IDS)->delete();

        $db->query('DROP TABLE IF EXISTS dlt_claimed_cells');
        $db->query('DROP TABLE IF EXISTS dlt_map');
        $db->query('DROP TABLE IF EXISTS dlt_events');

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
            'id' => $id, 'gold' => $gold, 'telegram_user_id' => $telegramUserId, 'insurance' => 0,
        ]);
    }

    private function seedTelegramUser(int $id, int $telegramId): void
    {
        Database::connect('tests')->table('telegram_users')->insert([
            'id' => $id, 'telegram_id' => $telegramId,
        ]);
    }

    private function seedResource(int $id, string $name, int $charId, int $qty): void
    {
        $db = Database::connect('tests');
        $db->table('resources')->insert(['id' => $id, 'name' => $name]);
        $db->table('character_resources')->insert([
            'id_characters' => $charId, 'id_resources' => $id, 'quantity' => $qty,
        ]);
    }

    private function seedCraftedItem(int $id, string $nameRus, int $charId, int $qty): void
    {
        $db = Database::connect('tests');
        $db->table('crafted_items')->insert(['id' => $id, 'name_rus' => $nameRus, 'name_eng' => "item{$id}"]);
        $db->table('crafted_items_log')->insert([
            'character_id' => $charId, 'crafted_item_id' => $id, 'quantity' => $qty, 'insured' => 0,
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

    /** @return list<array<string,mixed>> */
    private function resourcesOf(int $charId): array
    {
        $q = Database::connect('tests')->table('character_resources')->where('id_characters', $charId)->get();
        return $q === false ? [] : $q->getResultArray();
    }

    /** DeathService с реальными таблицами (шаг 3) + изолированным PlayerRespawner (dlt_*, без базы). */
    private function deathService(): DeathService
    {
        $respawner = new PlayerRespawner(
            new CharacterModel(),
            (new ClaimedCellModel())->setTable('dlt_claimed_cells'),
            (new MapModel())->setTable('dlt_map'),
            new ActiveEventModel(),
            (new EventModel())->setTable('dlt_events')
        );

        return new DeathService(null, null, null, $respawner, null);
    }

    // ------------------------------------------------------------------
    // §1 ревью — механизм подтверждённой дельты (напрямую, рефлексией)
    // ------------------------------------------------------------------

    public function testConfirmedGoldLossReflectsFreshStateNotStaleBefore(): void
    {
        $this->seedChar(305, 1000, null);
        // Гонка: между «до» (1000, как будто прочитано на шаге 3) и подтверждением
        // золото в БД уже 200 (параллельная трата/списание другим действием).
        Database::connect('tests')->table('characters')->where('id', 305)->update(['gold' => 200]);

        $m = new ReflectionMethod(DeathService::class, 'confirmedGoldLoss');
        $m->setAccessible(true);
        $confirmed = $m->invoke($this->deathService(), 305, 1000);

        $this->assertSame(800, $confirmed, 'подтверждённая дельта = before(1000, устаревший) − after(200, реальный)');
    }

    public function testConfirmedResourceLossReflectsFreshStateNotStaleBefore(): void
    {
        $this->seedResource(1, 'Дерево', 306, 10);
        $charResId = (int) $this->resourcesOf(306)[0]['id'];
        // Гонка: реальный остаток уже 3 (было 10), а «до» и «кандидат» всё ещё говорят
        // про 10/lossAmount=5 (посчитано на шаге 4 до фактического изменения).
        Database::connect('tests')->table('character_resources')->where('id', $charResId)->update(['quantity' => 3]);

        $loserResources = [['id' => $charResId, 'id_resources' => 1, 'quantity' => 10]];
        $lostResources  = [['charResId' => $charResId, 'resourceId' => 1, 'lossAmount' => 5]];

        $m = new ReflectionMethod(DeathService::class, 'confirmedResourceLoss');
        $m->setAccessible(true);
        $confirmed = $m->invoke($this->deathService(), $loserResources, $lostResources);

        $this->assertSame(
            [['charResId' => $charResId, 'resourceId' => 1, 'lossAmount' => 7]],
            $confirmed,
            'подтверждённая дельта = before(10) − after(3) = 7, не заказанный lossAmount(5)'
        );
    }

    /**
     * §5 ревью (первый пункт) — `LootProcessor::applyCraftLosses()` молча пропускает
     * (`continue`) строку `crafted_items_log`, исчезнувшую между расчётом и списанием.
     * `confirmedCraftedItemLoss()` не должен в этом случае утверждать в логе, что
     * предмет назван и списан именно на `lossAmount` — при физическом отсутствии строки
     * ПОСЛЕ списания считаем, что унесло весь снимок «до».
     */
    public function testConfirmedCraftedItemLossHandlesRacedAwayRow(): void
    {
        // Строку logId=999 в crafted_items_log НЕ создаём вовсе — имитирует «исчезла
        // до применения» (гонка/чужое действие между шагом 3 и шагом 5).
        $loserCraftedItems = [['id' => 999, 'crafted_item_id' => 901, 'quantity' => 2]];
        $lostCraftedItems  = [['logId' => 999, 'craftedItemId' => 901, 'lossAmount' => 1]];

        $m = new ReflectionMethod(DeathService::class, 'confirmedCraftedItemLoss');
        $m->setAccessible(true);
        $confirmed = $m->invoke($this->deathService(), $loserCraftedItems, $lostCraftedItems);

        $this->assertSame(
            [['logId' => 999, 'craftedItemId' => 901, 'lossAmount' => 2]],
            $confirmed,
            'строки после списания физически нет — подтверждённая потеря = весь снимок «до» (2), не заказанный lossAmount (1)'
        );
    }

    // ------------------------------------------------------------------
    // Тесты
    // ------------------------------------------------------------------

    public function testDeathWritesLossTraceWithGoldAndResourceComposition(): void
    {
        $this->seedChar(301, 1000, 701);
        $this->seedTelegramUser(701, 555000333);
        $this->seedResource(1, 'Дерево', 301, 10);
        $this->seedResource(2, 'Вода', 301, 6);

        $result = $this->deathService()->handlePlayerDeathAndReward(301);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['hasBase'], 'нет активной базы (dlt_claimed_cells пуста) — канон -50%');
        $this->assertSame(0.50, $result['penalty']);

        $rows = $this->actionLogRows(301, 'DEATH_LOSS');
        $this->assertCount(1, $rows, 'ровно одна запись потерь на одну смерть');
        $this->assertSame('Completed', $rows[0]['action_status']);
        $this->assertSame(555000333, (int) $rows[0]['chat_id']);

        $description = (string) $rows[0]['description'];
        // 50% от 1000 золота = 500; 50% от 10 дерева = 5; 50% от 6 воды = 3.
        $this->assertStringContainsString('-500 золота', $description, 'золото названо числом, не абстрактной фразой');
        $this->assertStringContainsString('Дерево ×5', $description, 'состав ресурсов — по имени и количеству');
        $this->assertStringContainsString('Вода ×3', $description);
    }

    public function testDeathLossNamesCraftedItemInDescription(): void
    {
        // Умер с крафтовым предметом (напр. робот) — самая дорогая пропажа обязана быть
        // названа, не только золото/ресурсы. quantity=2 при -50% (нет базы) → exact=1.0,
        // fraction=0 — детерминировано, без броска монетки ADR-172 (game_settings нет
        // в этом тесте, `fractionalCraftLossEnabled()` безопасно деградирует в default
        // true, но при целом exact бросок не задействуется вообще).
        $this->seedChar(304, 0, null);
        $this->seedCraftedItem(901, 'Промышленник', 304, 2);

        $result = $this->deathService()->handlePlayerDeathAndReward(304);

        $this->assertTrue($result['success']);

        $rows = $this->actionLogRows(304, 'DEATH_LOSS');
        $this->assertCount(1, $rows);
        $description = (string) $rows[0]['description'];
        $this->assertStringContainsString('предметы:', $description);
        $this->assertStringContainsString('Промышленник ×1', $description, 'имя из name_rus, не id и не заглушка');
    }

    public function testZeroLossDeathWritesNoDummyRecord(): void
    {
        // Персонаж без золота и без ресурсов — терять нечего.
        $this->seedChar(302, 0, null);

        $result = $this->deathService()->handlePlayerDeathAndReward(302);

        $this->assertTrue($result['success']);
        $this->assertSame([], $this->actionLogRows(302, 'DEATH_LOSS'), 'нечего терять — запись-пустышка не пишется');
    }

    public function testDeathLossSurvivesActionLogFailure(): void
    {
        // §3 ревью: дропаем ТОЛЬКО ту action_log, которую создали сами.
        if (! $this->createdActionLog) {
            $this->markTestSkipped(self::ACTION_LOG_SKIP_REASON);

            return;
        }
        Database::connect('tests')->query('DROP TABLE IF EXISTS action_log');

        $this->seedChar(303, 800, null);
        $this->seedResource(3, 'Камень', 303, 4);

        $result = $this->deathService()->handlePlayerDeathAndReward(303);

        $this->assertTrue($result['success'], 'смерть отработала целиком, несмотря на сбой лога');
        $this->assertSame(400, $this->goldOf(303), '50% от 800 списано — золото реально уменьшилось');

        $resources = $this->resourcesOf(303);
        $this->assertCount(1, $resources);
        $this->assertSame(2, (int) $resources[0]['quantity'], '50% от 4 камня списано — не откат');
    }
}
