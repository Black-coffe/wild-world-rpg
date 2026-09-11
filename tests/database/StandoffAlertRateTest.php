<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\PVP\AttackPlayerAction;
use App\Controllers\Telegram\Commands\Actions\PVP\DuelAction;
use App\Database\Migrations\Adr186AlertRateAndAttackCooldownSettings;
use App\Database\Migrations\Adr186CreatePvpStandoffs;
use App\Database\Migrations\Adr186SeedStandoffSettings;
use App\Database\Migrations\CreateActionLogTable;
use App\Database\Migrations\CreateBiomesTable;
use App\Database\Migrations\CreateBuildingsTable;
use App\Database\Migrations\CreateCharacterBuildingsTable;
use App\Database\Migrations\CreateCharacterFactionsTable;
use App\Database\Migrations\CreateCharacterTasksTable;
use App\Database\Migrations\CreateCharactersOutfitsTable;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateCharactersWeaponsTable;
use App\Database\Migrations\CreateClaimedCellsTable;
use App\Database\Migrations\CreateExploredCellsTable;
use App\Database\Migrations\CreateFactionsTable;
use App\Database\Migrations\CreateGameSettingsTable;
use App\Database\Migrations\CreateMapTable;
use App\Database\Migrations\CreateOutfitsTable;
use App\Database\Migrations\CreateTasksTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Database\Migrations\CreateWeaponsTable;
use App\Database\Migrations\W17AddCharacterDuelsOpen;
use App\Database\Migrations\W17SeedPvpDuelGameSettings;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * pvp-detection-clarity-26 (BLOCK-3 minor 9) — два новых `GameSettings` ключа:
 * `pvp.attack_cooldown_sec` (был хардкод `Config\GameBalance::$pvpAttackCooldownSec`,
 * читают `AttackPlayerAction` И `DuelAction`) и `pvp.standoff.min_alert_interval_sec`
 * (новый потолок частоты пуш-тревог ОДНОМУ защитнику, независимый от кулдауна атаки
 * и от кулдауна повторного открытия окна `pvp.standoff.cooldown_sec`).
 *
 * Схема — тот же приём, что `StandoffAttackGateTest`: прогон настоящих классов
 * миграций, создаём только отсутствующие таблицы.
 *
 * @internal
 */
