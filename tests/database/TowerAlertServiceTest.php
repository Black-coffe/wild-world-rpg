<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\CreateActionLogTable;
use App\Database\Migrations\CreateBuildingsTable;
use App\Database\Migrations\CreateCharacterBuildingsTable;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateFactionsTable;
use App\Database\Migrations\CreateGameSettingsTable;
use App\Database\Migrations\CreateMapTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\S26bSeedWatchTowerGameSettings;
use App\Services\PVE\TowerAlertService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * S26b (ADR-031) — TowerAlertService: alert-range detection вышки.
 *
 * Когда игрок перемещается, вышки чужих баз в радиусе пингуют владельцев.
 * Евклидова метрика (как PlayerDetection), exclude own, distinct-owner once,
 * cache-кулдаун, broken (hp=0) исключены. Telegram-доставка подменена стабом.
 *
 * pvp-detection-clarity-24 — схема строится прогоном настоящих классов миграций
 * (`feedback_test_schema_must_come_from_migration`), тем же приёмом, что
 * `RelocateAbandonedCharactersTest`/`StandoffAttackGateTest`: таблицы создаются
 * только если их ещё нет (`$created[...]`), дропаются в `tearDown()` в обратном
 * FK-порядке, только те, что создал этот прогон; уборка — и при падении `setUp()`
 * посередине. Раньше файл сам сносил семь общих таблиц (`DROP TABLE`) и пересоздавал
 * их рукописным DDL без FK — `action_log.character_id`/`chat_id` там были nullable,
 * тогда как реальная миграция (`CreateActionLogTable`) даёт NOT NULL + FK на
 * `characters`: проверки аудита и HTML-экранирования оставались зелёными даже
 * в сценарии, где вставка на проде отвалилась бы по констрейнту.
 *
 * `character_buildings.building_type` — реальный ENUM без значения `defensive`
 * (`CreateCharacterBuildingsTable`); его добавляет `S26AddDefensiveStructures`,
 * но та миграция тянет за собой `tasks.handler_key` → `events`/`world_objects`
 * (`AddHandlerKeyToEventsTasksWorldObjects`) — вне поверхности этого теста.
 * ENUM расширяется тем же DDL напрямую, как уже сделано в соседних тестах ветки
 * (`StandoffAttackGateTest`/`PvpStandoffServiceTest`/`DefenseStructureServiceTest`).
 * Содержимое строки `buildings` для WatchTower — из `S26bAddWatchTower` (сама
 * миграция не гоняется по той же причине про `tasks.handler_key`); GameSettings-ключи
 * `defense.tower.*` — из настоящей `S26bSeedWatchTowerGameSettings` (без посторонних
 * зависимостей, гоняется как есть).
 *
 * Владелец вышки (`character_buildings.character_id`) — реальный FK на `characters`:
 * прежний рукописный `placeTower()` заводил `character_buildings`-строку на владельца,
 * которого в `characters` не было вовсе (только двигающийся игрок создавался явно).
 * На строгой схеме это FK constraint violation, а не сервисный дефект — фикстура
 * теста была неполной. `placeTower()` теперь сам заводит владельца.
 *
 * @internal
 */
