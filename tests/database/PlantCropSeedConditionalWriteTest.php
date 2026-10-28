<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Greenhouse\PlantCropActionStart;
use App\Database\Migrations\CreateTasksTable;
use CodeIgniter\Events\Events;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Telegram;

/**
 * F4 (s2-conditional-writes-01) — посадка семени не списывает семя «в минус» и не создаёт задачу
 * посадки по устаревшему снимку.
 *
 * Гейт `PlantCropActionStart` читает количество семени (`ownedSeedQty()`), затем пишет списание
 * и задачу. Между чтением и записью строку семени обнуляет другой запрос: настоящий `handle()`
 * вызывается целиком, а обнуление вклинивается ровно после гейт-SELECT через `DBQuery`-событие
 * (тот же приём, что `ConditionalWriteServiceTest` для чужого списания между двумя операторами).
 * Ожидание — отказ без эффектов: количество не ниже 0, новой строки `character_tasks` нет.
 *
 * Схема строится из миграций/DDL по факту отсутствия таблицы; таблица, которую создал этот тест,
 * дропается в tearDown, чужие — нет (`tasks` и `character_tasks` общие, их не дропаем никогда).
 *
 * @internal
 */
final class PlantCropSeedConditionalWriteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED_REASON = 'stale seed snapshot: quantity went below zero or plant task row created';

    /** Общие таблицы стенда — не дропаются, даже если этот тест их создал. */
    private const KEEP_ALIVE = ['tasks', 'character_tasks'];

    /** @var list<string> таблицы, созданные ЭТИМ тестом. */
    private array $createdTables = [];

    /** @var array<string, list<int>> id строк, вставленных этим тестом, по таблице. */
    private array $ownRows = [];

    /** @var (callable(object): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Longman отвечает фейком только в тестовом режиме (урок feedback_taskhandler_telegram_init_in_tests).
        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $db = $this->db();
        // Кэш списка таблиц живёт на соединении между тестами класса — сбрасываем, иначе
        // «таблица есть» может быть устаревшим после DROP соседнего теста.
        $db->resetDataCache();

        $this->createIfMissing('telegram_users', '
            CREATE TABLE telegram_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL,
                username VARCHAR(100) NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createIfMissing('characters', '
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
            )
        ');
        $this->createIfMissing('resources', '
            CREATE TABLE resources (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NULL,
                name_en VARCHAR(255) NULL,
                description TEXT NULL,
                biome_id TEXT NULL,
                is_tradeable TINYINT NULL DEFAULT 1,
                type VARCHAR(255) NULL,
                price INT NULL,
                buy_price DECIMAL(10,2) NULL DEFAULT 0,
                sell_price DECIMAL(10,2) NULL DEFAULT 0,
                rarity INT NULL DEFAULT 1,
                level_required INT NULL,
                initial_quantity INT NULL DEFAULT 100,
                keyword VARCHAR(100) NULL,
                icon_text VARCHAR(8) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_resources_name (name),
                UNIQUE KEY uniq_resources_name_en (name_en)
            )
        ');
        $this->createIfMissing('character_resources', '
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
        $this->createIfMissing('buildings', "
            CREATE TABLE buildings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name_ru VARCHAR(255) NULL,
                name_en VARCHAR(255) NULL,
                description TEXT NULL,
                building_type ENUM('military','residential','farming','resource','engineering') NULL,
                hp INT NULL,
                construction_time INT NULL,
                tax INT NULL,
                level INT NULL DEFAULT 1,
                `usage` ENUM('personal','collective','all') NULL,
                required_resources TEXT NULL,
                min_character_level INT NULL,
                effects TEXT NULL,
                days_until_disappearance INT NULL DEFAULT 0,
                usage_count INT NULL
            )
        ");
        $this->createIfMissing('character_buildings', "
            CREATE TABLE character_buildings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT UNSIGNED NULL,
                building_id INT UNSIGNED NULL,
                faction_id INT UNSIGNED NULL,
                map_cell_id INT UNSIGNED NULL,
                amount INT NOT NULL DEFAULT 1,
                character_level_during_construction INT NULL,
                hp INT NULL,
                level INT NULL DEFAULT 1,
                built_at DATETIME NULL,
                building_type ENUM('military','residential','farming','resource','engineering') NULL,
                tax INT NULL,
                `usage` ENUM('personal','collective','all') NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ");
        $this->createIfMissing('game_settings', "
            CREATE TABLE game_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(64) NOT NULL,
                category VARCHAR(32) NOT NULL,
                value_type ENUM('int','float','bool','string') NOT NULL,
                value_int INT NULL,
                value_float DECIMAL(12,4) NULL,
                value_bool TINYINT(1) NULL,
                value_string VARCHAR(255) NULL,
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
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_setting_key (setting_key)
            )
        ");
        if (! $db->tableExists('tasks')) {
            $forge = Database::forge('tests');
            (new CreateTasksTable($forge instanceof Forge ? $forge : null))->up();
            $this->createdTables[] = 'tasks';
        }
        $this->createIfMissing('character_tasks', "
            CREATE TABLE character_tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT UNSIGNED NULL,
                telegram_user_id INT UNSIGNED NULL,
                task_id INT NULL,
                start_time DATETIME NULL,
                end_time DATETIME NULL,
                status ENUM('in_work','completed','interrupted','queued','paused') DEFAULT 'in_work',
                task_settings TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ");
    }

    protected function tearDown(): void
    {
        try {
            if ($this->listener !== null) {
                Events::removeListener('DBQuery', $this->listener);
                $this->listener = null;
            }
            $db = $this->db();
            foreach ($this->ownRows as $table => $ids) {
                if ($ids !== []) {
                    $db->table($table)->whereIn('id', $ids)->delete();
                }
            }
            foreach (array_reverse($this->createdTables) as $table) {
                if (! in_array($table, self::KEEP_ALIVE, true)) {
                    $db->query("DROP TABLE IF EXISTS {$table}");
                }
            }
            $db->resetDataCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testStaleSeedSnapshotRefusesPlantingWithoutTaskRow(): void
    {
        $tgId  = random_int(730_000_000, 739_999_999);
        $tgUid = $this->insertTracked('telegram_users', ['telegram_id' => $tgId]);
        $charId = $this->insertTracked('characters', [
            'telegram_user_id' => $tgUid, 'gold' => 0, 'health' => 100, 'tired' => 100,
        ]);

        $buildingId = $this->ensureRow('buildings', 'name_en', 'Greenhouse', ['name_ru' => 'Теплица', 'name_en' => 'Greenhouse']);
        $this->insertTracked('character_buildings', [
            'character_id' => $charId, 'building_id' => $buildingId, 'level' => 1,
        ]);

        $taskId = $this->ensureRow('tasks', 'name', 'plantCrop', [
            'name' => 'plantCrop', 'description' => '', 'difficulty_level' => 1,
            'parallel_execution_allowed' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 'berries' → seed_en='BerrySeeds' (FarmingService::CROP_MAP).
        $seedId = $this->ensureRow('resources', 'name_en', 'BerrySeeds', ['name' => 'Семена ягод', 'name_en' => 'BerrySeeds', 'rarity' => 1]);
        $seedRowId = $this->insertTracked('character_resources', [
            'id_characters' => $charId, 'id_resources' => $seedId, 'quantity' => 1,
        ]);

        // Гейт прочитал quantity = 1; сразу после этого чтения строку семени обнуляет отдельное
        // соединение — списание идёт по устаревшему снимку.
        $fired = false;
        $this->listener = function (object $query) use (&$fired, $seedRowId): void {
            if ($fired) {
                return;
            }
            $sql = ltrim((string) $query->getQuery());
            if (! str_starts_with($sql, 'SELECT') || ! str_contains($sql, 'character_resources') || ! str_contains($sql, 'JOIN')) {
                return;
            }
            $fired = true;
            Database::connect('tests', false)->query('UPDATE character_resources SET quantity = 0 WHERE id = ?', [$seedRowId]);
        };
        Events::on('DBQuery', $this->listener);

        $this->runPlantAction($tgId, 'plantSeedStart_berries');

        $this->assertTrue($fired, 'предусловие: гейт прочитал семя до обнуления');
        $this->assertGreaterThanOrEqual(0, $this->seedQty($charId, $seedId), self::RED_REASON);
        $this->assertSame(
            0,
            $this->db()->table('character_tasks')
                ->where('character_id', $charId)
                ->where('task_id', $taskId)
                ->countAllResults(),
            self::RED_REASON
        );
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect('tests');
    }

    /**
     * Создаёт таблицу только если её нет на стенде; свою — запоминает для дропа в tearDown.
     */
    private function createIfMissing(string $table, string $ddl): void
    {
        $db = $this->db();
        if ($db->tableExists($table)) {
            return;
        }
        $db->query($ddl);
        $db->resetDataCache();
        $this->createdTables[] = $table;
    }

    private function insertTracked(string $table, array $data): int
    {
        $this->db()->table($table)->insert($data);
        $id = (int) $this->db()->insertID();
        $this->ownRows[$table][] = $id;

        return $id;
    }

    /**
     * Найти строку по колонке или вставить свою (и только её запомнить для удаления).
     *
     * @param array<string, mixed> $row
     */
    private function ensureRow(string $table, string $column, string $value, array $row): int
    {
        $found = $this->db()->table($table)->where($column, $value)->orderBy('id', 'ASC')->limit(1)->get();
        $arr   = $found === false ? null : $found->getRowArray();
        if (is_array($arr) && isset($arr['id'])) {
            return (int) $arr['id'];
        }

        return $this->insertTracked($table, $row);
    }

    private function seedQty(int $charId, int $seedId): int
    {
        $row = $this->db()->table('character_resources')
            ->where('id_characters', $charId)
            ->where('id_resources', $seedId)
            ->get();
        $arr = $row === false ? null : $row->getRowArray();

        return is_array($arr) && is_numeric($arr['quantity'] ?? null) ? (int) $arr['quantity'] : 0;
    }

    private function runPlantAction(int $tgId, string $data): void
    {
        try {
            (new PlantCropActionStart(new CallbackQuery([
                'id'            => 'cbq_' . random_int(1, 999999999),
                'from'          => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
                'message'       => [
                    'message_id' => 1, 'date' => time(),
                    'chat'       => ['id' => $tgId, 'type' => 'private'],
                    'text'       => 'placeholder',
                ],
                'chat_instance' => 'ci_' . $tgId,
                'data'          => $data,
            ])))->handle();
        } catch (\Throwable $e) {
            // Успешный путь строит фото через Request::encodeFile() (фетч example.com на стенде) —
            // известный артефакт стенда, эффект проверяется по БД; любое другое исключение — не наше.
            $known = str_contains($e->getMessage(), 'encodeFile')
                || (str_contains($e->getMessage(), 'fopen') && str_contains($e->getMessage(), 'example.com'));
            if (! $known) {
                throw $e;
            }
        }
    }
}
