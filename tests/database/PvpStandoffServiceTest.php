<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Adr186CreatePvpStandoffs;
use App\Database\Migrations\Adr186SeedStandoffSettings;
use App\Database\Migrations\CreateActionLogTable;
use App\Database\Migrations\CreateBuildingsTable;
use App\Database\Migrations\CreateCharacterBuildingsTable;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateFactionsTable;
use App\Database\Migrations\CreateGameSettingsTable;
use App\Database\Migrations\CreateMapTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\W5AddCharacterCombatDroneActiveUntil;
use App\Services\PVE\DefenseStructureService;
use App\Services\PVE\PvpStandoffService;
use App\Services\PVE\StandoffNotifier;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * pvp-detection-clarity-06 — `PvpStandoffService`/`StandoffNotifier`/признак базы.
 *
 * Схема строится прогоном настоящих классов миграций (создаём только те таблицы,
 * которых ещё нет — CI гоняет набор на пустой БД, локальный стенд их обычно уже
 * несёт персистентно; см. `PvPRestrictionServiceTest` — тот же приём). ENUM
 * `building_type` расширяется до `defensive` и WoodenWall/WatchTower засеиваются
 * вручную (то же, что делает `S26AddDefensiveStructures`/`S26bAddWatchTower`,
 * без их побочной зависимости на `events`/`world_objects`/`tasks.handler_key`,
 * не относящейся к этой story).
 *
 * @internal
 */
