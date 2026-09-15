<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\Models\BuildingModel;
use App\TaskHandlers\CompleteRobotGatheringHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * multibase-picker-05 — завершение сбора копает вокруг базы запуска
 * (`task_settings.base_cell`), а не вокруг первой активной базы персонажа.
 * Легаси-задание (без ключа) и задание, чья база с тех пор заброшена, завершаются
 * без исключения по прежнему правилу (v0.51.30: не ронять сбор после переезда).
 *
 * Бьём по поведению: реальный `handle()` на своей схеме под приватным префиксом
 * `crgb_` (паттерн `BuildingCardBaseScopeTest`), отправка перехвачена подклассом.
 * Охват 1 клетка (Мастерская L1, робот без tier-бонуса) — BFS отдаёт только клетку
 * базы, поэтому биом в итоге однозначно показывает, от какой базы шёл обход.
 *
 * @internal
 */
final class CompleteRobotGatheringBaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'crgb_';

    /** @var array<string,string> таблица => DDL (порядок создания; дроп — в обратном). */
    private const TABLES = [
        'telegram_users'      => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
        'characters'          => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(64) NULL, cell_number INT NULL, locale VARCHAR(8) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'buildings'           => 'id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL',
        'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL, map_cell_id INT NULL, level INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
        'claimed_cells'       => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, map_cell_id INT NULL, claimed_at DATETIME NULL, status VARCHAR(16) NULL, camp_name VARCHAR(64) NULL',
        'map'                 => 'id INT PRIMARY KEY, cell_number INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, biome_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'biomes'              => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL',
        'resources'           => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_en VARCHAR(64) NULL, biome_id VARCHAR(64) NULL, rarity INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'character_resources' => 'id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT NULL, id_resources INT NULL, id_telegram_users INT NULL, quantity INT NULL, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'character_tasks'     => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, telegram_user_id INT NULL, task_id INT NULL, status VARCHAR(16) NULL, start_time DATETIME NULL, end_time DATETIME NULL, task_settings TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'crafted_items'       => 'id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(255) NULL, name_eng VARCHAR(255) NULL',
    ];

    private string $origPrefix = '';
    private int $workshopId = 0;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->db()->table('biomes')->insertBatch([
            ['id' => 1, 'name' => 'Лес'],
            ['id' => 2, 'name' => 'Пустыня'],
        ]);
        $this->db()->table('map')->insertBatch([
            ['id' => 100, 'cell_number' => 100, 'coordinate_x' => 10, 'coordinate_y' => 10, 'biome_id' => 1],
            ['id' => 200, 'cell_number' => 200, 'coordinate_x' => 20, 'coordinate_y' => 20, 'biome_id' => 2],
        ]);
        $this->db()->table('resources')->insertBatch([
            ['name' => 'Шишка', 'name_en' => 'Cone', 'biome_id' => '1', 'rarity' => 10],
            ['name' => 'Песок', 'name_en' => 'Sand', 'biome_id' => '2', 'rarity' => 10],
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

    /** @return array{0:int,1:int} [telegram_user_id, character_id] */
    private function seedCharacterWithTwoBases(): array
    {
        $this->db()->table('telegram_users')->insert(['telegram_id' => random_int(730_000_000, 739_999_999)]);
        $tgUid = (int) $this->db()->insertID();
        $this->db()->table('characters')->insert(['telegram_user_id' => $tgUid, 'cell_number' => 200, 'locale' => 'ru']);
        $charId = (int) $this->db()->insertID();

        $now = date('Y-m-d H:i:s');
        $this->db()->table('claimed_cells')->insertBatch([
            ['character_id' => $charId, 'map_cell_id' => 100, 'claimed_at' => $now, 'status' => 'active', 'camp_name' => 'Первая'],
            ['character_id' => $charId, 'map_cell_id' => 200, 'claimed_at' => $now, 'status' => 'active', 'camp_name' => 'Вторая'],
        ]);

        return [$tgUid, $charId];
    }

    private function seedWorkshop(int $charId, int $cell): void
    {
        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $this->workshopId, 'map_cell_id' => $cell, 'level' => 1,
        ]);
    }

    /**
     * @param array<string,mixed>|null $settings null — легаси-задание без task_settings
     * @return array<string,mixed>
     */
    private function seedTask(int $tgUid, int $charId, ?array $settings): array
    {
        $row = [
            'character_id'     => $charId,
            'telegram_user_id' => $tgUid,
            'task_id'          => 1,
            'status'           => 'in_work',
            'start_time'       => date('Y-m-d H:i:s', time() - 2 * 3600),
            'end_time'         => date('Y-m-d H:i:s', time() - 1),
            'task_settings'    => $settings === null ? null : json_encode($settings),
        ];
        $this->db()->table('character_tasks')->insert($row);
        $row['id'] = (int) $this->db()->insertID();

        return $row;
    }

    /** @param array<string,mixed> $task */
    private function complete(array $task): string
    {
        $handler = new CapturingRobotGatheringHandler();
        $handler->handle($task);
        $this->assertCount(1, $handler->sent, 'хендлер шлёт ровно одно сообщение-итог');

        return $handler->sent[0];
    }

    public function testCompletionDigsAroundSavedLaunchBase(): void
    {
        [$tgUid, $charId] = $this->seedCharacterWithTwoBases();
        $this->seedWorkshop($charId, 200);
        $task = $this->seedTask($tgUid, $charId, ['base_cell' => 200]);

        $msg = $this->complete($task);

        $this->assertStringContainsString('Вторая (20, 20)', $msg, 'итог называет базу запуска и координаты');
        $this->assertStringContainsString('Пустыня', $msg, 'обход стартует от клетки базы запуска');
        $this->assertStringContainsString('Песок', $msg);
        $this->assertStringNotContainsString('Лес', $msg, 'первая база не подменяет базу запуска');
        $this->assertSame('completed', $this->db()->table('character_tasks')->where('id', $task['id'])->get()->getRow('status'));
    }

    public function testLaunchBaseWithoutOwnWorkshopStillCompletes(): void
    {
        // Мастерскую базы запуска снесли после запуска — сбор не обнуляется (v0.51.30),
        // берётся прежний поиск Мастерской без клетки; клетка обхода остаётся базой запуска.
        [$tgUid, $charId] = $this->seedCharacterWithTwoBases();
        $this->seedWorkshop($charId, 100);
        $task = $this->seedTask($tgUid, $charId, ['base_cell' => 200]);

        $msg = $this->complete($task);

        $this->assertStringContainsString('Вторая (20, 20)', $msg);
        $this->assertStringContainsString('Песок', $msg);
        $this->assertStringNotContainsString('отсутствует', $msg);
    }

    public function testLegacyTaskWithoutBaseCellCompletesByPriorRule(): void
    {
        [$tgUid, $charId] = $this->seedCharacterWithTwoBases();
        $this->seedWorkshop($charId, 100);
        $task = $this->seedTask($tgUid, $charId, null);

        $msg = $this->complete($task);

        $this->assertStringContainsString('Первая (10, 10)', $msg, 'легаси: первая активная база, как до выкатки');
        $this->assertStringContainsString('Шишка', $msg);
        $this->assertStringNotContainsString('Песок', $msg);
    }

    public function testLegacyTaskPicksActiveBaseWithLowestId(): void
    {
        // multibase-picker-09 (lead-review minor 8): легаси-путь детерминирован — наименьший `id`.
        // Вставка в обратном порядке: сначала id=9 («Первая», клетка 100), затем id=3 («Вторая», 200).
        $this->db()->table('telegram_users')->insert(['telegram_id' => random_int(730_000_000, 739_999_999)]);
        $tgUid = (int) $this->db()->insertID();
        $this->db()->table('characters')->insert(['telegram_user_id' => $tgUid, 'cell_number' => 555, 'locale' => 'ru']);
        $charId = (int) $this->db()->insertID();
        $now = date('Y-m-d H:i:s');
        $this->db()->table('claimed_cells')->insert(['id' => 9, 'character_id' => $charId, 'map_cell_id' => 100, 'claimed_at' => $now, 'status' => 'active', 'camp_name' => 'Первая']);
        $this->db()->table('claimed_cells')->insert(['id' => 3, 'character_id' => $charId, 'map_cell_id' => 200, 'claimed_at' => $now, 'status' => 'active', 'camp_name' => 'Вторая']);
        $this->seedWorkshop($charId, 200);
        $task = $this->seedTask($tgUid, $charId, null);

        $msg = $this->complete($task);

        $this->assertStringContainsString('Вторая (20, 20)', $msg, 'активная база с наименьшим id');
        $this->assertStringContainsString('Песок', $msg);
        $this->assertStringNotContainsString('Шишка', $msg);
    }

    public function testSavedBaseNoLongerActiveFallsBackToPriorRule(): void
    {
        [$tgUid, $charId] = $this->seedCharacterWithTwoBases();
        $this->seedWorkshop($charId, 100);
        $this->db()->table('claimed_cells')->where('map_cell_id', 200)->update(['status' => 'abandoned']);
        $task = $this->seedTask($tgUid, $charId, ['crafted_item_id' => 999999, 'base_cell' => 200]);

        $msg = $this->complete($task);

        $this->assertStringContainsString('Первая (10, 10)', $msg, 'заброшенная база запуска → прежнее правило, без исключения');
        $this->assertStringContainsString('Шишка', $msg);
        $this->assertStringNotContainsString('Вторая', $msg);
    }
}

/**
 * Перехват отправки: `safeSendMessage`/`safeSendPhoto` — protected в BaseTaskHandler.
 *
 * @internal
 */
final class CapturingRobotGatheringHandler extends CompleteRobotGatheringHandler
{
    /** @var list<string> */
    public array $sent = [];

    protected function safeSendMessage($chatId, string $text, array $extra = []): void
    {
        $this->sent[] = $text;
    }

    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
        $this->sent[] = $caption;
    }
}
