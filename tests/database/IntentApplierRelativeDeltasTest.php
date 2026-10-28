<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Services\Events\IntentApplier;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * F16 (s2-conditional-writes-06) — крон мировых событий пишет дельты относительно, а не со снимка.
 *
 * Форма: один загруженный снимок персонажа, две дельты подряд — в БД сумма обеих; минус золота
 * больше баланса — 0; пол 0.01 у здоровья; метеор, чьё чтение ресурсов устарело (остаток упал
 * до 1 после SELECT, потеря 3) — 0, не −2. Крон-путь, без Telegram.
 *
 * Схема — по факту отсутствия таблицы; свои таблицы дропаются в tearDown, свои строки удаляются.
 *
 * @internal
 */
final class IntentApplierRelativeDeltasTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const RED_REASON = 'event delta written from a snapshot: second delta lost, or gold went negative';

    /** @var list<string> */
    private array $createdTables = [];

    /** @var array<string, list<int>> */
    private array $ownRows = [];

    /** @var (callable(object): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->db();
        $db->resetDataCache();

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

        // Общий стенд: соседний тест мог оставить свою урезанную `characters`/`resources`
        // (например, только id + active_vehicle_log_id). Не дропаем чужое — только добавляем
        // недостающие колонки, которые нужны этому тесту.
        $this->ensureColumns('characters', [
            'gold' => 'INT NULL DEFAULT 0', 'health' => 'DECIMAL(10,2) NULL DEFAULT 100',
            'tired' => 'DECIMAL(10,2) NULL DEFAULT 100', 'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
        ]);
        $this->ensureColumns('resources', [
            'name' => 'VARCHAR(255) NULL', 'name_en' => 'VARCHAR(255) NULL', 'keyword' => 'VARCHAR(100) NULL',
            'biome_id' => 'TEXT NULL', 'rarity' => 'INT NULL DEFAULT 1',
            'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
        ]);
        $this->ensureColumns('character_resources', [
            'id_characters' => 'INT NULL', 'id_resources' => 'INT NULL', 'quantity' => 'INT NULL DEFAULT 0',
            'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
        ]);
    }

    /**
     * @param array<string, string> $columns
     */
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
                $db->query("DROP TABLE IF EXISTS {$table}");
            }
            $db->resetDataCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testTwoGoldDeltasFromOneSnapshotBothLand(): void
    {
        $charId   = $this->insertTracked('characters', ['gold' => 0, 'health' => 50, 'tired' => 50]);
        $snapshot = ['id' => $charId, 'gold' => 0, 'health' => 50.0, 'tired' => 50.0];

        $applier = $this->applier();
        $applier->apply($snapshot, ['gold_delta' => 5]);
        $applier->apply($snapshot, ['gold_delta' => 5]);

        $this->assertSame(10, (int) $this->charField($charId, 'gold'), self::RED_REASON);
    }

    public function testGoldMinusBeyondBalanceClampsToZero(): void
    {
        $charId = $this->insertTracked('characters', ['gold' => 10, 'health' => 50, 'tired' => 50]);

        $this->applier()->apply(['id' => $charId, 'gold' => 10, 'health' => 50.0, 'tired' => 50.0], ['gold_delta' => -30]);

        $this->assertSame(0, (int) $this->charField($charId, 'gold'), self::RED_REASON);
    }

    public function testHealthFloorStaysAtOneHundredth(): void
    {
        $charId = $this->insertTracked('characters', ['gold' => 0, 'health' => 0.5, 'tired' => 50]);

        $this->applier()->apply(['id' => $charId, 'gold' => 0, 'health' => 0.5, 'tired' => 50.0], ['health_delta' => -1.0]);

        $this->assertEqualsWithDelta(0.01, (float) $this->charField($charId, 'health'), 0.0001, self::RED_REASON);
    }

    public function testTwoResourceGrantsSum(): void
    {
        $charId  = $this->insertTracked('characters', ['gold' => 0, 'health' => 50, 'tired' => 50]);
        $keyword = 'ia_rel_' . random_int(100_000, 999_999);
        $resId   = $this->insertTracked('resources', [
            'name' => 'Тест ' . $keyword, 'name_en' => 'Test ' . $keyword, 'keyword' => $keyword, 'rarity' => 1,
        ]);
        $snapshot = ['id' => $charId, 'gold' => 0, 'health' => 50.0, 'tired' => 50.0];
        $intent   = ['keyword' => $keyword, 'biome_id' => null, 'rarity' => null, 'amount' => 1];

        $applier = $this->applier();
        $applier->apply($snapshot, ['resource_grant_intents' => [$intent]]);
        $applier->apply($snapshot, ['resource_grant_intents' => [$intent]]);

        $rows = $this->db()->table('character_resources')
            ->where('id_characters', $charId)->where('id_resources', $resId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->ownRows['character_resources'][] = (int) $row['id'];
        }
        $total = array_sum(array_map(static fn (array $r): int => (int) $r['quantity'], $rows));

        $this->assertSame(2, $total, self::RED_REASON);
    }

    public function testMeteorOnStaleReadNeverGoesNegative(): void
    {
        $charId = $this->insertTracked('characters', ['gold' => 0, 'health' => 50, 'tired' => 50]);
        $suffix = random_int(100_000, 999_999);
        $resId  = $this->insertTracked('resources', ['name' => 'Метеор ' . $suffix, 'name_en' => 'Meteor ' . $suffix, 'rarity' => 1]);
        $rowId  = $this->insertTracked('character_resources', [
            'id_characters' => $charId, 'id_resources' => $resId, 'quantity' => 4,
        ]);

        // Крон прочитал quantity = 4 (потеря 75% → 3); сразу после чтения остаток падает до 1.
        $fired = false;
        $this->listener = function (object $query) use (&$fired, $rowId): void {
            if ($fired) {
                return;
            }
            $sql = ltrim((string) $query->getQuery());
            if (! str_starts_with($sql, 'SELECT') || ! str_contains($sql, 'character_resources')) {
                return;
            }
            $fired = true;
            Database::connect('tests', false)->query('UPDATE character_resources SET quantity = 1 WHERE id = ?', [$rowId]);
        };
        Events::on('DBQuery', $this->listener);

        $this->applier()->apply(['id' => $charId, 'gold' => 0, 'health' => 50.0, 'tired' => 50.0], ['resource_loss_percent' => 75.0]);

        $this->assertTrue($fired, 'предусловие: метеор прочитал ресурсы до падения остатка');
        $row = $this->db()->table('character_resources')->where('id', $rowId)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame(0, (int) $row['quantity'], self::RED_REASON);
    }

    private function applier(): IntentApplier
    {
        return new IntentApplier(
            new CharacterModel(),
            new CharacterResourceModel(),
            new CharacterTaskModel(),
            new ResourceModel(),
            new TaskModel(),
        );
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect('tests');
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

    /**
     * @param array<string, mixed> $data
     */
    private function insertTracked(string $table, array $data): int
    {
        $this->db()->table($table)->insert($data);
        $id = (int) $this->db()->insertID();
        $this->ownRows[$table][] = $id;

        return $id;
    }

    private function charField(int $charId, string $field): mixed
    {
        $row = $this->db()->table('characters')->select($field)->where('id', $charId)->get()->getRowArray();

        return is_array($row) ? ($row[$field] ?? null) : null;
    }
}
