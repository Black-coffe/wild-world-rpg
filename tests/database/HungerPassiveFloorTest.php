<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\AddHungerLossColumnsToCharacters;
use App\Services\Player\Death\DeathMessageBuilder;
use App\Services\Player\PlayerDetectionService;
use App\Services\PVE\TributeService;
use App\TaskHandlers\FoodAndWaterConsumptionHandler;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\WipeManifest;
use ReflectionClass;
use ReflectionMethod;

/**
 * ADR-200 — суточный голод ранит, но не добивает: здоровье делится, как было, но не опускается ниже
 * пассивного пола `health.passive_floor` (дефолт 1.0, выше порога рулетки смерти 0.99). Ниже пола голод
 * здоровье не меняет и не поднимает. Сообщение о голоде печатает фактическую просадку.
 *
 * До правки пол был 0.01: 1.5 HP → 0.75 → рулетка в ту же минуту.
 *
 * Схема — по факту отсутствия таблицы; свои таблицы дропаются в tearDown, свои строки удаляются. Колонки
 * последней просадки (`hunger_hp_lost`, `hunger_hp_lost_at`) — из настоящей миграции; добавленные ею в чужую
 * таблицу снимаются в tearDown.
 *
 * @internal
 */
final class HungerPassiveFloorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** @var list<string> */
    private array $createdTables = [];

    /** @var array<string, list<int>> */
    private array $ownRows = [];

    private bool $addedHungerColumns = false;

    protected function setUp(): void
    {
        parent::setUp();
        HungerSendSpy::$captions = [];

        $this->createIfMissing('telegram_users', '
            CREATE TABLE telegram_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL,
                username VARCHAR(255) NULL,
                first_name VARCHAR(255) NULL,
                last_name VARCHAR(255) NULL,
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
                level INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ');
        $this->createIfMissing('action_log', "
            CREATE TABLE action_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                character_id INT UNSIGNED NOT NULL,
                chat_id BIGINT UNSIGNED NULL,
                action_name VARCHAR(255) NOT NULL,
                action_status ENUM('Pending','Completed','Skipped','REJECTED') NOT NULL DEFAULT 'Pending',
                description TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ");
        $this->addHungerColumns();
    }

    protected function tearDown(): void
    {
        try {
            $db = $this->db();
            if (($this->ownRows['characters'] ?? []) !== [] && $db->tableExists('action_log')) {
                $db->table('action_log')->whereIn('character_id', $this->ownRows['characters'])->delete();
            }
            foreach ($this->ownRows as $table => $ids) {
                if ($ids !== []) {
                    $db->table($table)->whereIn('id', $ids)->delete();
                }
            }
            if ($this->addedHungerColumns && ! in_array('characters', $this->createdTables, true)) {
                $this->hungerMigration()->down();
            }
            foreach (array_reverse($this->createdTables) as $table) {
                $db->query("DROP TABLE IF EXISTS {$table}");
            }
            $db->resetDataCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testHungerStopsAtPassiveFloor(): void
    {
        $charId = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 1.5, 'level' => 10]);

        $this->hunger($charId, 0.75);

        $this->assertEqualsWithDelta(1.0, $this->health($charId), 0.0001, 'hunger went below the passive floor');
    }

    public function testHungerBelowFloorNeitherLowersNorHeals(): void
    {
        $charId = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 0.6, 'level' => 10]);

        $this->hunger($charId, 0.3);

        $this->assertEqualsWithDelta(0.6, $this->health($charId), 0.0001, 'below the floor hunger leaves HP as is');
    }

    public function testHungerAboveFloorHalvesAsBefore(): void
    {
        $charId = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 80, 'level' => 10]);

        $this->hunger($charId, 40.0);

        $this->assertEqualsWithDelta(40.0, $this->health($charId), 0.0001, 'above the floor hunger halves HP as before');
    }

    public function testHungerMessagePrintsTheRealDrop(): void
    {
        $tgId   = $this->insertTracked('telegram_users', ['telegram_id' => 777000123]);
        $charId = $this->insertTracked('characters', ['telegram_user_id' => $tgId, 'health' => 1.5, 'level' => 10]);

        $this->hunger($charId, 0.75);

        $this->assertCount(1, HungerSendSpy::$captions, 'one hunger message');
        $caption = HungerSendSpy::$captions[0];
        $this->assertStringContainsString('просело на:* 0.5', $caption, 'message shows the applied drop, not the requested one');
        $this->assertStringContainsString('Текущее здоровье:* 1', $caption);
    }

    /**
     * Ревью раунд 1 (ask 7): фактическая просадка от голода записана с временем туда, где её читает сообщение о
     * смерти; голод, не тронувший здоровье (ниже пола), записи не оставляет. Ревью раунд 2: это строка персонажа
     * (`hunger_hp_lost`, `hunger_hp_lost_at`), не `action_log`.
     */
    public function testHungerRecordsTheAppliedDropForTheDeathMessage(): void
    {
        $charId = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 1.5, 'level' => 10]);
        $belowFloor = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 0.6, 'level' => 10]);

        $this->hunger($charId, 0.75);
        $this->hunger($belowFloor, 0.3);

        $row = $this->db()->table('characters')->select('hunger_hp_lost, hunger_hp_lost_at')->where('id', $charId)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertIsNumeric($row['hunger_hp_lost'] ?? null, 'the drop is kept on the character row');
        $this->assertEqualsWithDelta(0.5, (float) $row['hunger_hp_lost'], 0.0001);
        $this->assertNotNull($row['hunger_hp_lost_at'] ?? null);

        $builder = new DeathMessageBuilder();
        $hunger  = $builder->recentHunger($charId, time());
        $this->assertNotNull($hunger, 'hunger drop is recorded');
        $this->assertEqualsWithDelta(0.5, $hunger['hp'], 0.0001, 'the applied drop, not the requested half');
        $this->assertLessThanOrEqual(1, abs(time() - (int) strtotime($hunger['time'])) / 60);
        $this->assertNull($builder->recentHunger($belowFloor, time()), 'no drop — no record');
        $this->assertNull($builder->recentHunger($charId, time() + (DeathMessageBuilder::HUNGER_WINDOW_MINUTES + 5) * 60), 'outside the window');
    }

    /** Сообщение о смерти читает просадку из строки персонажа: 25 ❤️ 90 минут назад — «голод: ❤️25, 90 мин назад». */
    public function testDeathMessageReadsTheDropFromTheCharacterRow(): void
    {
        $now    = time();
        $charId = $this->insertTracked('characters', [
            'telegram_user_id'  => null,
            'health'            => 0.5,
            'level'             => 10,
            'hunger_hp_lost'    => 25,
            'hunger_hp_lost_at' => date('Y-m-d H:i:s', $now - 90 * 60),
        ]);

        $hunger = (new DeathMessageBuilder())->recentHunger($charId, $now);

        $this->assertNotNull($hunger, 'the drop on the character row is read');
        $this->assertEqualsWithDelta(25.0, $hunger['hp'], 0.0001);
        $text = DeathMessageBuilder::causeBlock([], null, 0.5, $now, $hunger);
        $this->assertStringContainsString('голод', $text);
        $this->assertStringContainsString('❤️25', $text);
        $this->assertStringContainsString('90 мин', $text);
    }

    /**
     * Ревью раунд 2: голод не оставляет строки в `action_log`, и читатели активности игрока (детект брошенных,
     * анти-сговор подати) не видят двух голодных персонажей ни активными, ни одновременно активными.
     */
    public function testHungerIsNotReadAsPlayerActivity(): void
    {
        $a = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 100, 'level' => 10]);
        $b = $this->insertTracked('characters', ['telegram_user_id' => null, 'health' => 100, 'level' => 10]);

        $this->hunger($a, 50.0);
        $this->hunger($b, 50.0);

        $this->assertEqualsWithDelta(50.0, $this->health($a), 0.0001, 'hunger did drop HP');
        $this->assertSame(0, $this->db()->table('action_log')->whereIn('character_id', [$a, $b])->countAllResults(), 'no action_log row from hunger');

        $detection = (new ReflectionClass(PlayerDetectionService::class))->newInstanceWithoutConstructor();
        $map       = (new ReflectionMethod(PlayerDetectionService::class, 'fetchLastActiveMap'))->invoke($detection, [$a, $b]);
        $this->assertSame([], array_filter(is_array($map) ? $map : []), 'a starving idle character does not look active');

        $tribute = (new ReflectionClass(TributeService::class))->newInstanceWithoutConstructor();
        $ts      = new ReflectionMethod(TributeService::class, 'actionTimestamps');
        $since   = date('Y-m-d H:i:s', time() - 86400);
        $tsA     = $ts->invoke($tribute, $a, $since);
        $tsB     = $ts->invoke($tribute, $b, $since);
        $this->assertIsArray($tsA);
        $this->assertIsArray($tsB);
        $this->assertSame(0, TributeService::countConcurrent(array_values($tsA), array_values($tsB), 120), 'two hungry accounts are not concurrent activity');
    }

    /** Колонки — состояние персонажа: вайп их обнуляет. */
    public function testWipeResetsTheHungerColumns(): void
    {
        $values = (new WipeManifest())->characterResetValues;
        $this->assertArrayHasKey('hunger_hp_lost', $values);
        $this->assertNull($values['hunger_hp_lost']);
        $this->assertArrayHasKey('hunger_hp_lost_at', $values);
        $this->assertNull($values['hunger_hp_lost_at']);
    }

    private function hunger(int $charId, float $requested): void
    {
        $handler = new HungerSendSpy();
        $m       = new ReflectionMethod(FoodAndWaterConsumptionHandler::class, 'subtractHealth');
        $m->invoke($handler, ['id' => $charId, 'health' => $this->health($charId)], $requested, 1.0, 1.0);
    }

    private function health(int $charId): float
    {
        $row = $this->db()->table('characters')->select('health')->where('id', $charId)->get()->getRowArray();

        return is_array($row) && is_numeric($row['health'] ?? null) ? (float) $row['health'] : -1.0;
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect('tests');
    }

    private function addHungerColumns(): void
    {
        $db = $this->db();
        $db->resetDataCache();
        if ($db->fieldExists('hunger_hp_lost', 'characters') && $db->fieldExists('hunger_hp_lost_at', 'characters')) {
            return;
        }
        $this->hungerMigration()->up();
        $db->resetDataCache();
        $this->addedHungerColumns = true;
    }

    private function hungerMigration(): AddHungerLossColumnsToCharacters
    {
        if (! class_exists(AddHungerLossColumnsToCharacters::class, false)) {
            require_once APPPATH . 'Database/Migrations/2026-12-28-100000_AddHungerLossColumnsToCharacters.php';
        }
        $forge = Database::forge('tests');

        return new AddHungerLossColumnsToCharacters($forge instanceof Forge ? $forge : null);
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
}

final class HungerSendSpy extends FoodAndWaterConsumptionHandler
{
    /** @var list<string> */
    public static array $captions = [];

    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
        self::$captions[] = $caption;
    }
}