final class StandoffAlertRateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    /** @var array<string,bool> */
    private array $created = [];
    private bool $alteredDefensiveEnum      = false;
    private bool $seededStandoffSettings    = false;
    private bool $seededAlertRateSettings   = false;
    private bool $seededDuelSettings        = false;
    private int $woodenWallBuildingId       = 0;
    private int $biomeId                    = 0;

    /** @var list<int> */
    private array $characterIds = [];
    /** @var list<int> */
    private array $telegramUserIds = [];
    /** @var list<int> */
    private array $mapIds = [];
    /** @var list<int> */
    private array $buildingRowIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        $this->created['telegram_users']      = $this->createIfMissing('telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php');
        $this->created['characters']          = $this->createIfMissing('characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php');
        $this->created['map']                 = $this->createIfMissing('map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php');
        $this->created['biomes']              = $this->createIfMissing('biomes', CreateBiomesTable::class, '2024-03-17-222643_CreateBiomesTable.php');
        $this->created['game_settings']       = $this->createIfMissing('game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php');
        $this->created['factions']            = $this->createIfMissing('factions', CreateFactionsTable::class, '2024-05-15-131853_CreateFactionsTable.php');
        $this->created['character_factions']  = $this->createIfMissing('character_factions', CreateCharacterFactionsTable::class, '2024-05-15-132233_CreateCharacterFactionsTable.php');
        $this->created['buildings']           = $this->createIfMissing('buildings', CreateBuildingsTable::class, '2024-05-23-090819_CreateBuildingsTable.php');
        $this->created['character_buildings'] = $this->createIfMissing('character_buildings', CreateCharacterBuildingsTable::class, '2024-05-27-105534_CreateCharacterBuildingsTable.php');
        $this->created['action_log']          = $this->createIfMissing('action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php');
        $this->created['pvp_standoffs']       = $this->createIfMissing('pvp_standoffs', Adr186CreatePvpStandoffs::class, '2026-09-11-210000_Adr186CreatePvpStandoffs.php');
        $this->created['claimed_cells']       = $this->createIfMissing('claimed_cells', CreateClaimedCellsTable::class, '2024-05-23-061031_CreateClaimedCellsTable.php');
        $this->created['explored_cells']      = $this->createIfMissing('explored_cells', CreateExploredCellsTable::class, '2024-03-24-212921_CreateExploredCellsTable.php');
        $this->created['tasks']               = $this->createIfMissing('tasks', CreateTasksTable::class, '2024-03-22-111828_CreateTasksTable.php');
        $this->created['character_tasks']     = $this->createIfMissing('character_tasks', CreateCharacterTasksTable::class, '2024-03-22-132411_CreateCharacterTasksTable.php');
        $this->created['weapons']             = $this->createIfMissing('weapons', CreateWeaponsTable::class, '2025-02-08-195713_CreateWeaponsTable.php');
        $this->created['outfits']             = $this->createIfMissing('outfits', CreateOutfitsTable::class, '2025-02-08-194808_CreateOutfitsTable.php');
        $this->created['characters_weapons']  = $this->createIfMissing('characters_weapons', CreateCharactersWeaponsTable::class, '2025-02-11-115603_CreateCharactersWeaponsTable.php');
        $this->created['characters_outfits']  = $this->createIfMissing('characters_outfits', CreateCharactersOutfitsTable::class, '2025-02-10-224703_CreateCharactersOutfitsTable.php');

        if (! $this->conn->tableExists('battle_logs')) {
            $this->conn->query('
                CREATE TABLE battle_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    battle_type VARCHAR(20) NOT NULL,
                    player1_id INT NOT NULL,
                    player2_id INT NOT NULL,
                    winner_id INT NULL,
                    created_at DATETIME NULL,
                    finished_at DATETIME NULL,
                    log_data LONGTEXT NULL
                )
            ');
            $this->created['battle_logs'] = true;
        }

        $enum = "ENUM('military','residential','farming','resource','engineering','defensive')";
        if ($this->created['buildings'] || $this->created['character_buildings'] || ! $this->hasDefensiveEnumValue()) {
            $this->conn->query("ALTER TABLE buildings MODIFY building_type {$enum} NOT NULL");
            $this->conn->query("ALTER TABLE character_buildings MODIFY building_type {$enum} NOT NULL");
            $this->alteredDefensiveEnum = true;
        }

        $existingStandoff = $this->conn->table('game_settings')->where('setting_key', 'pvp.standoff.enabled')->get()->getRowArray();
        if (empty($existingStandoff)) {
            $this->requireMigration('Adr186SeedStandoffSettings', '2026-09-11-210100_Adr186SeedStandoffSettings.php');
            (new Adr186SeedStandoffSettings())->up();
            $this->seededStandoffSettings = true;
        }

        // pvp-detection-clarity-26 — сама история: ключи + колонка `last_alerted_at`.
        $existingCooldown = $this->conn->table('game_settings')->where('setting_key', 'pvp.attack_cooldown_sec')->get()->getRowArray();
        $hasAlertColumn   = $this->conn->fieldExists('last_alerted_at', 'pvp_standoffs');
        if (empty($existingCooldown) || ! $hasAlertColumn) {
            $this->requireMigration('Adr186AlertRateAndAttackCooldownSettings', '2026-09-12-120000_Adr186AlertRateAndAttackCooldownSettings.php');
            (new Adr186AlertRateAndAttackCooldownSettings())->up();
            $this->seededAlertRateSettings = true;
        }

        $existingDuel = $this->conn->table('game_settings')->where('setting_key', 'pvp.duel.enabled')->get()->getRowArray();
        if (empty($existingDuel)) {
            $this->requireMigration('W17AddCharacterDuelsOpen', '2026-06-04-230000_W17AddCharacterDuelsOpen.php');
            (new W17AddCharacterDuelsOpen())->up();
            $this->requireMigration('W17SeedPvpDuelGameSettings', '2026-06-04-240000_W17SeedPvpDuelGameSettings.php');
            (new W17SeedPvpDuelGameSettings())->up();
            $this->seededDuelSettings = true;
        }

        $this->woodenWallBuildingId = $this->ensureBuildingRow('AlertRateWall', 'Стена теста тревог');
        $this->biomeId              = $this->ensureBiomeRow();

        $this->setBoolSetting('pvp.standoff.enabled', true);
        $this->setBoolSetting('pvp.standoff.require_tower', false);
        $this->setIntSetting('pvp.standoff.window_sec', 300);
        $this->setIntSetting('pvp.standoff.cooldown_sec', 0);
        $this->setIntSetting('pvp.standoff.hold_damage_reduction_percent', 10);
        $this->setIntSetting('pvp.attack_cooldown_sec', 30);
        $this->setIntSetting('pvp.standoff.min_alert_interval_sec', 60);
        $this->setBoolSetting('pvp.duel.enabled', true);
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
            $this->conn->table('battle_logs')->where('player1_id', $id)->orWhere('player2_id', $id)->delete();
            $this->conn->table('claimed_cells')->where('character_id', $id)->delete();
            $this->conn->table('explored_cells')->where('character_id', $id)->delete();
            $this->conn->table('character_tasks')->where('character_id', $id)->delete();
            $this->conn->table('characters')->where('id', $id)->delete();
        }
        foreach ($this->mapIds as $id) {
            $this->conn->table('map')->where('id', $id)->delete();
        }
        foreach ($this->telegramUserIds as $id) {
            $this->conn->table('telegram_users')->where('id', $id)->delete();
        }

        if ($this->seededDuelSettings) {
            $this->requireMigration('W17SeedPvpDuelGameSettings', '2026-06-04-240000_W17SeedPvpDuelGameSettings.php');
            (new W17SeedPvpDuelGameSettings())->down();
            $this->requireMigration('W17AddCharacterDuelsOpen', '2026-06-04-230000_W17AddCharacterDuelsOpen.php');
            (new W17AddCharacterDuelsOpen())->down();
        }

        if ($this->seededAlertRateSettings) {
            $this->requireMigration('Adr186AlertRateAndAttackCooldownSettings', '2026-09-12-120000_Adr186AlertRateAndAttackCooldownSettings.php');
            (new Adr186AlertRateAndAttackCooldownSettings())->down();
        }

        if ($this->seededStandoffSettings) {
            $this->requireMigration('Adr186SeedStandoffSettings', '2026-09-11-210100_Adr186SeedStandoffSettings.php');
            (new Adr186SeedStandoffSettings())->down();
        }

        $downOrder = [
            ['characters_outfits', CreateCharactersOutfitsTable::class, '2025-02-10-224703_CreateCharactersOutfitsTable.php'],
            ['characters_weapons', CreateCharactersWeaponsTable::class, '2025-02-11-115603_CreateCharactersWeaponsTable.php'],
            ['character_tasks', CreateCharacterTasksTable::class, '2024-03-22-132411_CreateCharacterTasksTable.php'],
            ['explored_cells', CreateExploredCellsTable::class, '2024-03-24-212921_CreateExploredCellsTable.php'],
            ['claimed_cells', CreateClaimedCellsTable::class, '2024-05-23-061031_CreateClaimedCellsTable.php'],
            ['pvp_standoffs', Adr186CreatePvpStandoffs::class, '2026-09-11-210000_Adr186CreatePvpStandoffs.php'],
            ['action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php'],
            ['character_buildings', CreateCharacterBuildingsTable::class, '2024-05-27-105534_CreateCharacterBuildingsTable.php'],
            ['character_factions', CreateCharacterFactionsTable::class, '2024-05-15-132233_CreateCharacterFactionsTable.php'],
            ['tasks', CreateTasksTable::class, '2024-03-22-111828_CreateTasksTable.php'],
            ['weapons', CreateWeaponsTable::class, '2025-02-08-195713_CreateWeaponsTable.php'],
            ['outfits', CreateOutfitsTable::class, '2025-02-08-194808_CreateOutfitsTable.php'],
            ['buildings', CreateBuildingsTable::class, '2024-05-23-090819_CreateBuildingsTable.php'],
            ['factions', CreateFactionsTable::class, '2024-05-15-131853_CreateFactionsTable.php'],
            ['game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php'],
            ['biomes', CreateBiomesTable::class, '2024-03-17-222643_CreateBiomesTable.php'],
            ['map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php'],
            ['characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php'],
            ['telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php'],
        ];

        if (! empty($this->created['battle_logs'])) {
            $this->conn->query('DROP TABLE IF EXISTS battle_logs');
        }

        foreach ($downOrder as [$table, $class, $file]) {
            if (empty($this->created[$table])) {
                continue;
            }
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            $this->requireMigration($short, $file);
            $forge = Database::forge('tests');
            (new $class($forge instanceof Forge ? $forge : null))->down();
        }

        $this->cleanCache();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- миграция: ключи + идемпотентность

    public function testMigrationSeedsBothKeysWithFullRationaleAndByteIdenticalDefault(): void
    {
        $cooldown = $this->conn->table('game_settings')->where('setting_key', 'pvp.attack_cooldown_sec')->get()->getRowArray();
        $this->assertIsArray($cooldown);
        $this->assertSame('combat', $cooldown['category']);
        $this->assertSame(30, (int) $cooldown['value_int'], 'default обязан быть байт-в-байт равен прежнему хардкоду Config\\GameBalance::$pvpAttackCooldownSec');
        $this->assertSame('30', $cooldown['default_value_text']);
        $this->assertNotSame('', trim((string) $cooldown['rationale_text']));
        $this->assertNotSame('', trim((string) $cooldown['effect_text']));
        $this->assertNotSame('', trim((string) $cooldown['above_effect_text']));
        $this->assertNotSame('', trim((string) $cooldown['below_effect_text']));
        $this->assertNotNull($cooldown['hard_min']);
        $this->assertNotNull($cooldown['hard_max']);

        $alertRate = $this->conn->table('game_settings')->where('setting_key', 'pvp.standoff.min_alert_interval_sec')->get()->getRowArray();
        $this->assertIsArray($alertRate);
        $this->assertSame('combat', $alertRate['category']);
        $this->assertSame(60, (int) $alertRate['value_int']);
        $this->assertNotSame('', trim((string) $alertRate['rationale_text']));
        $this->assertNotSame('', trim((string) $alertRate['effect_text']));
        $this->assertNotSame('', trim((string) $alertRate['above_effect_text']));
        $this->assertNotSame('', trim((string) $alertRate['below_effect_text']));
        $this->assertNotNull($alertRate['hard_min']);
        $this->assertNotNull($alertRate['hard_max']);
    }

    public function testMigrationIsIdempotentOnRepeatedUp(): void
    {
        (new Adr186AlertRateAndAttackCooldownSettings())->up();
        (new Adr186AlertRateAndAttackCooldownSettings())->up();

        $cooldownCount = $this->conn->table('game_settings')->where('setting_key', 'pvp.attack_cooldown_sec')->countAllResults();
        $alertRateCount = $this->conn->table('game_settings')->where('setting_key', 'pvp.standoff.min_alert_interval_sec')->countAllResults();

        $this->assertSame(1, $cooldownCount, 'повторный up() не должен плодить дубли pvp.attack_cooldown_sec');
        $this->assertSame(1, $alertRateCount, 'повторный up() не должен плодить дубли pvp.standoff.min_alert_interval_sec');
        // Прямой SHOW COLUMNS, не fieldExists() — CI4 кэширует метаданные поля в
        // рамках соединения, тот же приём, что в самой миграции.
        $column = $this->conn->query("SHOW COLUMNS FROM `pvp_standoffs` LIKE 'last_alerted_at'")->getRowArray();
        $this->assertIsArray($column, 'повторный up() не должен падать на уже существующей колонке');
    }

    // ---------------------------------------------------------------- потолок частоты тревог (на защитника)

    public function testAlertSuppressedWhenIntervalNotElapsedButWindowStillOpens(): void
    {
        $this->setIntSetting('pvp.standoff.min_alert_interval_sec', 3600);

        $cell      = $this->createSelfConsistentCell();
        $defender  = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker1 = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker2 = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        // Первая тревога — интервал ещё не считается (нет предыдущей).
        $first = $this->invokeAttack($attacker1['tgId'], $attacker1['id'], $defender['id']);
        $this->assertStringContainsString('Осталось', $this->responseText($first));
        $row1 = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker1['id'])->where('defender_id', $defender['id'])->get()->getRowArray();
        $this->assertNotNull($row1['last_alerted_at'], 'первая тревога защитнику обязана уйти и застолбить last_alerted_at');

        // Защитник отреагировал ("убежал") — окно закрыто, cooldown_sec=0 разрешает переоткрытие.
        $this->conn->table('pvp_standoffs')->where('id', $row1['id'])->update(['status' => 'fled']);

        // Другой атакующий (потолок — на ЗАЩИТНИКА, не на пару) открывает НОВОЕ окно.
        $second = $this->invokeAttack($attacker2['tgId'], $attacker2['id'], $defender['id']);
        $secondText = $this->responseText($second);

        $this->assertStringContainsString('Осталось', $secondText, 'AC: при неистёкшем интервале окно всё равно ОБЯЗАНО открыться — атака заморожена');

        $rowsForDefender = $this->conn->table('pvp_standoffs')->where('defender_id', $defender['id'])->countAllResults();
        $this->assertSame(2, $rowsForDefender, 'второе окно обязано открыться отдельной строкой');

        $row2 = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker2['id'])->where('defender_id', $defender['id'])->get()->getRowArray();
        $this->assertSame('open', $row2['status']);
        $this->assertNull($row2['last_alerted_at'], 'AC: при неистёкшем интервале повторная тревога НЕ должна уходить');
    }

    public function testAlertSentAgainAfterMinIntervalElapsed(): void
    {
        $this->setIntSetting('pvp.standoff.min_alert_interval_sec', 60);

        $cell      = $this->createSelfConsistentCell();
        $defender  = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker1 = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker2 = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        $this->invokeAttack($attacker1['tgId'], $attacker1['id'], $defender['id']);
        $row1 = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker1['id'])->where('defender_id', $defender['id'])->get()->getRowArray();
        $this->conn->table('pvp_standoffs')->where('id', $row1['id'])->update(['status' => 'fled']);

        // Отодвигаем часы БД у предыдущей тревоги дальше min_alert_interval_sec (60 с) назад.
        $this->conn->query('UPDATE pvp_standoffs SET last_alerted_at = (NOW() - INTERVAL 100 SECOND) WHERE id = ?', [$row1['id']]);

        $second = $this->invokeAttack($attacker2['tgId'], $attacker2['id'], $defender['id']);
        $this->assertStringContainsString('Осталось', $this->responseText($second));

        $row2 = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker2['id'])->where('defender_id', $defender['id'])->get()->getRowArray();
        $this->assertNotNull($row2['last_alerted_at'], 'интервал истёк — тревога обязана уйти снова');
    }

    public function testMinAlertIntervalZeroDisablesCapEntirely(): void
    {
        $this->setIntSetting('pvp.standoff.min_alert_interval_sec', 0);

        $cell      = $this->createSelfConsistentCell();
        $defender  = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker1 = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker2 = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        $this->invokeAttack($attacker1['tgId'], $attacker1['id'], $defender['id']);
        $row1 = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker1['id'])->where('defender_id', $defender['id'])->get()->getRowArray();
        $this->conn->table('pvp_standoffs')->where('id', $row1['id'])->update(['status' => 'fled']);

        $this->invokeAttack($attacker2['tgId'], $attacker2['id'], $defender['id']);
        $row2 = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker2['id'])->where('defender_id', $defender['id'])->get()->getRowArray();

        $this->assertNotNull($row2['last_alerted_at'], 'min_alert_interval_sec=0 обязан выключать потолок целиком');
    }

    public function testExistingAlertOnFirstOpenStillFiresUnaffectedByCap(): void
    {
        // Существующие тесты на тревогу (StandoffAttackGateTest) остаются зелёными —
        // первая тревога защитнику без предыдущей истории всегда уходит.
        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        $response = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $this->assertStringContainsString('Осталось', $this->responseText($response));

        $row = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker['id'])->where('defender_id', $defender['id'])->get()->getRowArray();
        $this->assertNotNull($row['last_alerted_at']);
    }

    // ---------------------------------------------------------------- pvp.attack_cooldown_sec из GameSettings

    public function testAttackCooldownOverrideAppliesInAttackPlayerAction(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', false);
        $this->setIntSetting('pvp.attack_cooldown_sec', 9999);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], veryHighHealth: true);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], veryHighHealth: true);

        $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $second = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $text   = $this->responseText($second);

        $this->assertStringContainsString('Подождите', $text, 'админский override обязан применяться, а не хардкод 30 с');
        preg_match('/Подождите (\d+) сек/u', $text, $m);
        $this->assertNotEmpty($m, 'текст обязан содержать остаток кулдауна');
        $this->assertGreaterThan(9000, (int) $m[1], 'остаток обязан отражать override (9999), а не прежний хардкод 30');
    }

    /**
     * DuelAction отказывает на кулдауне через `alert()` (`answerCallbackQuery`,
     * без `sendMessage`) — `ServerResponse::getText()` на этом пути всегда пуст,
     * поэтому TTL кэш-ключа через `getMetaData()` не годится (CodeIgniter\Test\
     * Mock\MockCache — инвертированная проверка на "не истёк", всегда `null` для
     * живой записи в тестовом окружении). Override доказывается функционально:
     * `pvp.attack_cooldown_sec = 0` обязан пускать ВТОРОЙ немедленный тап дуэли
     * (успешный результат боя, непустой текст) — прежний хардкод 30 с заблокировал
     * бы его тем же кулдауном.
     */
    public function testAttackCooldownOverrideAppliesInDuelAction(): void
    {
        $this->setIntSetting('pvp.attack_cooldown_sec', 0);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->conn->table('characters')->where('id', $defender['id'])->update(['duels_open' => 1]);

        $first  = $this->invokeDuel($attacker['tgId'], $attacker['id'], $defender['id']);
        $second = $this->invokeDuel($attacker['tgId'], $attacker['id'], $defender['id']);

        $this->assertStringContainsString('Обменов ударами', $this->responseText($first));
        $this->assertStringContainsString(
            'Обменов ударами',
            $this->responseText($second),
            'override=0 обязан пускать немедленный повторный тап — прежний хардкод 30 с заблокировал бы его пустым alert()'
        );
    }

    // ---------------------------------------------------------------- helpers

    private function invokeAttack(int $tgId, int $attackerId, int $targetId): ServerResponse
    {
        return (new AttackPlayerAction($this->callbackQuery($tgId, "attackPlayer_{$targetId}")))->handle();
    }

    private function invokeDuel(int $tgId, int $attackerId, int $targetId): ServerResponse
    {
        return (new DuelAction($this->callbackQuery($tgId, "duel_{$targetId}")))->handle();
    }

    private function responseText(ServerResponse $response): string
    {
        $result = $response->getResult();
        if (! is_object($result) || ! method_exists($result, 'getText')) {
            return '';
        }

        return (string) ($result->getText() ?? '');
    }

    /** Настоящий CallbackQuery — как из реального вебхука клика по кнопке. */
    private function callbackQuery(int $tgId, string $data): CallbackQuery
    {
        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        return new CallbackQuery([
            'id'      => 'cbq_' . random_int(1, PHP_INT_MAX),
            'from'    => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1,
                'date'       => time(),
                'chat'       => ['id' => $tgId, 'type' => 'private'],
                'text'       => 'placeholder',
            ],
            'chat_instance' => 'ci_1',
            'data'          => $data,
        ]);
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
        $id                     = (int) $this->conn->insertID();
        $this->buildingRowIds[] = $id;

        return $id;
    }

    private function ensureBiomeRow(): int
    {
        $existing = $this->conn->table('biomes')->where('name', 'AlertRateTestForest')->get()->getRowArray();
        if (is_array($existing)) {
            return (int) $existing['id'];
        }
        $this->conn->table('biomes')->insert([
            'name'            => 'AlertRateTestForest',
            'description'     => 'test',
            'biome_type'      => 'plain',
            'danger_level'    => 1,
            'occurrence_rate' => 1.0,
        ]);

        return (int) $this->conn->insertID();
    }

    private function createSelfConsistentCell(): int
    {
        $id = random_int(900_000_000, 999_999_999);
        $this->conn->table('map')->insert([
            'id'           => $id,
            'cell_number'  => $id,
            'coordinate_x' => 0,
            'coordinate_y' => 100,
            'biome_id'     => $this->biomeId,
        ]);
        $this->mapIds[] = $id;

        return $id;
    }

    /**
     * @param array{telegram_id:int} $opts
     * @return array{id:int,tgId:int}
     */
    private function insertCharacter(int $cell, array $opts, int $level = 10, bool $veryHighHealth = false): array
    {
        $this->conn->table('telegram_users')->insert(['telegram_id' => $opts['telegram_id']]);
        $telegramUserId          = (int) $this->conn->insertID();
        $this->telegramUserIds[] = $telegramUserId;

        $health = $veryHighHealth ? 99999 : 100;

        $this->conn->table('characters')->insert([
            'name'             => 'T' . random_int(100000, 999999),
            'level'            => $level,
            'health'           => $health,
            'tired'            => 100,
            'strength'         => 50,
            'agility'          => 50,
            'intellect'        => 50,
            'experience'       => 100,
            'gold'             => 1000,
            'cell_number'      => $cell,
            'telegram_user_id' => $telegramUserId,
            'created_at'       => date('Y-m-d H:i:s', time() - 60 * 86400),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $id                   = (int) $this->conn->insertID();
        $this->characterIds[] = $id;

        return ['id' => $id, 'tgId' => $opts['telegram_id']];
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

    private function setBoolSetting(string $key, bool $value): void
    {
        $this->conn->table('game_settings')->where('setting_key', $key)->update(['value_bool' => $value ? 1 : 0]);
        $this->cleanCache();
    }

    private function setIntSetting(string $key, int $value): void
    {
        $this->conn->table('game_settings')->where('setting_key', $key)->update(['value_int' => $value]);
        $this->cleanCache();
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