final class PvpStandoffServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    private bool $createdTelegramUsers   = false;
    private bool $createdCharacters      = false;
    private bool $createdMap             = false;
    private bool $createdGameSettings    = false;
    private bool $createdFactions        = false;
    private bool $createdBuildings       = false;
    private bool $createdCharBuildings   = false;
    private bool $createdActionLog       = false;
    private bool $createdDroneColumn     = false;
    private bool $createdStandoffs       = false;
    private bool $seededStandoffSettings = false;
    private bool $alteredDefensiveEnum   = false;

    /** @var list<int> */
    private array $characterIds = [];
    /** @var list<int> */
    private array $mapIds = [];
    /** @var list<int> */
    private array $buildingRowIds = [];
    /** @var list<int> */
    private array $telegramUserIds = [];

    private int $woodenWallBuildingId = 0;
    private int $watchTowerBuildingId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        $this->createdTelegramUsers = $this->createIfMissing('telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php');
        $this->createdCharacters    = $this->createIfMissing('characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php');
        $this->createdMap           = $this->createIfMissing('map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php');
        $this->createdGameSettings  = $this->createIfMissing('game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php');
        $this->createdFactions      = $this->createIfMissing('factions', CreateFactionsTable::class, '2024-05-15-131853_CreateFactionsTable.php');
        $this->createdBuildings     = $this->createIfMissing('buildings', CreateBuildingsTable::class, '2024-05-23-090819_CreateBuildingsTable.php');
        $this->createdCharBuildings = $this->createIfMissing('character_buildings', CreateCharacterBuildingsTable::class, '2024-05-27-105534_CreateCharacterBuildingsTable.php');
        $this->createdActionLog     = $this->createIfMissing('action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php');
        $this->createdStandoffs     = $this->createIfMissing('pvp_standoffs', Adr186CreatePvpStandoffs::class, '2026-09-11-210000_Adr186CreatePvpStandoffs.php');

        if (! $this->columnExists('characters', 'combat_drone_active_until')) {
            $this->requireMigration('W5AddCharacterCombatDroneActiveUntil', '2026-06-01-300000_W5AddCharacterCombatDroneActiveUntil.php');
            (new W5AddCharacterCombatDroneActiveUntil())->up();
            $this->conn->resetDataCache();
            $this->createdDroneColumn = true;
        }

        // ENUM building_type → +'defensive' (то, что делает S26AddDefensiveStructures,
        // без его зависимости на tasks.handler_key/events/world_objects).
        if ($this->createdBuildings || $this->createdCharBuildings || ! $this->hasDefensiveEnumValue()) {
            $enum = "ENUM('military','residential','farming','resource','engineering','defensive')";
            $this->conn->query("ALTER TABLE buildings MODIFY building_type {$enum} NOT NULL");
            $this->conn->query("ALTER TABLE character_buildings MODIFY building_type {$enum} NOT NULL");
            $this->alteredDefensiveEnum = true;
        }

        $existing = $this->conn->table('game_settings')->where('setting_key', 'pvp.standoff.enabled')->get()->getRowArray();
        if (empty($existing)) {
            $this->requireMigration('Adr186SeedStandoffSettings', '2026-09-11-210100_Adr186SeedStandoffSettings.php');
            (new Adr186SeedStandoffSettings())->up();
            $this->seededStandoffSettings = true;
        }

        $this->woodenWallBuildingId = $this->ensureBuildingRow('WoodenWall', 'Деревянная стена');
        $this->watchTowerBuildingId = $this->ensureBuildingRow('WatchTower', 'Дозорная вышка');

        $this->setStandoffSetting('pvp.standoff.enabled', true);
        $this->setStandoffSetting('pvp.standoff.require_tower', false);
        $this->setIntSetting('pvp.standoff.window_sec', 300);
        $this->setIntSetting('pvp.standoff.cooldown_sec', 900);
    }

    protected function tearDown(): void
    {
        foreach ($this->buildingRowIds as $id) {
            $this->conn->table('buildings')->where('id', $id)->delete();
        }
        foreach ($this->characterIds as $id) {
            $this->conn->table('character_buildings')->where('character_id', $id)->delete();
            $this->conn->table('pvp_standoffs')->where('attacker_id', $id)->orWhere('defender_id', $id)->delete();
            $this->conn->table('action_log')->where('character_id', $id)->delete();
            $this->conn->table('characters')->where('id', $id)->delete();
        }
        foreach ($this->mapIds as $id) {
            $this->conn->table('map')->where('id', $id)->delete();
        }
        foreach ($this->telegramUserIds as $id) {
            $this->conn->table('telegram_users')->where('id', $id)->delete();
        }

        if ($this->seededStandoffSettings) {
            $this->requireMigration('Adr186SeedStandoffSettings', '2026-09-11-210100_Adr186SeedStandoffSettings.php');
            (new Adr186SeedStandoffSettings())->down();
        }
        if ($this->createdStandoffs) {
            $this->requireMigration('Adr186CreatePvpStandoffs', '2026-09-11-210000_Adr186CreatePvpStandoffs.php');
            (new Adr186CreatePvpStandoffs())->down();
        }
        if ($this->createdDroneColumn) {
            $this->requireMigration('W5AddCharacterCombatDroneActiveUntil', '2026-06-01-300000_W5AddCharacterCombatDroneActiveUntil.php');
            (new W5AddCharacterCombatDroneActiveUntil())->down();
        }
        if ($this->createdActionLog) {
            $this->requireMigration('CreateActionLogTable', '2024-03-18-134951_CreateActionLogTable.php');
            $forge = Database::forge('tests');
            (new CreateActionLogTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdCharBuildings) {
            $this->requireMigration('CreateCharacterBuildingsTable', '2024-05-27-105534_CreateCharacterBuildingsTable.php');
            $forge = Database::forge('tests');
            (new CreateCharacterBuildingsTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdBuildings) {
            $this->requireMigration('CreateBuildingsTable', '2024-05-23-090819_CreateBuildingsTable.php');
            $forge = Database::forge('tests');
            (new CreateBuildingsTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdFactions) {
            $this->requireMigration('CreateFactionsTable', '2024-05-15-131853_CreateFactionsTable.php');
            $forge = Database::forge('tests');
            (new CreateFactionsTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdGameSettings) {
            $this->requireMigration('CreateGameSettingsTable', '2026-05-19-100000_CreateGameSettingsTable.php');
            $forge = Database::forge('tests');
            (new CreateGameSettingsTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdMap) {
            $this->requireMigration('CreateMapTable', '2024-03-18-105708_CreateMapTable.php');
            $forge = Database::forge('tests');
            (new CreateMapTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdCharacters) {
            $this->requireMigration('CreateCharactersTable', '2024-03-20-154155_CreateCharactersTable.php');
            $forge = Database::forge('tests');
            (new CreateCharactersTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdTelegramUsers) {
            $this->requireMigration('CreateTelegramUsersTable', '2024-03-20-153728_CreateTelegramUsersTable.php');
            $forge = Database::forge('tests');
            (new CreateTelegramUsersTable($forge instanceof Forge ? $forge : null))->down();
        }

        $this->cleanCache();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- open()/activeAgainst()

    public function testOpenCreatesWindowWithConfiguredWindowSec(): void
    {
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell);

        $opened = (new PvpStandoffService())->open($attacker, $defender, $cell);

        $this->assertIsArray($opened);
        $this->assertSame('open', $opened['status']);
        $this->assertSame($attacker, (int) $opened['attacker_id']);
        $this->assertSame($defender, (int) $opened['defender_id']);
        $expiresIn = strtotime((string) $opened['expires_at']) - time();
        $this->assertGreaterThan(290, $expiresIn);
        $this->assertLessThanOrEqual(300, $expiresIn);
    }

    public function testSecondAttackerOpeningGetsNullAndInheritsRemainingWindow(): void
    {
        $cell      = $this->createCell();
        $defender  = $this->insertCharacter();
        $attacker1 = $this->insertCharacter();
        $attacker2 = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell);

        $service = new PvpStandoffService();
        $first   = $service->open($attacker1, $defender, $cell);
        $this->assertIsArray($first);

        $dupAttempt = $service->open($attacker2, $defender, $cell);
        $this->assertNull($dupAttempt, 'дубль-открытие обязано вернуть null, а не исключение и не второе окно');

        $inherited = $service->activeAgainst($defender);
        $this->assertIsArray($inherited);
        $this->assertSame((int) $first['id'], (int) $inherited['id']);
        $this->assertSame($first['expires_at'], $inherited['expires_at'], 'второй атакующий не продлевает остаток');
    }

    public function testActiveAgainstIsNullOnceExpiresAtIsInThePastEvenIfCronNeverRan(): void
    {
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell);

        $standoffId = $this->insertStandoffRow($attacker, $defender, $cell, 'open', -60);

        $this->assertNull((new PvpStandoffService())->activeAgainst($defender), 'ленивое истечение: expires_at в прошлом → окно не активно вне зависимости от крона');
        $this->assertGreaterThan(0, $standoffId);
    }

    // ---------------------------------------------------------------- close()

    public function testCloseAppliesOnceAndRefusesSecondReactionFromDefender(): void
    {
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell);

        $service = new PvpStandoffService();
        $opened  = $service->open($attacker, $defender, $cell);
        $this->assertIsArray($opened);
        $id = (int) $opened['id'];

        $this->assertTrue($service->close($id, 'held'), 'первый ход защитника обязан применяться');
        $this->assertFalse($service->close($id, 'fled'), 'второй ход подряд — «ты уже отреагировал»');

        $row = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('held', $row['status']);
    }

    // ---------------------------------------------------------------- shouldOpen()

    public function testShouldOpenFalseWhenKillswitchOff(): void
    {
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell);
        $this->setStandoffSetting('pvp.standoff.enabled', false);

        $this->assertFalse((new PvpStandoffService())->shouldOpen($defender, $cell));
    }

    public function testShouldOpenFalseWhenDefenderHasCombatDroneButNoStructures(): void
    {
        // ADR-186 §2 — не второе определение «дома»: с ADR-064 getDefenseProfile()
        // возвращает профиль на одном боевом дроне в чистом поле (structure_ids => []).
        // Признак базы обязан требовать непустой набор построек и игнорировать дрон.
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $this->conn->table('characters')->where('id', $defender)->update([
            'combat_drone_active_until' => date('Y-m-d H:i:s', time() + 1800),
        ]);

        $defense = new DefenseStructureService();
        $trapProfile = $defense->getDefenseProfile($defender, $cell);
        $this->assertNotNull($trapProfile, 'предпосылка теста: drone-only профиль действительно существует (ADR-064)');
        $this->assertSame([], $trapProfile['structure_ids']);

        $this->assertFalse($defense->hasActiveStructuresOnCell($defender, $cell), 'дрон не считается постройкой');
        $this->assertFalse((new PvpStandoffService())->shouldOpen($defender, $cell), 'окно не открывается на одном дроне без построек');
    }

    public function testShouldOpenFalseWhileDefenderCooldownActiveAndTrueAfterItExpires(): void
    {
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell);
        $this->setIntSetting('pvp.standoff.cooldown_sec', 900);

        // Свежезакрытое окно (100с назад) — кулдаун ещё идёт.
        $recentId = $this->insertStandoffRow(
            $this->insertCharacter(),
            $defender,
            $cell,
            'held',
            -300,
        );
        $this->conn->table('pvp_standoffs')->where('id', $recentId)->update([
            'updated_at' => date('Y-m-d H:i:s', time() - 100),
        ]);

        $this->assertFalse((new PvpStandoffService())->shouldOpen($defender, $cell), 'кулдаун защитника ещё не истёк');

        // Отодвигаем закрытие за пределы кулдауна.
        $this->conn->table('pvp_standoffs')->where('id', $recentId)->update([
            'updated_at' => date('Y-m-d H:i:s', time() - 1000),
        ]);

        $this->assertTrue((new PvpStandoffService())->shouldOpen($defender, $cell), 'кулдаун истёк — новое окно снова доступно');
    }

    public function testShouldOpenRespectsRequireTowerSetting(): void
    {
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell); // стена есть, вышки нет
        $this->setStandoffSetting('pvp.standoff.require_tower', true);

        $this->assertFalse((new PvpStandoffService())->shouldOpen($defender, $cell), 'require_tower=true, а вышки на клетке нет');

        $this->placeWatchTower($defender, $cell);
        $this->assertTrue((new PvpStandoffService())->shouldOpen($defender, $cell));
    }

    // ---------------------------------------------------------------- StandoffNotifier

    public function testAlertDefenderKeyboardHasExactlyThreeNamedMoves(): void
    {
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell);

        $opened = (new PvpStandoffService())->open($attacker, $defender, $cell);
        $this->assertIsArray($opened);

        $notifier = new class () extends StandoffNotifier {
            /** @var array{chatId:int,text:string,keyboard:array<string,mixed>}|null */
            public ?array $captured = null;

            protected function sendDefenderAlert(int $chatId, string $text, array $keyboard): bool
            {
                $this->captured = ['chatId' => $chatId, 'text' => $text, 'keyboard' => $keyboard];
                return true;
            }
        };

        $notifier->alertDefender($opened);

        $this->assertNotNull($notifier->captured, 'chatId защитника обязан резолвиться (telegram_users есть)');
        $rows = $notifier->captured['keyboard']['inline_keyboard'] ?? null;
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows, 'ровно три хода в одном ряду, ни одной одиночки');
        $buttons = $rows[0];
        $this->assertCount(3, $buttons);
        $callbacks = array_column($buttons, 'callback_data');
        $this->assertSame([
            "standoffHold_{$opened['id']}",
            'runAway',
            "attackPlayer_{$attacker}",
        ], $callbacks);
    }

    public function testWaitScreenKeyboardHasCheckAndLeave(): void
    {
        $cell     = $this->createCell();
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $this->placeWoodenWall($defender, $cell);

        $opened = (new PvpStandoffService())->open($attacker, $defender, $cell);
        $this->assertIsArray($opened);

        $screen = (new StandoffNotifier())->waitScreen($opened);

        $this->assertIsString($screen['text']);
        $this->assertNotSame('', $screen['text']);
        $rows = $screen['keyboard']['inline_keyboard'] ?? null;
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $callbacks = array_column($rows[0], 'callback_data');
        $this->assertSame([
            "standoffCheck_{$opened['id']}",
            "standoffLeave_{$opened['id']}",
        ], $callbacks);
    }

    // ---------------------------------------------------------------- helpers

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

    private function columnExists(string $table, string $column): bool
    {
        $fields = $this->conn->getFieldNames($table);
        return is_array($fields) && in_array($column, $fields, true);
    }

    private function hasDefensiveEnumValue(): bool
    {
        $row = $this->conn->query("SHOW COLUMNS FROM buildings LIKE 'building_type'")->getRowArray();
        $type = is_array($row) && isset($row['Type']) ? (string) $row['Type'] : '';
        return str_contains($type, 'defensive');
    }

    private function ensureBuildingRow(string $nameEn, string $nameRu): int
    {
        $existing = $this->conn->table('buildings')->where('name_en', $nameEn)->get()->getRowArray();
        if (is_array($existing)) {
            return (int) $existing['id'];
        }
        $this->conn->table('buildings')->insert([
            'name_ru'       => $nameRu,
            'name_en'       => $nameEn,
            'building_type' => 'defensive',
            'hp'            => 200,
        ]);
        $id = (int) $this->conn->insertID();
        $this->buildingRowIds[] = $id;
        return $id;
    }

    private function insertCharacter(): int
    {
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => random_int(100_000_000, 999_999_999),
        ]);
        $telegramUserId          = (int) $this->conn->insertID();
        $this->telegramUserIds[] = $telegramUserId;

        $this->conn->table('characters')->insert([
            'name'             => 'T' . random_int(100000, 999999),
            'level'            => 10,
            'cell_number'      => '1',
            'telegram_user_id' => $telegramUserId,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $id                    = (int) $this->conn->insertID();
        $this->characterIds[]  = $id;
        return $id;
    }

    private function placeWoodenWall(int $characterId, int $cellNumber): void
    {
        $this->conn->table('character_buildings')->insert([
            'character_id'                        => $characterId,
            'building_id'                          => $this->woodenWallBuildingId,
            'map_cell_id'                          => $cellNumber,
            'amount'                               => 1,
            'character_level_during_construction'  => 10,
            'hp'                                   => 200,
            'level'                                => 1,
            'built_at'                             => date('Y-m-d H:i:s'),
            'building_type'                        => 'defensive',
            'tax'                                  => 200,
            'usage'                                => 'personal',
        ]);
    }

    private function placeWatchTower(int $characterId, int $cellNumber): void
    {
        $this->conn->table('character_buildings')->insert([
            'character_id'                        => $characterId,
            'building_id'                          => $this->watchTowerBuildingId,
            'map_cell_id'                          => $cellNumber,
            'amount'                               => 1,
            'character_level_during_construction'  => 12,
            'hp'                                   => 300,
            'level'                                => 1,
            'built_at'                             => date('Y-m-d H:i:s'),
            'building_type'                        => 'defensive',
            'tax'                                  => 700,
            'usage'                                => 'personal',
        ]);
    }

    private function insertStandoffRow(int $attackerId, int $defenderId, int $cellNumber, string $status, int $expiresInSeconds): int
    {
        $this->conn->table('pvp_standoffs')->insert([
            'attacker_id'      => $attackerId,
            'defender_id'      => $defenderId,
            'cell_number'      => $cellNumber,
            'started_at'       => date('Y-m-d H:i:s'),
            'expires_at'       => date('Y-m-d H:i:s', time() + $expiresInSeconds),
            'status'           => $status,
            'notified_expired' => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->conn->insertID();
    }

    /**
     * FK `character_buildings.map_cell_id` → `map.id` (не `map.cell_number`, хотя на
     * проде они всегда совпадают — {@see \App\Services\Player\PlayerStateService::isCharacterOnBase()}).
     * Возвращаем именно `id`: он и используется всюду в тесте как «номер клетки»,
     * что FK-валидно и не расходится с прод-инвариантом.
     */
    private function createCell(): int
    {
        $cellNumber = random_int(900_000_000, 999_999_999);
        $this->conn->table('map')->insert([
            'cell_number'  => $cellNumber,
            'coordinate_x' => 0,
            'coordinate_y' => 100,
        ]);
        $id             = (int) $this->conn->insertID();
        $this->mapIds[] = $id;
        return $id;
    }

    private function setStandoffSetting(string $key, bool $value): void
    {
        $this->conn->table('game_settings')->where('setting_key', $key)->update(['value_bool' => $value ? 1 : 0]);
    }

    private function setIntSetting(string $key, int $value): void
    {
        $this->conn->table('game_settings')->where('setting_key', $key)->update(['value_int' => $value]);
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
}
