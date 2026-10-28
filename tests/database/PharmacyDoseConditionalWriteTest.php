<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\UsePharmacyAction;
use App\Database\Migrations\CreateCharacterDebuffs;
use CodeIgniter\Database\Forge;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Telegram;

/**
 * F14 (s2-conditional-writes-05) — доза препарата списывается условной записью ДО лечения, снятия
 * дебаффов и «сытости».
 *
 * Гейт `UsePharmacyAction::handle()` читает строку `crafted_items_log` (есть ли препарат). Сразу
 * после этого чтения другое соединение обнуляет строку (`quantity = 0`, `durability_count = 0`):
 * следующий запрос использует последнюю дозу раньше. Настоящий `handle()` вызывается целиком,
 * обнуление вклинивается через `DBQuery`-событие (тот же приём, что `PlantCropSeedConditionalWriteTest`).
 * Ожидание — отказ без эффектов: здоровье не выросло, ожог не снят, строка не ушла в минус.
 *
 * Схема строится DDL только при отсутствии таблицы; у чужой урезанной `characters` добавляются
 * недостающие колонки. Созданное этим тестом — дропается, чужое — нет.
 *
 * @internal
 */
final class PharmacyDoseConditionalWriteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED_REASON = 'pharmacy effects applied although the DB held zero doses';

    /** @var list<string> таблицы, созданные ЭТИМ тестом. */
    private array $createdTables = [];

    /** @var array<string, list<int>> id строк, вставленных этим тестом, по таблице. */
    private array $ownRows = [];

    /** @var array{id:int, value_bool:mixed}|null исходное значение чужой строки `debuff.enabled`. */
    private ?array $restoreSetting = null;

    /** @var (callable(object): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $db = $this->db();
        $db->resetDataCache();
        $this->cleanCache();

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
                name VARCHAR(100) NULL,
                level INT DEFAULT 1,
                health DECIMAL(7,2) DEFAULT 100,
                tired DECIMAL(7,2) DEFAULT 100,
                strength DECIMAL(7,2) DEFAULT 1,
                agility DECIMAL(7,2) DEFAULT 1,
                intellect DECIMAL(7,2) DEFAULT 1,
                gold INT DEFAULT 0,
                experience DECIMAL(15,2) DEFAULT 0,
                npc_kills INT DEFAULT 0,
                cell_number INT DEFAULT 0,
                biome_id INT NULL,
                well_fed_until DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) AUTO_INCREMENT=' . random_int(3_000_000, 3_999_999));
        $this->createIfMissing('crafted_items', '
            CREATE TABLE crafted_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name_rus VARCHAR(255) NULL,
                name_eng VARCHAR(255) NULL,
                type VARCHAR(100) NULL,
                direction_craft VARCHAR(100) NULL,
                effect VARCHAR(255) NULL,
                gathering_speed FLOAT NULL,
                durability_count INT NULL,
                durability_time INT NULL,
                type_of_battle VARCHAR(100) NULL,
                damage INT NULL,
                armor INT NULL,
                hp INT NULL,
                character_boost TEXT NULL,
                weight DECIMAL(10,2) NULL,
                price DECIMAL(10,2) NULL DEFAULT 0,
                stack_size INT NULL,
                required_level INT NULL,
                required_skills TEXT NULL,
                required_resources TEXT NULL,
                crafting_time VARCHAR(100) NULL,
                crafting_location VARCHAR(255) NULL,
                description TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createIfMissing('crafted_items_log', '
            CREATE TABLE crafted_items_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT UNSIGNED NULL,
                task_id INT UNSIGNED NULL,
                crafted_item_id INT UNSIGNED NULL,
                type VARCHAR(100) NULL,
                direction_craft VARCHAR(100) NULL,
                crafting_location VARCHAR(255) NULL DEFAULT "all",
                durability_count INT DEFAULT 0,
                durability_time DATE NULL,
                quantity INT DEFAULT 1,
                custom_setting TEXT NULL,
                insured TINYINT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        // Колонки — 2026-11-24-100000_CreateCharacterDebuffs.php без FK на characters: на общем стенде
        // `characters` мог создать сосед со знаковым id, и FK тогда не встаёт.
        $this->createIfMissing('character_debuffs', '
            CREATE TABLE character_debuffs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT UNSIGNED NOT NULL,
                debuff_key VARCHAR(32) NOT NULL,
                severity TINYINT UNSIGNED DEFAULT 1,
                source VARCHAR(64) NULL,
                applied_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                last_tick_at DATETIME NULL,
                cured_at DATETIME NULL,
                cured_by_item VARCHAR(64) NULL,
                expired_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                KEY idx_active (character_id, cured_at, expired_at)
            )
        ');

        // Общий стенд: соседний тест мог оставить свою урезанную `characters` (например, только
        // id + active_vehicle_log_id + gold/health/tired). Чужое не дропаем — только добавляем
        // недостающие колонки, которые нужны handler'у (так же — `crafted_items`/`crafted_items_log`).
        $this->ensureColumns('characters', [
            'telegram_user_id' => 'INT NULL', 'name' => 'VARCHAR(100) NULL', 'level' => 'INT DEFAULT 1',
            'health' => 'DECIMAL(7,2) DEFAULT 100', 'tired' => 'DECIMAL(7,2) DEFAULT 100',
            'strength' => 'DECIMAL(7,2) DEFAULT 1', 'agility' => 'DECIMAL(7,2) DEFAULT 1',
            'intellect' => 'DECIMAL(7,2) DEFAULT 1', 'gold' => 'INT DEFAULT 0',
            'experience' => 'DECIMAL(15,2) DEFAULT 0', 'npc_kills' => 'INT DEFAULT 0',
            'cell_number' => 'INT DEFAULT 0', 'biome_id' => 'INT NULL', 'well_fed_until' => 'DATETIME NULL',
            'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
        ]);

        $this->ensureColumns('crafted_items', [
            'name_rus' => 'VARCHAR(255) NULL', 'name_eng' => 'VARCHAR(255) NULL', 'type' => 'VARCHAR(100) NULL',
            'direction_craft' => 'VARCHAR(100) NULL', 'effect' => 'VARCHAR(255) NULL',
            'durability_count' => 'INT NULL', 'durability_time' => 'INT NULL', 'stack_size' => 'INT NULL',
            'description' => 'TEXT NULL', 'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
        ]);
        $this->ensureColumns('crafted_items_log', [
            'character_id' => 'INT UNSIGNED NULL', 'task_id' => 'INT UNSIGNED NULL',
            'crafted_item_id' => 'INT UNSIGNED NULL', 'type' => 'VARCHAR(100) NULL',
            'direction_craft' => 'VARCHAR(100) NULL', 'crafting_location' => 'VARCHAR(255) NULL',
            'durability_count' => 'INT DEFAULT 0', 'durability_time' => 'DATE NULL', 'quantity' => 'INT DEFAULT 1',
            'custom_setting' => 'TEXT NULL', 'insured' => 'TINYINT NULL',
            'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
        ]);

        // Состояния выключены по умолчанию (`debuff.enabled` = false): без них нечего снимать.
        $this->enableDebuffs();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->listener !== null) {
                Events::removeListener('DBQuery', $this->listener);
                $this->listener = null;
            }
            $db = $this->db();
            if ($this->restoreSetting !== null) {
                $db->table('game_settings')->where('id', $this->restoreSetting['id'])
                    ->update(['value_bool' => $this->restoreSetting['value_bool']]);
                $this->restoreSetting = null;
            }
            foreach ($this->ownRows as $table => $ids) {
                if ($ids !== [] && $db->tableExists($table)) {
                    $db->table($table)->whereIn('id', $ids)->delete();
                }
            }
            foreach (array_reverse($this->createdTables) as $table) {
                $db->query("DROP TABLE IF EXISTS {$table}");
            }
            $db->resetDataCache();
            $this->cleanCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testZeroDosesAfterGateRefusesWithoutEffects(): void
    {
        $tgId   = random_int(740_000_000, 749_999_999);
        $tgUid  = $this->insertTracked('telegram_users', ['telegram_id' => $tgId]);
        $charId = $this->insertTracked('characters', [
            'telegram_user_id' => $tgUid, 'health' => 40, 'tired' => 40,
        ]);

        // Bandage: legacy-ветка (+2 здоровья) и снимает ожог (`Config\Debuffs::BURN`).
        $itemId = $this->catalogRowId('crafted_items', 'name_eng', 'Bandage', [
            'name_rus' => 'Бинт', 'type' => 'drug', 'durability_count' => 1,
        ]);
        $logId = $this->insertTracked('crafted_items_log', [
            'character_id' => $charId, 'crafted_item_id' => $itemId, 'quantity' => 1, 'durability_count' => 1,
        ]);
        $debuffId = $this->insertTracked('character_debuffs', [
            'character_id' => $charId, 'debuff_key' => 'burn', 'severity' => 1, 'source' => 'test',
            'applied_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $healthBefore = $this->health($charId);

        // Гейт прочитал quantity = 1; сразу после этого чтения строку обнуляет отдельное соединение.
        $fired = false;
        $this->listener = function (object $query) use (&$fired, $logId): void {
            if ($fired) {
                return;
            }
            $sql = ltrim((string) $query->getQuery());
            if (! str_starts_with($sql, 'SELECT') || ! str_contains($sql, 'crafted_items_log')) {
                return;
            }
            $fired = true;
            Database::connect('tests', false)->query(
                'UPDATE crafted_items_log SET quantity = 0, durability_count = 0 WHERE id = ?',
                [$logId]
            );
        };
        Events::on('DBQuery', $this->listener);

        (new UsePharmacyAction($this->callbackQuery($tgId, 'usePharmacy_Bandage')))->handle();

        $this->assertTrue($fired, 'предусловие: гейт прочитал препарат до обнуления');
        $this->assertSame($healthBefore, $this->health($charId), self::RED_REASON);

        $debuff = $this->row('character_debuffs', $debuffId);
        $this->assertNotNull($debuff, self::RED_REASON);
        $this->assertNull($debuff['cured_at'] ?? null, self::RED_REASON);

        $log = $this->row('crafted_items_log', $logId);
        if ($log !== null) {
            $this->assertGreaterThanOrEqual(0, (int) $log['quantity'], self::RED_REASON);
            $this->assertGreaterThanOrEqual(0, (int) $log['durability_count'], self::RED_REASON);
        }
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect('tests');
    }

    private function cleanCache(): void
    {
        $c = cache();
        if (is_object($c) && method_exists($c, 'clean')) {
            $c->clean();
        }
    }

    private function enableDebuffs(): void
    {
        $found = $this->db()->table('game_settings')->where('setting_key', 'debuff.enabled')->get();
        $row   = $found === false ? null : $found->getRowArray();
        if (is_array($row) && isset($row['id'])) {
            $this->restoreSetting = ['id' => (int) $row['id'], 'value_bool' => $row['value_bool'] ?? null];
            $this->db()->table('game_settings')->where('id', (int) $row['id'])
                ->update(['value_type' => 'bool', 'value_bool' => 1]);

            return;
        }
        $this->insertTracked('game_settings', [
            'setting_key' => 'debuff.enabled', 'category' => 'debuff', 'value_type' => 'bool', 'value_bool' => 1,
        ]);
    }

    /** @param array<string, string> $columns */
    private function ensureColumns(string $table, array $columns): void
    {
        $db = $this->db();
        $db->resetDataCache();
        $have = $db->getFieldNames($table);
        foreach ($columns as $name => $ddl) {
            if (! in_array($name, $have, true)) {
                $db->query("ALTER TABLE {$table} ADD COLUMN {$name} {$ddl}");
            }
        }
        $db->resetDataCache();
    }

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

    /** @param array<string, mixed> $data */
    private function insertTracked(string $table, array $data): int
    {
        $this->db()->table($table)->insert($data);
        $id = (int) $this->db()->insertID();
        $this->ownRows[$table][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $insert */
    private function catalogRowId(string $table, string $column, string $value, array $insert): int
    {
        $found = $this->db()->table($table)->where($column, $value)->orderBy('id', 'ASC')->limit(1)->get();
        $row   = $found === false ? null : $found->getRowArray();
        if (is_array($row) && isset($row['id'])) {
            return (int) $row['id'];
        }

        return $this->insertTracked($table, $insert + [$column => $value]);
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $id): ?array
    {
        $found = $this->db()->table($table)->where('id', $id)->get();
        $row   = $found === false ? null : $found->getRowArray();

        return is_array($row) ? $row : null;
    }

    private function health(int $charId): float
    {
        $row = $this->row('characters', $charId);

        return is_numeric($row['health'] ?? null) ? (float) $row['health'] : -1.0;
    }

    private function callbackQuery(int $tgId, string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'            => 'cbq_' . random_int(1, 999999999),
            'from'          => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message'       => [
                'message_id' => 1, 'date' => time(),
                'chat'       => ['id' => $tgId, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_' . $tgId,
            'data'          => $data,
        ]);
    }
}
