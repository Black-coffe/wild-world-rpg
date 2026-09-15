<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\StartRobotGatheringAction;
use App\Models\BuildingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * multibase-picker-05 — «Стоя на второй базе без ангара запустила робота
 * промышленника. В какой локации — непонятно».
 *
 * Робот запускается только с базы, на которой стоит игрок и на которой есть СВОЯ
 * Мастерская робототехники (`character_buildings.map_cell_id`); клетка базы
 * запуска уходит в `task_settings.base_cell`; экран называет базу и координаты.
 *
 * Реальный `handle()` на своей схеме под приватным префиксом `srgb_`
 * (паттерн `BuildingCardBaseScopeTest`). Успешный запуск в конце шлёт фото по
 * `base_url()` — в тест-стенде оно не открывается, поэтому успех проверяется по
 * записи задания в БД, а caption — через тот же `launchCaption()`/`baseLabel()`,
 * которыми его собирает `handle()`.
 *
 * @internal
 */
final class StartRobotGatheringBaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'srgb_';

    /** @var array<string,string> таблица => колонки (порядок создания; дроп — в обратном). */
    private const TABLES = [
        'telegram_users'      => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
        'characters'          => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(64) NULL, cell_number INT NULL, locale VARCHAR(8) NULL, disable_media TINYINT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL',
        'buildings'           => 'id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL',
        'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL, map_cell_id INT NULL, level INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
        'claimed_cells'       => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, map_cell_id INT NULL, claimed_at DATETIME NULL, status VARCHAR(16) NULL, camp_name VARCHAR(64) NULL',
        'map'                 => 'id INT PRIMARY KEY, cell_number INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, biome_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'tasks'               => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_rus VARCHAR(64) NULL',
        'character_tasks'     => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, telegram_user_id INT NULL, task_id INT NULL, status VARCHAR(16) NULL, start_time DATETIME NULL, end_time DATETIME NULL, task_settings TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'crafted_items'       => 'id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(255) NULL, name_eng VARCHAR(255) NULL, durability_count INT NULL, status VARCHAR(16) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'crafted_items_log'   => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, task_id INT NULL, crafted_item_id INT NULL, type VARCHAR(100) NULL, direction_craft VARCHAR(100) NULL, crafting_location VARCHAR(255) NULL, durability_count INT NULL, durability_time DATETIME NULL, quantity INT NULL, insured TINYINT NULL, custom_setting TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
    ];

    private string $origPrefix = '';
    private int $workshopId = 0;
    private int $robotId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Request коротит на фейковый ServerResponse вместо HTTP (feedback_taskhandler_telegram_init_in_tests).
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

        $this->db()->table('buildings')->insert(['name_ru' => 'Мастерская робототехники', 'name_en' => 'RoboticsWorkshop']);
        $this->workshopId = (int) $this->db()->insertID();
        $this->db()->table('tasks')->insert(['name' => 'GatheringResourcesRobot', 'name_rus' => 'Робот-добытчик']);
        $this->db()->table('crafted_items')->insert([
            'name_rus' => 'Робот-промышленник', 'name_eng' => 'TestGathererRobot', 'durability_count' => 10,
        ]);
        $this->robotId = (int) $this->db()->insertID();
        $this->db()->table('map')->insertBatch([
            ['id' => 100, 'cell_number' => 100, 'coordinate_x' => 10, 'coordinate_y' => 10, 'biome_id' => 1],
            ['id' => 200, 'cell_number' => 200, 'coordinate_x' => 20, 'coordinate_y' => 20, 'biome_id' => 2],
        ]);
    }

    protected function tearDown(): void
    {
        try {
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

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedPlayer(int $standingOn): array
    {
        $tgId = random_int(740_000_000, 749_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();
        $this->db()->table('characters')->insert(['telegram_user_id' => $tgUid, 'cell_number' => $standingOn, 'locale' => 'ru']);
        $charId = (int) $this->db()->insertID();

        $now = date('Y-m-d H:i:s');
        $this->db()->table('claimed_cells')->insertBatch([
            ['character_id' => $charId, 'map_cell_id' => 100, 'claimed_at' => $now, 'status' => 'active', 'camp_name' => 'Первая'],
            ['character_id' => $charId, 'map_cell_id' => 200, 'claimed_at' => $now, 'status' => 'active', 'camp_name' => 'Вторая'],
        ]);
        // Мастерская — только на базе «Первая».
        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $this->workshopId, 'map_cell_id' => 100, 'level' => 1,
        ]);
        $this->db()->table('crafted_items_log')->insert([
            'character_id' => $charId, 'crafted_item_id' => $this->robotId, 'quantity' => 1, 'durability_count' => 10,
        ]);

        return [$tgId, $charId];
    }

    private function cbq(int $tgId): CallbackQuery
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
            'data' => 'startRobotGatherer_' . $this->robotId,
        ]);
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

    public function testLaunchFromBaseWithoutOwnWorkshopIsRefusedAndNamesBase(): void
    {
        [$tgId, $charId] = $this->seedPlayer(200); // стоит на «Вторая», Мастерская — на «Первая»

        $response = (new StartRobotGatheringAction($this->cbq($tgId)))->handle();
        $text     = $this->textOf($response);

        $this->assertStringContainsString('Вторая (20, 20)', $text, 'отказ называет эту базу и координаты');
        $this->assertStringContainsString('нужна Мастерская робототехники на этой базе', $text);
        $this->assertSame(0, $this->db()->table('character_tasks')->where('character_id', $charId)->countAllResults(), 'задание не создано');
        $this->assertSame(
            10,
            (int) $this->db()->table('crafted_items_log')->where('character_id', $charId)->get()->getRow('durability_count'),
            'прочность робота не списана'
        );
    }

    public function testLaunchFromBaseWithWorkshopSavesBaseCellAndNamesBase(): void
    {
        [$tgId, $charId] = $this->seedPlayer(100); // стоит на «Первая», где есть Мастерская

        try {
            (new StartRobotGatheringAction($this->cbq($tgId)))->handle();
        } catch (\Throwable) {
            // Фото экрана запуска открывается по base_url() — в тест-стенде недоступно;
            // задание к этому моменту уже записано (см. докблок класса).
        }

        $row = $this->db()->table('character_tasks')->where('character_id', $charId)->get()->getRowArray();
        $this->assertIsArray($row, 'задание создано');
        $settings = json_decode((string) $row['task_settings'], true);
        $this->assertIsArray($settings);
        $this->assertSame($this->robotId, $settings['crafted_item_id'] ?? null);
        $this->assertSame(100, $settings['base_cell'] ?? null, 'base_cell = клетка базы запуска');

        $label = StartRobotGatheringAction::baseLabel($charId, 100);
        $this->assertSame('Первая (10, 10)', $label);
        $caption = StartRobotGatheringAction::launchCaption('Робот-промышленник', $label, 2, 1, '🗺 Он обходит *1* яч.', 0, 0);
        $this->assertStringContainsString('Первая (10, 10)', $caption, 'экран запуска называет базу и координаты');
        $this->assertLessThan(1024, mb_strlen($caption), 'caption влезает в лимит Telegram');
        $this->assertSame(0, substr_count($caption, '*') % 2, 'парные * — legacy Markdown не ломается');
    }

    public function testBaseLabelStripsMarkdownFromCampName(): void
    {
        [, $charId] = $this->seedPlayer(100);
        $this->db()->table('claimed_cells')->where('map_cell_id', 100)->update(['camp_name' => 'Ла_герь*']);

        $this->assertSame('Лагерь (10, 10)', StartRobotGatheringAction::baseLabel($charId, 100));
    }
}