final class TowerAlertServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private BaseConnection $conn;

    /** @var array<string,bool> какие таблицы создал этот тест (только их и дропаем) */
    private array $created = [];

    private bool $alteredDefensiveEnum = false;
    private bool $seededTowerSettings  = false;
    private int $towerBuildingId       = 0;

    /** @var list<int> */
    private array $telegramUserIds = [];
    /** @var list<int> строки characters, заведённые этим тестом (id может быть явным) */
    private array $characterIds = [];
    /** @var list<int> */
    private array $mapIds = [];
    /** @var list<int> */
    private array $buildingRowIds = [];
    /** @var list<int> */
    private array $characterBuildingIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        // Как в RelocateAbandonedCharactersTest (pvp-detection-clarity-22, minor #I):
        // если что-то из этого посередине бросит исключение, PHPUnit не позовёт
        // tearDown() вовсе — ловим здесь и подчищаем ровно то, что успело создаться.
        try {
            $this->created['telegram_users']      = $this->createIfMissing('telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php');
            $this->created['characters']          = $this->createIfMissing('characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php');
            $this->created['buildings']           = $this->createIfMissing('buildings', CreateBuildingsTable::class, '2024-05-23-090819_CreateBuildingsTable.php');
            $this->created['factions']            = $this->createIfMissing('factions', CreateFactionsTable::class, '2024-05-15-131853_CreateFactionsTable.php');
            $this->created['map']                 = $this->createIfMissing('map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php');
            $this->created['game_settings']       = $this->createIfMissing('game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php');
            $this->created['character_buildings'] = $this->createIfMissing('character_buildings', CreateCharacterBuildingsTable::class, '2024-05-27-105534_CreateCharacterBuildingsTable.php');
            $this->created['action_log']          = $this->createIfMissing('action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php');

            if ($this->created['buildings'] || $this->created['character_buildings'] || ! $this->hasDefensiveEnumValue()) {
                $enum = "ENUM('military','residential','farming','resource','engineering','defensive')";
                $this->conn->query("ALTER TABLE buildings MODIFY building_type {$enum} NOT NULL");
                $this->conn->query("ALTER TABLE character_buildings MODIFY building_type {$enum} NOT NULL");
                $this->alteredDefensiveEnum = true;
            }

            $this->towerBuildingId = $this->ensureWatchTowerBuildingRow();

            $existing = $this->conn->table('game_settings')->where('setting_key', 'defense.tower.alert_range_cells')->get()->getRowArray();
            if (empty($existing)) {
                $this->requireMigration('S26bSeedWatchTowerGameSettings', '2026-05-20-710000_S26bSeedWatchTowerGameSettings.php');
                (new S26bSeedWatchTowerGameSettings())->up();
                $this->seededTowerSettings = true;
            }
        } catch (\Throwable $e) {
            $this->dropTrackedTables();
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        if (empty($this->created['action_log']) && $this->characterIds !== []) {
            $this->conn->table('action_log')
                ->whereIn('character_id', $this->characterIds)
                ->where('action_name', 'tower_alert_sent')
                ->delete();
        }
        if (empty($this->created['character_buildings']) && $this->characterBuildingIds !== []) {
            $this->conn->table('character_buildings')->whereIn('id', $this->characterBuildingIds)->delete();
        }
        if (empty($this->created['map']) && $this->mapIds !== []) {
            $this->conn->table('map')->whereIn('id', $this->mapIds)->delete();
        }
        if (empty($this->created['buildings']) && $this->buildingRowIds !== []) {
            $this->conn->table('buildings')->whereIn('id', $this->buildingRowIds)->delete();
        }
        if (empty($this->created['characters']) && $this->characterIds !== []) {
            $this->conn->table('characters')->whereIn('id', $this->characterIds)->delete();
        }
        if (empty($this->created['telegram_users']) && $this->telegramUserIds !== []) {
            $this->conn->table('telegram_users')->whereIn('id', $this->telegramUserIds)->delete();
        }

        if ($this->seededTowerSettings) {
            $this->requireMigration('S26bSeedWatchTowerGameSettings', '2026-05-20-710000_S26bSeedWatchTowerGameSettings.php');
            (new S26bSeedWatchTowerGameSettings())->down();
        }

        $this->dropTrackedTables();

        $this->telegramUserIds     = [];
        $this->characterIds        = [];
        $this->mapIds               = [];
        $this->buildingRowIds       = [];
        $this->characterBuildingIds = [];
        $this->alteredDefensiveEnum = false;
        $this->seededTowerSettings  = false;
        $this->towerBuildingId      = 0;

        $this->cleanCache();
        parent::tearDown();
    }

    /**
     * Обратный FK-порядок: action_log → character_buildings → characters/buildings/
     * factions/map → telegram_users; game_settings независим. Дропает только то,
     * что отмечено в `$this->created` — не трогает таблицу, которую стенд уже нёс
     * до запуска (pvp-detection-clarity-24, acceptance #2).
     */
    private function dropTrackedTables(): void
    {
        $this->conn->resetDataCache();

        $order = [
            ['action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php'],
            ['character_buildings', CreateCharacterBuildingsTable::class, '2024-05-27-105534_CreateCharacterBuildingsTable.php'],
            ['game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php'],
            ['map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php'],
            ['factions', CreateFactionsTable::class, '2024-05-15-131853_CreateFactionsTable.php'],
            ['buildings', CreateBuildingsTable::class, '2024-05-23-090819_CreateBuildingsTable.php'],
            ['characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php'],
            ['telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php'],
        ];

        foreach ($order as [$table, $class, $file]) {
            if (! empty($this->created[$table]) && $this->conn->tableExists($table)) {
                $short = substr($class, (int) strrpos($class, '\\') + 1);
                $this->requireMigration($short, $file);
                $forge = Database::forge('tests');
                (new $class($forge instanceof Forge ? $forge : null))->down();
            }
        }

        $this->created = [];
        $this->conn->resetDataCache();
    }

    private function createIfMissing(string $table, string $class, string $file): bool
    {
        if ($this->conn->tableExists($table)) {
            return false;
        }
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $this->requireMigration($short, $file);
        $forge = Database::forge('tests');
        (new $class($forge instanceof Forge ? $forge : null))->up();
        $this->conn->resetDataCache();

        return true;
    }

    private function requireMigration(string $shortClass, string $file): void
    {
        $class = 'App\\Database\\Migrations\\' . $shortClass;
        if (! class_exists($class, false)) {
            require_once APPPATH . 'Database/Migrations/' . $file;
        }
    }

    private function hasDefensiveEnumValue(): bool
    {
        $row  = $this->conn->query("SHOW COLUMNS FROM buildings LIKE 'building_type'")->getRowArray();
        $type = is_array($row) && isset($row['Type']) ? (string) $row['Type'] : '';

        return str_contains($type, 'defensive');
    }

    /** Содержимое — из S26bAddWatchTower (реальные production-значения WatchTower). */
    private function ensureWatchTowerBuildingRow(): int
    {
        $existing = $this->conn->table('buildings')->where('name_en', 'WatchTower')->get()->getRowArray();
        if (is_array($existing)) {
            return (int) $existing['id'];
        }
        $this->conn->table('buildings')->insert([
            'name_ru'             => 'Дозорная вышка',
            'name_en'             => 'WatchTower',
            'description'         => 'Наблюдательная вышка над базой.',
            'building_type'       => 'defensive',
            'hp'                  => 300,
            'construction_time'   => 90,
            'tax'                 => 700,
            'level'               => 1,
            'usage'               => 'personal',
            'required_resources'  => '{}',
            'min_character_level' => 12,
            'effects'             => '{"effect":"defense_tower"}',
        ]);
        $id                     = (int) $this->conn->insertID();
        $this->buildingRowIds[] = $id;

        return $id;
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }

    /** Заводит character-строку с явным id, если такой ещё нет (владелец или mover). */
    private function ensureCharacterRow(int $id, string $name): void
    {
        if ($this->conn->table('characters')->where('id', $id)->countAllResults() > 0) {
            return;
        }
        $this->conn->table('characters')->insert(['id' => $id, 'name' => $name]);
        $this->characterIds[] = $id;
    }

    /**
     * Создаёт клетку (cell=x*1000+y) и вышку владельца на этой клетке. Владелец
     * (`character_buildings.character_id`) — реальный FK на `characters`, поэтому
     * заводится здесь же, если ещё не существует (pvp-detection-clarity-24).
     */
    private function placeTower(int $ownerId, int $x, int $y, int $hp = 300): void
    {
        $this->ensureCharacterRow($ownerId, "Owner{$ownerId}");

        // map.id == map.cell_number для реальных данных (см. ClaimedCellModel,
        // RelocateAbandonedCharactersTest) — character_buildings.map_cell_id несёт
        // реальный FK на map.id, а TowerAlertService джойнит по cell_number; explicit
        // id здесь — не выдумка, а тот же инвариант, на котором стоит вся остальная игра.
        $cell = $x * 1000 + $y;
        $this->conn->table('map')->insert(['id' => $cell, 'cell_number' => $cell, 'coordinate_x' => $x, 'coordinate_y' => $y]);
        $this->mapIds[] = $cell;

        $this->conn->table('character_buildings')->insert([
            'character_id'                       => $ownerId,
            'building_id'                         => $this->towerBuildingId,
            'map_cell_id'                         => $cell,
            'character_level_during_construction' => 1,
            'hp'                                  => $hp,
            'built_at'                            => date('Y-m-d H:i:s'),
            'building_type'                       => 'defensive',
            'tax'                                 => 700,
            'usage'                               => 'personal',
        ]);
        $this->characterBuildingIds[] = (int) $this->conn->insertID();
    }

    private function makeMover(int $id, string $name): void
    {
        $this->ensureCharacterRow($id, $name);
    }

    private function service(): RecordingTowerAlert
    {
        return new RecordingTowerAlert();
    }

    public function testPingsOwnerWhenInRange(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 7, 5); // owner=7 at (7,5)

        $svc  = $this->service();
        $sent = $svc->notifyTowersNear(1, 5, 5); // mover at (5,5), dist=2 ≤ 5

        $this->assertSame(1, $sent);
        $this->assertCount(1, $svc->pings);
        $this->assertSame(7, $svc->pings[0]['owner']);
        $this->assertSame('Raider', $svc->pings[0]['mover']);
        $this->assertSame(2, $svc->pings[0]['dist']);
    }

    public function testNoPingOutOfRange(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 12, 5); // (12,5), dist from (5,5) = 7 > 5

        $svc  = $this->service();
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    public function testNoPingForOwnTower(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(1, 6, 5); // вышка самого идущего

        $svc = $this->service();
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    public function testBrokenTowerNoPing(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 6, 5, 0); // hp=0

        $svc = $this->service();
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    public function testCooldownSuppressesSecondPing(): void
    {
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 6, 5);

        $svc = $this->service();
        $this->assertSame(1, $svc->notifyTowersNear(1, 5, 5));
        // Повтор по той же паре (owner 7, mover 1) — на кулдауне.
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    public function testDistinctOwnerPingedOncePerPass(): void
    {
        $this->makeMover(1, 'Raider');
        // У владельца 7 две вышки на разных соседних клетках, обе в радиусе.
        $this->placeTower(7, 6, 5);
        $this->placeTower(7, 5, 6);

        $svc  = $this->service();
        $sent = $svc->notifyTowersNear(1, 5, 5);
        $this->assertSame(1, $sent); // один пинг на владельца за проход
    }

    public function testZeroRangeDisablesAlerts(): void
    {
        $this->conn->table('game_settings')
            ->where('setting_key', 'defense.tower.alert_range_cells')
            ->update(['value_int' => 0]);

        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 5, 5); // прямо на клетке

        $svc = $this->service();
        $this->assertSame(0, $svc->notifyTowersNear(1, 5, 5));
    }

    /**
     * pvp-detection-clarity-04: имя со спецсимволами Markdown («*», «_») больше не может
     * дать Telegram 400 — сообщение переведено на HTML, эти символы там не значимы.
     */
    public function testAlertMessagePreservesMarkdownSpecialCharsVerbatim(): void
    {
        $svc = new MessageExposingTowerAlert();
        $msg = $svc->exposeBuildAlertMessage('a*b_c', 3, 10, 10);

        $this->assertStringContainsString('a*b_c', $msg);
    }

    /** HTML-режим требует экранирования собственных спецсимволов ('<','>','&'). */
    public function testAlertMessageEscapesHtmlSpecialChars(): void
    {
        $svc = new MessageExposingTowerAlert();
        $msg = $svc->exposeBuildAlertMessage('a<b>c&d', 3, 10, 10);

        $this->assertStringContainsString('a&lt;b&gt;c&amp;d', $msg);
        $this->assertStringNotContainsString('<b>c', $msg);
    }

    /**
     * pvp-detection-clarity-04: срабатывание пишет `tower_alert_sent` в action_log —
     * иначе измерить постфактум, работала ли вышка, по-прежнему нечем.
     */
    public function testTowerAlertSentWritesAuditLog(): void
    {
        $this->conn->table('telegram_users')->insert(['telegram_id' => 555]);
        $tgId                     = (int) $this->conn->insertID();
        $this->telegramUserIds[] = $tgId;

        $this->makeMover(7, 'Owner');
        $this->conn->table('characters')->where('id', 7)->update(['telegram_user_id' => $tgId]);
        $this->makeMover(1, 'Raider');
        $this->placeTower(7, 6, 5);

        $svc  = new DeliveryStubTowerAlert();
        $sent = $svc->notifyTowersNear(1, 5, 5);

        $this->assertSame(1, $sent);
        $rows = $this->conn->table('action_log')->where('action_name', 'tower_alert_sent')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['character_id']);
        $this->assertSame('Completed', $rows[0]['action_status']);
    }

    /**
     * Доказывает acceptance #2: посторонняя таблица, которую этот тест не создавал,
     * переживает прогон `buildSchema()`/`dropTrackedTables()`. Сносим то, что успел
     * поднять штатный `setUp()`, вручную поднимаем ровно один "чужой" стол (как будто
     * он уже стоял на стенде до этого теста) — и убеждаемся, что повторный проход
     * его не трогает.
     */
    public function testDropTrackedTablesLeavesPreexistingTableUntouched(): void
    {
        $this->dropTrackedTables();

        $forge = Database::forge('tests');
        (new CreateBuildingsTable($forge instanceof Forge ? $forge : null))->up();
        $this->conn->resetDataCache();
        $this->conn->table('buildings')->insert([
            'name_ru'             => 'Чужое',
            'name_en'             => 'ForeignPreexisting',
            'building_type'       => 'military',
            'hp'                  => 1,
            'construction_time'   => 1,
            'tax'                 => 0,
            'usage'               => 'personal',
            'required_resources'  => '{}',
            'min_character_level' => 1,
            'effects'             => '{}',
        ]);

        // Пересобрать остальную схему поверх уже стоящей buildings — created['buildings']
        // обязан выйти false, раз таблица уже была на месте до этого прогона.
        $this->created['telegram_users']      = $this->createIfMissing('telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php');
        $this->created['characters']          = $this->createIfMissing('characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php');
        $this->created['buildings']           = $this->createIfMissing('buildings', CreateBuildingsTable::class, '2024-05-23-090819_CreateBuildingsTable.php');
        $this->created['factions']            = $this->createIfMissing('factions', CreateFactionsTable::class, '2024-05-15-131853_CreateFactionsTable.php');
        $this->created['map']                 = $this->createIfMissing('map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php');
        $this->created['game_settings']       = $this->createIfMissing('game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php');
        $this->created['character_buildings'] = $this->createIfMissing('character_buildings', CreateCharacterBuildingsTable::class, '2024-05-27-105534_CreateCharacterBuildingsTable.php');
        $this->created['action_log']          = $this->createIfMissing('action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php');

        $this->assertFalse($this->created['buildings'], 'предпосылка теста: buildings уже стояла на стенде до этого прогона');

        $this->dropTrackedTables();

        $this->assertTrue($this->conn->tableExists('buildings'), 'таблица, существовавшая до теста, обязана пережить прогон');
        $row = $this->conn->table('buildings')->where('name_en', 'ForeignPreexisting')->get()->getRowArray();
        $this->assertIsArray($row, 'данные постороннего стола не должны исчезнуть');

        // Восстановить консистентную схему для настоящего tearDown() этого теста.
        (new CreateBuildingsTable($forge instanceof Forge ? $forge : null))->down();
        $this->conn->resetDataCache();
        $this->setUp();
    }

    /**
     * Acceptance #3: после падения setUp() посередине (несовместимая преднесённая
     * map рвёт FK у character_buildings) в базе не остаётся мусора — ни одной из
     * таблиц, что успели создаться до броска, кроме самой несовместимой map (её
     * дроп не в зоне ответственности этого прогона, ровно как в
     * RelocateAbandonedCharactersTest).
     */
    public function testSetUpCleansUpPartiallyCreatedTablesWhenItFailsMidway(): void
    {
        $this->dropTrackedTables();

        $this->conn->query('CREATE TABLE map (id VARCHAR(10) NOT NULL PRIMARY KEY)');
        $this->conn->resetDataCache();

        $threw = false;
        try {
            $this->setUp();
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'предпосылка теста: несовместимая map обязана ронять создание character_buildings на FK map_cell_id');
        foreach (['telegram_users', 'characters', 'buildings', 'factions', 'game_settings', 'character_buildings', 'action_log'] as $t) {
            $this->assertFalse($this->conn->tableExists($t), "мусор от упавшего посередине setUp() не должен остаться: {$t}");
        }

        $this->conn->query('DROP TABLE IF EXISTS map');
        $this->conn->resetDataCache();
        $this->setUp();
    }
}

