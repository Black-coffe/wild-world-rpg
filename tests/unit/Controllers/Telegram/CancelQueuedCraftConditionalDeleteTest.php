<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Telegram;

use App\Controllers\Telegram\Commands\Actions\Craft\CancelQueuedCraftAction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * exploit-fix-05 (H2) — снятие строки очереди крафта стало условным
 * `DELETE ... WHERE id = ? AND character_id = ? AND status = 'queued'` с решением по
 * `affectedRows()`, идущим ПЕРВЫМ — вместо безусловного `Model::delete()` (`CancelQueuedCraftAction`
 * `:94` до правки), который выполнялся уже ПОСЛЕ того, как возвраты были применены.
 *
 * Проверяет ФОРМУ записи, а не гонку. Настоящую гонку (два потока читают состояние строки ДО
 * того, как любой из них записал) честно симулировать здесь нельзя — свежий `SELECT` (`:58-62`) и
 * условный `DELETE` стоят внутри одного и того же синхронного вызова `handle()`, поэтому в
 * последовательном PHPUnit-тесте они всегда видят одно и то же состояние строки. Это ожидаемо и
 * задокументировано в `GapAuditTest::testQueuedCraftCancelRefundsExactlyOnce` (PoC этой story не
 * переписывает — он и до, и после правки зелёный по той же причине). Вместо гонки здесь проверяется
 * структурный инвариант: возврат случается ТОЛЬКО если запись реально сняла строку, и не случается,
 * если строка к моменту вызова уже не в состоянии `queued` — независимо от того, какой именно слой
 * (ранний гейт `:58-62` или сам условный `DELETE`) эту попытку отбивает.
 *
 *  1. Строка `queued` → отмена → возврат ровно один раз (ресурсы + золото), строка реально удалена.
 *  2. Строка НЕ `queued` (выставлено вручную до вызова — как выглядела бы строка, если бы её уже
 *     забрал конкурентный обработчик) → отмена → ни одного возврата, строка остаётся нетронутой.
 *
 * Схема таблиц — минимальный набор колонок, которые реально трогают `CancelQueuedCraftAction` и
 * `BaseAction::getUserAndCharacter()`; те же DDL, что уже сверены точечно и используются в
 * `tests/exploit-poc/GapAuditTest.php` (миграции этих таблиц локально с нуля не идут —
 * `feedback_test_schema_must_come_from_migration`). Таблицы общего стенда `wildworld_tests`,
 * которые существовали ДО этого теста (параллельные воркеры), не дропаются — чистятся только
 * собственные строки этого теста.
 *
 * @internal
 */
final class CancelQueuedCraftConditionalDeleteTest extends CIUnitTestCase
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
        'character_tasks'     => 'character_id',
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

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }
    }

    protected function tearDown(): void
    {
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
        $row = $this->db()->table('resources')->where('name', $name)->orderBy('id', 'ASC')->limit(1)->get();
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

    private function goldOf(int $charId): int
    {
        $row = $this->db()->table('characters')->where('id', $charId)->get();
        $arr = $row === false ? [] : ($row->getRowArray() ?? []);

        return is_numeric($arr['gold'] ?? null) ? (int) $arr['gold'] : 0;
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedCharacter(): array
    {
        $tgId = random_int(710_000_000, 719_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();

        $this->db()->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'gold' => 0, 'health' => 100, 'tired' => 100,
        ]);
        $charId = (int) $this->db()->insertID();
        $this->ownCharacterIds[] = $charId;

        return [$tgId, $charId];
    }

    /** Новая CallbackQuery на каждый вызов handle() — как в GapAuditTest/DuplicationTest. */
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
     * Рецепт `WinterHerbalBrew` (`app/Config/CraftRecipes.php`): resources
     * Древесина×20 + Травы×5, gold_required=50, crafted_items=[] — тот же минимальный рецепт, что
     * уже использует `GapAuditTest`, без зависимости от `CraftedItemsModel::getRowByName()`.
     */
    public function testQueuedRowIsDeletedAndRefundedExactlyOnce(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');
        $herbId = $this->ensureResource('Травы', 'Herbs');

        $this->db()->table('character_tasks')->insert([
            'character_id'  => $charId,
            'status'        => 'queued',
            'task_settings' => json_encode(['recipe' => 'WinterHerbalBrew', 'quantity' => 1]),
        ]);
        $taskRowId = (int) $this->db()->insertID();

        $response = (new CancelQueuedCraftAction($this->cbq($tgId, 'cancelQueued_' . $taskRowId)))->handle();

        $this->assertInstanceOf(ServerResponse::class, $response);
        $this->assertSame(20, $this->backpackQty($charId, $woodId), 'условное снятие сработало — древесина возвращена');
        $this->assertSame(5, $this->backpackQty($charId, $herbId), 'условное снятие сработало — травы возвращены');
        $this->assertSame(50, $this->goldOf($charId), 'условное снятие сработало — золото возвращено');

        $stillThere = $this->db()->table('character_tasks')->where('id', $taskRowId)->countAllResults();
        $this->assertSame(0, $stillThere, 'условная запись реально сняла строку из очереди');
    }

    public function testNonQueuedRowRefundsNothingAndStaysInPlace(): void
    {
        [$tgId, $charId] = $this->seedCharacter();
        $woodId = $this->ensureResource('Древесина', 'Wood');
        $herbId = $this->ensureResource('Травы', 'Herbs');

        // Статус выставлен напрямую в обход handle() — так выглядела бы строка, если бы к моменту
        // записи её уже забрал конкурентный обработчик. `WHERE ... status = 'queued'` на это
        // обязано ответить отказом без единого возврата, а не безусловным `affectedRows`.
        $this->db()->table('character_tasks')->insert([
            'character_id'  => $charId,
            'status'        => 'in_work',
            'task_settings' => json_encode(['recipe' => 'WinterHerbalBrew', 'quantity' => 1]),
        ]);
        $taskRowId = (int) $this->db()->insertID();

        $response = (new CancelQueuedCraftAction($this->cbq($tgId, 'cancelQueued_' . $taskRowId)))->handle();

        $this->assertInstanceOf(ServerResponse::class, $response);
        $this->assertSame(0, $this->backpackQty($charId, $woodId), 'неподтверждённое снятие — древесина не возвращена');
        $this->assertSame(0, $this->backpackQty($charId, $herbId), 'неподтверждённое снятие — травы не возвращены');
        $this->assertSame(0, $this->goldOf($charId), 'неподтверждённое снятие — золото не возвращено');

        $row = $this->db()->table('character_tasks')->where('id', $taskRowId)->get()->getRowArray();
        $this->assertIsArray($row, 'строка осталась в очереди — снятие было отвергнуто, а не выполнено');
        $this->assertSame('in_work', $row['status'], 'статус строки не тронут неподтверждённой попыткой отмены');
    }
}
