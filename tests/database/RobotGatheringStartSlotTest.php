<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\StartRobotGatheringAction;
use App\Models\BuildingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;

/**
 * s1-atomicity-11 (ask 3, finding 2 раунда 1) — запуск робота-добытчика пишет строку
 * `character_tasks` через ядро слота `ActiveTasksService::insertExclusiveTaskRow()`, а не
 * `CharacterTaskModel::save()`.
 *
 * Реальный `handle()` на своей схеме под приватным префиксом `rgss_` (паттерн
 * `StartRobotGatheringBaseTest`); `character_tasks` несёт колонки и индекс S1 дословно из
 * миграции `S1CharacterTasksExclusiveSlot` (C9). Контракт держится при любых флагах задачи:
 * если `GatheringResourcesRobot` 🔒 (`parallel_execution_allowed = 0`), занятый слот даёт
 * текст отказа ADR-167 «Сейчас занят: …», прочность робота не списана, строки нет.
 *
 * @internal
 */
final class RobotGatheringStartSlotTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'rgss_';

    private const LOCK_CACHE_KEY = 'game_settings_craft_exclusive_lock_enabled';

    /** @var array<string,string> таблица => колонки (порядок создания; дроп — в обратном). */
    private const TABLES = [
        'telegram_users'      => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
        'characters'          => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(64) NULL, cell_number INT NULL, locale VARCHAR(8) NULL, disable_media TINYINT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL',
        'buildings'           => 'id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL',
        'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL, map_cell_id INT NULL, level INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
        'claimed_cells'       => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, map_cell_id INT NULL, claimed_at DATETIME NULL, status VARCHAR(16) NULL, camp_name VARCHAR(64) NULL',
        'map'                 => 'id INT PRIMARY KEY, cell_number INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, biome_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'tasks'               => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_rus VARCHAR(64) NULL, parallel_execution_allowed TINYINT NOT NULL DEFAULT 1',
        'character_tasks'     => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT UNSIGNED NOT NULL, telegram_user_id INT NULL, task_id INT NULL, status VARCHAR(16) NULL, start_time DATETIME NULL, end_time DATETIME NULL, task_settings TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, '
            . '`is_exclusive` TINYINT(1) NOT NULL DEFAULT 0, '
            . "`exclusive_slot` INT UNSIGNED GENERATED ALWAYS AS (IF(`status` = 'in_work' AND `is_exclusive` = 1, `character_id`, NULL)) VIRTUAL, "
            . 'UNIQUE KEY `uq_ct_exclusive_slot` (`exclusive_slot`)',
        'crafted_items'       => 'id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(255) NULL, name_eng VARCHAR(255) NULL, durability_count INT NULL, status VARCHAR(16) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'crafted_items_log'   => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, task_id INT NULL, crafted_item_id INT NULL, type VARCHAR(100) NULL, direction_craft VARCHAR(100) NULL, crafting_location VARCHAR(255) NULL, durability_count INT NULL, durability_time DATETIME NULL, quantity INT NULL, insured TINYINT NULL, custom_setting TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
    ];

    private string $origPrefix = '';
    private int $robotId       = 0;
    private int $robotTaskId   = 0;
    private int $gatherTaskId  = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->origPrefix = $this->db()->getPrefix();
        $this->db()->setPrefix(self::PREFIX);
        foreach (self::TABLES as $table => $cols) {
            $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            $this->db()->query('CREATE TABLE ' . self::PREFIX . $table . " ({$cols}) DEFAULT CHARSET=utf8mb4");
        }
        self::resetBuildingCache();

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }
        service('cache')->save(self::LOCK_CACHE_KEY, ['v' => true, 't' => 'bool'], 60);

        $this->db()->table('buildings')->insert(['name_ru' => 'Мастерская робототехники', 'name_en' => 'RoboticsWorkshop']);
        $workshopId = (int) $this->db()->insertID();
        $this->db()->table('crafted_items')->insert([
            'name_rus' => 'Робот-промышленник', 'name_eng' => 'TestGathererRobot', 'durability_count' => 10,
        ]);
        $this->robotId = (int) $this->db()->insertID();
        $this->db()->table('tasks')->insert(['name' => 'Gather', 'name_rus' => 'Добыча ресурсов', 'parallel_execution_allowed' => 0]);
        $this->gatherTaskId = (int) $this->db()->insertID();
        $this->db()->table('map')->insert(['id' => 100, 'cell_number' => 100, 'coordinate_x' => 10, 'coordinate_y' => 10, 'biome_id' => 1]);

        // Сам игрок: стоит на своей базе «Первая» с Мастерской L1, один робот прочностью 10.
        $this->db()->table('telegram_users')->insert(['telegram_id' => 745_000_111]);
        $tgUid = (int) $this->db()->insertID();
        $this->db()->table('characters')->insert(['telegram_user_id' => $tgUid, 'cell_number' => 100, 'locale' => 'ru']);
        $charId = (int) $this->db()->insertID();
        $this->db()->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => 100, 'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active', 'camp_name' => 'Первая',
        ]);
        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $workshopId, 'map_cell_id' => 100, 'level' => 1,
        ]);
        $this->db()->table('crafted_items_log')->insert([
            'character_id' => $charId, 'crafted_item_id' => $this->robotId, 'quantity' => 1, 'durability_count' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            service('cache')->delete(self::LOCK_CACHE_KEY);
            foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            }
        } finally {
            $this->db()->setPrefix($this->origPrefix);
            self::resetBuildingCache();
        }

        parent::tearDown();
    }

    private static function resetBuildingCache(): void
    {
        $prop = new \ReflectionProperty(BuildingModel::class, 'byNameEnCache');
        $prop->setValue(null, []);
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    private function seedRobotTask(int $parallel): void
    {
        $this->db()->table('tasks')->insert([
            'name' => 'GatheringResourcesRobot', 'name_rus' => 'Робот-добытчик', 'parallel_execution_allowed' => $parallel,
        ]);
        $this->robotTaskId = (int) $this->db()->insertID();
    }

    private function charId(): int
    {
        return (int) $this->db()->table('characters')->get()->getRow('id');
    }

    private function cbq(): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_' . random_int(1, 999999999),
            'from' => ['id' => 745_000_111, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1, 'date' => time(),
                'chat' => ['id' => 745_000_111, 'type' => 'private'],
                'text' => 'placeholder',
            ],
            'chat_instance' => 'ci_745000111',
            'data' => 'startRobotGatherer_' . $this->robotId,
        ]);
    }

    /** Реальный `handle()`; фото-транспорт успешного запуска в стенде бросает — это не исход. */
    private function launch(): string
    {
        try {
            $response = (new StartRobotGatheringAction($this->cbq()))->handle();
        } catch (TelegramException | \ErrorException $e) {
            $this->assertStringContainsString('robot_gatherer.jpg', $e->getMessage(), 'ожидаемое исключение — только фото');

            return '';
        } catch (\Throwable $e) {
            return 'threw: ' . $e->getMessage();
        }

        return $this->textOf($response);
    }

    private function textOf(ServerResponse $response): string
    {
        $result = $response->getResult();
        if (! is_object($result)) {
            return '';
        }
        $text = $result->getText();

        return is_string($text) ? $text : '';
    }

    private function durability(): int
    {
        return (int) $this->db()->table('crafted_items_log')->where('character_id', $this->charId())->get()->getRow('durability_count');
    }

    /** @return list<array<string,mixed>> */
    private function robotRows(): array
    {
        /** @var list<array<string,mixed>> */
        return $this->db()->table('character_tasks')->where('task_id', $this->robotTaskId)->get()->getResultArray();
    }

    public function testBusyExclusiveSlotRefusesWithAdr167TextAndSpendsNothing(): void
    {
        $this->seedRobotTask(0);
        $this->db()->table('character_tasks')->insert([
            'character_id' => $this->charId(), 'telegram_user_id' => 1, 'task_id' => $this->gatherTaskId,
            'status' => 'in_work', 'start_time' => date('Y-m-d H:i:s'), 'end_time' => date('Y-m-d H:i:s', time() + 3600),
            'is_exclusive' => 1,
        ]);

        $text = $this->launch();

        $this->assertSame(10, $this->durability(), 'прочность робота не списана при занятом 🔒-слоте');
        $this->assertSame([], $this->robotRows(), 'строка робота не создана');
        $this->assertStringContainsString('Сейчас занят: Добыча ресурсов', $text, 'отказ — текстом ADR-167');
    }

    public function testExclusiveRobotStartOnFreeSlotTakesTheSlot(): void
    {
        $this->seedRobotTask(0);

        $this->launch();

        $rows = $this->robotRows();
        $this->assertCount(1, $rows, 'робот запущен');
        $this->assertSame(1, (int) $rows[0]['is_exclusive'], 'строка пишется ядром слота: флаг 🔒 скопирован из tasks');
        $this->assertSame('in_work', $rows[0]['status']);
    }

    public function testParallelRobotStartWritesNonExclusiveRowAndSpendsDurability(): void
    {
        $this->seedRobotTask(1);

        $this->launch();

        $rows = $this->robotRows();
        $this->assertCount(1, $rows, 'робот запущен');
        $this->assertSame(0, (int) $rows[0]['is_exclusive']);
        $this->assertNotNull($rows[0]['created_at'], 'метки времени ставит ядро слота');
        $this->assertSame(8, $this->durability(), 'прочность списана: 2 ч × L1');
    }
}