/**
 * Подменяет Telegram-доставку на запись (вся остальная логика — настоящая).
 *
 * @internal
 */
final class RecordingTowerAlert extends TowerAlertService
{
    /** @var list<array{owner:int, mover:string, dist:int, x:int, y:int}> */
    public array $pings = [];

    protected function sendAlert(int $ownerId, string $moverName, int $dist, int $x, int $y): bool
    {
        $this->pings[] = ['owner' => $ownerId, 'mover' => $moverName, 'dist' => $dist, 'x' => $x, 'y' => $y];
        return true;
    }
}

/** Открывает buildAlertMessage() для прямой проверки HTML-экранирования (без Telegram/БД). */
final class MessageExposingTowerAlert extends TowerAlertService
{
    public function exposeBuildAlertMessage(string $moverName, int $dist, int $x, int $y): string
    {
        return $this->buildAlertMessage($moverName, $dist, $x, $y);
    }
}

/**
 * Подменяет только доставку Telegram-сообщения — ownerChatId() и logAlertSent()
 * реальные, чтобы проверить, что успешная отправка пишет action_log.
 *
 * @internal
 */
final class DeliveryStubTowerAlert extends TowerAlertService
{
    protected function deliverAlertMessage(int $chatId, string $moverName, int $dist, int $x, int $y): bool
    {
        return true;
    }
}
