<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Player\Death\DeathMessageBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-200 (sudden-death-1125 S4, ask 7) — откуда сообщение о смерти берёт причину: удары событий из
 * `event_effects_log` за окно (фактически применённый `health_delta`, а не запрошенный), иначе идущий или
 * только что кончившийся Поход с его накопленным `acc.hp`; без таблиц — молча «ничего», текст смерти не падает.
 *
 * @internal
 */
final class DeathCauseLookupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['event_effects_log', 'events', 'character_tasks', 'tasks'];

    private const CHAR = 41;

    /** @var \CodeIgniter\Database\BaseConnection<object, object> */
    private $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->drop();
        $this->conn->query('CREATE TABLE events (event_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_english VARCHAR(255) NOT NULL)');
        $this->conn->query('CREATE TABLE event_effects_log (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, event_id INT NOT NULL,
            cell_number INT NULL, biome_id INT NULL, effect_details TEXT NOT NULL, event_time DATETIME NOT NULL, additional_info TEXT NULL)');
        $this->conn->query('CREATE TABLE tasks (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL)');
        $this->conn->query("CREATE TABLE character_tasks (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, task_id INT NOT NULL,
            start_time DATETIME NULL, end_time DATETIME NULL, status VARCHAR(20) NOT NULL DEFAULT 'in_work', task_settings TEXT NULL)");
        $this->conn->query("INSERT INTO tasks (id, name) VALUES (3, 'Marching'), (4, 'Gather')");
        $this->conn->query("INSERT INTO events (event_id, name, name_english) VALUES (7, 'Подземные толчки', 'Tremor'), (9, 'Чистый родник', 'CleanSpring')");
    }

    protected function tearDown(): void
    {
        $this->drop();
        parent::tearDown();
    }

    public function testHitsInTheWindowCarryTheAppliedDeltaAndTheEventName(): void
    {
        $now = time();
        $this->hit(7, -40.0, -45.0, $now - 25 * 60);
        $this->hit(9, 5.0, 5.0, $now - 20 * 60);
        $this->hit(7, -30.0, -38.0, $now - 3 * 60);
        $this->hit(7, -50.0, -50.0, $now - (DeathMessageBuilder::CAUSE_WINDOW_MINUTES + 5) * 60); // вне окна
        $this->conn->query('INSERT INTO event_effects_log (character_id, event_id, effect_details, event_time) VALUES (?, 7, ?, ?)', [
            self::CHAR + 1, json_encode(['health_delta' => -60.0]), date('Y-m-d H:i:s', $now - 60),
        ]); // чужой персонаж

        $hits = (new DeathMessageBuilder())->recentHits(self::CHAR, $now);

        $this->assertSame([-40.0, 5.0, -30.0], array_column($hits, 'health_delta'), 'применённая дельта, окно, только свой персонаж');
        $this->assertSame(['Подземные толчки', 'Чистый родник', 'Подземные толчки'], array_column($hits, 'name'));

        $text = DeathMessageBuilder::causeBlock($hits, null, 0.5, $now);
        $this->assertStringContainsString('❤️70', $text);
        $this->assertStringContainsString('25 мин', $text);
    }

    public function testMarchRunningOrJustFinishedIsFoundWithItsAccumulatedHealth(): void
    {
        $now = time();
        $this->assertNull((new DeathMessageBuilder())->recentMarch(self::CHAR, $now), 'похода нет');

        // Цепочка шагов одного похода: каждая строка — шаг, acc копится; поход начат 40 минут назад.
        $this->march('completed', $now - 40 * 60, ['started_cell' => 500, 'steps_done' => 1, 'acc' => ['hp' => 1.02]]);
        $this->march('completed', $now - 20 * 60, ['started_cell' => 500, 'steps_done' => 6, 'acc' => ['hp' => 2.5]]);
        $this->march('completed', $now - 2 * 60, ['started_cell' => 500, 'steps_done' => 12, 'acc' => ['hp' => 3.5]]);

        $march = (new DeathMessageBuilder())->recentMarch(self::CHAR, $now);
        $this->assertNotNull($march);
        $this->assertEqualsWithDelta(3.5, $march['hp'], 0.0001);
        $this->assertSame(12, $march['cells']);
        $this->assertSame(date('Y-m-d H:i:s', $now - 40 * 60), $march['started'], 'начало похода — первая строка цепочки');
    }

    public function testOldMarchOutsideTheWindowIsNotTheCause(): void
    {
        $now = time();
        $this->march('completed', $now - (DeathMessageBuilder::CAUSE_WINDOW_MINUTES + 30) * 60, ['started_cell' => 500, 'steps_done' => 3, 'acc' => ['hp' => 3.0]]);

        $this->assertNull((new DeathMessageBuilder())->recentMarch(self::CHAR, $now));
    }

    public function testMissingTablesGiveNothingInsteadOfAnError(): void
    {
        $this->drop();

        $builder = new DeathMessageBuilder();
        $this->assertSame([], $builder->recentHits(self::CHAR, time()));
        $this->assertNull($builder->recentMarch(self::CHAR, time()));
    }

    private function hit(int $eventId, float $applied, float $requested, int $at): void
    {
        $this->conn->query('INSERT INTO event_effects_log (character_id, event_id, effect_details, event_time) VALUES (?, ?, ?, ?)', [
            self::CHAR, $eventId, json_encode(['health_delta' => $applied, 'health_delta_requested' => $requested]), date('Y-m-d H:i:s', $at),
        ]);
    }

    /** @param array<string, mixed> $settings */
    private function march(string $status, int $start, array $settings): void
    {
        $this->conn->query('INSERT INTO character_tasks (character_id, task_id, start_time, end_time, status, task_settings) VALUES (?, 3, ?, ?, ?, ?)', [
            self::CHAR, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start), $status, json_encode($settings),
        ]);
    }

    private function drop(): void
    {
        foreach (self::TABLES as $t) {
            $this->conn->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->conn->resetDataCache();
    }
}
