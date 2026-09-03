<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\RepairBuildingAction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * exploit-fix-15 (C2) — отказ на любой строке плана ремонта обязан откатить уже
 * списанные строки, а не коммитить их: раньше `confirmRepair()` (`RepairBuildingAction`
 * до правки) при недостатке на второй строке плана делал `break` и всё равно шёл в
 * `transComplete()` — первая строка (уже списанная `decrementIfAtLeast()`) фиксировалась,
 * hp постройки не менялся, а игрок читал «попробуй снова», как будто ничего не произошло.
 *
 * `computePlan()` проверяет affordability ДО транзакции, читая 'have' заранее (`:108` в
 * actione) — недостаток строки в этом раннем чтении отбивается ДО открытия транзакции
 * вовсе (другая ветка, не патченная этой story). Чтобы дойти до самой транзакции и
 * реально упасть на `decrementIfAtLeast()` внутри неё, обе строки должны выглядеть
 * достаточными в момент `computePlan()` — отказ внутри транзакции возможен только через
 * честную гонку (аналогично M1/`CargoDroneAutoSendAction`): кто-то тратит ресурс между
 * ранним чтением и условным списанием. Симулируется детерминированно, см. ниже.
 *
 * Рецепт `WoodenWall` (`app/Config/Buildings.php`): resources Wood×800, Clay×200 (в этом
 * порядке — Wood обрабатывается первым). `defense.repair.cost_fraction` не сконфигурирован
 * в тестовой БД → `GameSettingsService::get()` деградирует на caller-default 0.50
 * (`GameSettingsReaderTrait::gsFloat`, `App\Services\GameSettings\GameSettingsService::get()`
 * ловит отсутствие таблицы и возвращает default). templateHp=1000, curHp=500,
 * level=1 (levelMult=1.0, no-op) → missingFraction=0.5 → qty = ceil(baseQty × 0.5 × 0.5):
 * Wood 200, Clay 50. У персонажа Wood=200, Clay=50 — ровно хватает на обе строки на
 * момент `computePlan()`.
 *
 * Схема таблиц — минимальный набор колонок, которые реально трогают `RepairBuildingAction`
 * и `BaseAction::getUserAndCharacter()`, тот же паттерн DDL, что и
 * `CancelQueuedCraftConditionalDeleteTest` (миграции этих таблиц локально с нуля не идут —
 * `feedback_test_schema_must_come_from_migration`). Таблицы общего стенда `wildworld_tests`,
 * которые существовали ДО этого теста (параллельные воркеры), не дропаются — чистятся
 * только собственные строки этого теста.
 *
 * @internal
 */
final class RepairBuildingShortageRollbackTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<string> таблицы, которые СОЗДАЛ этот тест (и поэтому вправе дропнуть). */
    private array $createdTables = [];

    /** @var list<int> id персонажей, заведённых этим тестом. */
    private array $ownCharacterIds = [];

    /** Таблица → колонка-владелец персонажа, для чистки строк в общих таблицах. */
    private const CHAR_LINKED = [
        'characters'          => 'id',
        'character_resources' => 'id_characters',
        'character_buildings' => 'character_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Нужен, чтобы App\Services\Telegram\Request коротил на фейковый ServerResponse вместо
        // реального HTTP (урок feedback_taskhandler_telegram_init_in_tests).
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

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
            ) AUTO_INCREMENT=' . random_int(3_000_000, 3_999_999));
        // AUTO_INCREMENT со случайного высокого значения — `characters` в общей БД отсутствует и
        // создаётся каждым прогоном заново, сдвиг нужен, чтобы не столкнуться с id из прошлых
        // прогонов в персистентных character-linked таблицах (см. CHAR_LINKED).
        $this->createTableIfMissing('character_resources', '
            CREATE TABLE character_resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_characters INT NULL,
                id_resources INT NULL,
                id_telegram_users INT NULL,
                quantity INT NULL DEFAULT 0,
                custom_data TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createTableIfMissing('resources', '
            CREATE TABLE resources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NULL,
                name_en VARCHAR(64) NULL,
                icon_text VARCHAR(8) NULL,
                rarity INT NULL DEFAULT 1,
                is_tradeable TINYINT NULL DEFAULT 1,
                buy_price DECIMAL(10,2) NULL DEFAULT 0,
                sell_price DECIMAL(10,2) NULL DEFAULT 0
            )
        ');
        $this->createTableIfMissing('buildings', "
            CREATE TABLE buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_ru VARCHAR(255) NULL,
                name_en VARCHAR(255) NULL,
                building_type ENUM('military','residential','farming','resource','engineering','defensive') NULL,
                hp INT NULL
            )
        ");
        $this->createTableIfMissing('character_buildings', "
            CREATE TABLE character_buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                building_id INT NULL,
                building_type ENUM('military','residential','farming','resource','engineering','defensive') NULL,
                hp INT NULL,
                level INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ");

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('DBQuery');

        try {
            foreach (self::CHAR_LINKED as $table => $col) {
                if (in_array($table, $this->createdTables, true) || $this->ownCharacterIds === []) {
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

    private function ensureResource(string $name, string $nameEn): int
    {
        $row = $this->db()->table('resources')->where('name_en', $nameEn)->orderBy('id', 'ASC')->limit(1)->get();
        $arr = $row === false ? null : $row->getRowArray();
        if (is_array($arr) && isset($arr['id'])) {
            return (int) $arr['id'];
        }

        $this->db()->table('resources')->insert(['name' => $name, 'name_en' => $nameEn, 'rarity' => 1]);

        return (int) $this->db()->insertID();
    }

    private function backpackQty(int $charId, int $resId): int
    {
        $row = $this->db()->table('character_resources')
            ->where('id_characters', $charId)
            ->where('id_resources', $resId)
            ->get();
        $arr = $row === false ? null : $row->getRowArray();

        return is_array($arr) && is_numeric($arr['quantity'] ?? null) ? (int) $arr['quantity'] : 0;
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedCharacter(): array
    {
        $tgId = random_int(720_000_000, 729_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();

        $this->db()->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'gold' => 0, 'health' => 100, 'tired' => 100,
        ]);
        $charId = (int) $this->db()->insertID();
        $this->ownCharacterIds[] = $charId;

        return [$tgId, $charId];
    }

    /** Новая CallbackQuery на каждый вызов handle() — как в CancelQueuedCraftConditionalDeleteTest. */
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

    public function testShortageOnSecondLineRollsBackFirstLineAndLeavesHpUntouched(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');
        $clayId = $this->ensureResource('Глина', 'Clay');

        $this->db()->table('buildings')->insert([
            'name_ru' => 'Деревянная стена', 'name_en' => 'WoodenWall',
            'building_type' => 'defensive', 'hp' => 1000,
        ]);
        $buildingId = (int) $this->db()->insertID();

        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $buildingId,
            'building_type' => 'defensive', 'hp' => 500, 'level' => 1,
        ]);
        $cbId = (int) $this->db()->insertID();

        // Wood: нужно 200, есть ровно 200 — спишется первым. Clay: нужно 50, есть ровно 50 —
        // affordability-гейт `computePlan()` (:108, читает 'have' ДО транзакции) увидит обе
        // строки как достаточные и пропустит confirmRepair() дальше, к самой транзакции.
        $this->db()->table('character_resources')->insert(['id_characters' => $charId, 'id_resources' => $woodId, 'quantity' => 200]);
        $this->db()->table('character_resources')->insert(['id_characters' => $charId, 'id_resources' => $clayId, 'quantity' => 50]);

        // Честная гонка (та же, что описывает сообщение отказа в actione): между тем, как
        // computePlan() ПРОЧИТАЛ остаток Clay (50, «хватает»), и тем, как decrementIfAtLeast()
        // внутри транзакции спишет его ЗАНОВО отдельным условным UPDATE, кто-то успел
        // потратить Clay до 5. Симулируем детерминированно через Events::on('DBQuery', …):
        // SQL-форма чтения `character_resources` по (id_characters, id_resources) одинакова и
        // в `computePlan()`, и в `CharacterResourceModel::decrementIfAtLeast()` (сначала
        // читает rowId, потом уже отдельным запросом делает условный `UPDATE`). Порядок в
        // рамках ОДНОГО вызова `confirmRepair()`: 1 — computePlan/Wood, 2 — computePlan/Clay,
        // 3 — decrementIfAtLeast/Wood (rowId), 4 — decrementIfAtLeast/Clay (rowId). Режем Clay
        // ПОСЛЕ 4-го такого чтения — сразу после того, как `decrementIfAtLeast()` нашёл строку
        // Clay, но ДО того, как выполнит по ней условный `UPDATE ... WHERE quantity >= 50`.
        $seen  = 0;
        $clayResIdForHook = $clayId;
        Events::on('DBQuery', function (Query $query) use (&$seen, $charId, $clayResIdForHook): void {
            $sql = $query->getOriginalQuery();
            if (! str_contains($sql, 'FROM `character_resources`') || ! str_contains($sql, '`id_characters`')) {
                return;
            }
            $seen++;
            if ($seen === 4) {
                $this->db()->table('character_resources')
                    ->where('id_characters', $charId)
                    ->where('id_resources', $clayResIdForHook)
                    ->update(['quantity' => 5]);
            }
        });

        $response = (new RepairBuildingAction($this->cbq($tgId, 'confirmRepairBuilding_' . $cbId)))->confirmRepair();

        $this->assertInstanceOf(ServerResponse::class, $response);
        $this->assertSame(200, $this->backpackQty($charId, $woodId), 'откат вернул уже списанную первую строку (Wood)');
        // Симулированная гонка идёт по тому же соединению/транзакции, что и сам confirmRepair()
        // (тестовый стенд не поднимает второе физическое соединение) — откат сворачивает
        // обратно и её тоже, возвращая Clay к исходным 50. Смысловая проверка этой строки не в
        // точном числе Clay, а в том, что Wood выше — не 0: без правки строка Wood осталась бы
        // списанной, несмотря на transRollback() по Clay.
        $this->assertSame(50, $this->backpackQty($charId, $clayId), 'вторая строка (Clay) тоже вернулась к состоянию до вызова — откат затронул всю транзакцию');

        $row = $this->db()->table('character_buildings')->where('id', $cbId)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame(500, (int) $row['hp'], 'hp постройки не изменился — ремонт не прошёл целиком');
    }
}
