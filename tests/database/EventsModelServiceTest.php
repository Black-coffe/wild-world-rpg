<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Events\EventsModelService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * w2-n5-deeds-02 — модель «🎉 События» {@see EventsModelService}: активные + 3 прошедших, `touched` по
 * `effect_log`, без `chat_id` и Markdown.
 *
 * `events` — вручную: миграция CreateEventsTable не проходит на MySQL 8 (`img_path TEXT` с default);
 * `active_events` и `biomes` — прогоном миграций.
 *
 * @internal
 */
final class EventsModelServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-04-04-090501_CreateActiveEventsTable',
        '2026-05-05-150000_AddEffectLogToActiveEvents',
    ];

    private const TABLES = ['biomes', 'events', 'active_events'];

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query(
                'CREATE TABLE events (event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_english VARCHAR(255) NULL,'
                . " description TEXT NULL, biome_ids TEXT NULL, event_type ENUM('local','global') NOT NULL, effect_type ENUM('damage','heal','buff','debuff','none') NOT NULL)"
            );
            $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1), (2, 'Болото', 2)");
            $this->conn->query(
                "INSERT INTO events (event_id, name, description, biome_ids, event_type, effect_type) VALUES (1, 'Кислотный *дождь*', 'Жжёт.', '[1,2]', 'local', 'damage'), (2, 'Туман', 'Серо.', NULL, 'global', 'none')"
            );
            $now = time();
            $this->conn->query(
                'INSERT INTO active_events (event_id, start_time, end_time, status, effect_log) VALUES'
                . " (1, ?, ?, 'active', '{\"1\": 5}'),"
                . " (2, '2026-09-20 17:50:00', '2026-09-20 18:13:00', 'completed', '{\"4\": 1}'),"
                . " (2, '2026-09-18 10:00:00', '2026-09-18 10:10:00', 'completed', NULL),"
                . " (2, '2026-09-17 10:00:00', '2026-09-17 10:10:00', 'completed', NULL),"
                . " (2, '2026-09-16 10:00:00', '2026-09-16 10:10:00', 'completed', NULL)",
                [date('Y-m-d H:i:s', $now - 60), date('Y-m-d H:i:s', $now + 3600)]
            );
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testActiveEventCarriesPlainFieldsAndTouched(): void
    {
        $model = (new EventsModelService())->model(1);

        $this->assertCount(1, $model['active']);
        $event = $model['active'][0];
        $this->assertSame('Кислотный *дождь*', $event['name'], 'сырой текст — экранирует рендерер');
        $this->assertSame('Выборочно в указанных биомах', $event['where_ru']);
        $this->assertSame('Урон', $event['effect_ru']);
        $this->assertSame(['Лес', 'Болото'], $event['biomes']);
        $this->assertTrue($event['touched']);
        $this->assertNotSame('', $event['end_time']);
    }

    public function testHistoryKeepsTheLastThreeAndMarksWhoWasHit(): void
    {
        $past = (new EventsModelService())->model(4)['past'];

        $this->assertCount(EventsModelService::HISTORY_LIMIT, $past);
        $this->assertSame('20 сентября, 17:50', $past[0]['start_ru']);
        $this->assertSame('23 мин.', $past[0]['duration']);
        $this->assertTrue($past[0]['touched']);
        $this->assertFalse($past[1]['touched']);
        $this->assertFalse((new EventsModelService())->model(0)['active'][0]['touched'], 'без персонажа — не задело');
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
