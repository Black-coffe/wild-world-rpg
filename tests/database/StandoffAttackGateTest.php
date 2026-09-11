<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\PVP\AttackPlayerAction;
use App\Controllers\Telegram\Commands\Actions\PVP\StandoffCheckAction;
use App\Controllers\Telegram\Commands\Actions\PVP\StandoffLeaveAction;
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
use App\Services\PVE\DefenseStructureService;
use App\Services\PVE\PvpStandoffService;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;
use ReflectionClass;
use ReflectionMethod;

/**
 * pvp-detection-clarity-08 — гейт окна в `AttackPlayerAction::handle()` ДО записи
 * анти-спам-кулдауна, экран ожидания, профиль обороны владельца базы при
 * контратаке, надбавка «укрыться» под потолком, lock-объяснение вместо «⚠️ Ошибка».
 *
 * Схема строится прогоном настоящих классов миграций (тот же приём, что
 * `PvpStandoffServiceTest`/`DemolishBuildingTest`; создаём только отсутствующие
 * таблицы — CI гоняет набор на пустой базе, локальный стенд их обычно уже несёт).
 * Хвост из `plan.md` (`## Assumptions`): у `battle_logs` нет собственной
 * `createTable`-миграции — схема хендролится, как в `AttackPlayerActionFixtureFenceTest`.
 *
 * Гейт окна (`resolveStandoffPreGate()`/`resolveStandoffOpen()`/`applyHoldBonus()`) —
 * приватные методы, тестируются Reflection'ом без Telegram-сети, тем же приёмом,
 * что `isCellsCloseEnough()`/`toCharacterArray()` в существующих тестах. Путь «от
 * кнопки до боя» (killswitch OFF / истечение по времени / контратака) проверяется
 * реальным `handle()` через настоящий `CallbackQuery` — оба бойца получают
 * искусственно огромное здоровье, чтобы бой гарантированно завершился `exhausted`
 * (150 раундов без смерти) и не заходил в `DeathService`/лут/ладдер/трофеи —
 * это отдельная, намного более тяжёлая поверхность соседних story.
 *
 * pvp-detection-clarity-13 (BLOCK критично #1 / major #5 / хвост #18) — гейт
 * пересобран на два хода: `resolveStandoffPreGate()` читает контратаку/заморозку
 * БЕЗ письма и алерта, `resolveStandoffOpen()` (пишет строку + шлёт алерт) —
 * только после того, как `handle()` проверил смежность и `checkPvPAllowed()`.
 * Плюс честный экран для атакующего, попавшего на ЧУЖОЕ открытое окно, и
 * `applyHoldBonus()` больше не синтезирует профиль обороны из `null` (ADR-030).
 *
 * @internal
 */
final class StandoffAttackGateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    /** @var array<string,bool> */
    private array $created = [];
    private bool $alteredDefensiveEnum   = false;
    private bool $seededStandoffSettings = false;
    private int $woodenWallBuildingId    = 0;
    private int $biomeId                 = 0;

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

        $this->created['telegram_users']     = $this->createIfMissing('telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php');
        $this->created['characters']         = $this->createIfMissing('characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php');
        $this->created['map']                = $this->createIfMissing('map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php');
        $this->created['biomes']             = $this->createIfMissing('biomes', CreateBiomesTable::class, '2024-03-17-222643_CreateBiomesTable.php');
        $this->created['game_settings']      = $this->createIfMissing('game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php');
        $this->created['factions']           = $this->createIfMissing('factions', CreateFactionsTable::class, '2024-05-15-131853_CreateFactionsTable.php');
        $this->created['character_factions'] = $this->createIfMissing('character_factions', CreateCharacterFactionsTable::class, '2024-05-15-132233_CreateCharacterFactionsTable.php');
        $this->created['buildings']          = $this->createIfMissing('buildings', CreateBuildingsTable::class, '2024-05-23-090819_CreateBuildingsTable.php');
        $this->created['character_buildings'] = $this->createIfMissing('character_buildings', CreateCharacterBuildingsTable::class, '2024-05-27-105534_CreateCharacterBuildingsTable.php');
        $this->created['action_log']         = $this->createIfMissing('action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php');
        $this->created['pvp_standoffs']      = $this->createIfMissing('pvp_standoffs', Adr186CreatePvpStandoffs::class, '2026-09-11-210000_Adr186CreatePvpStandoffs.php');
        $this->created['claimed_cells']      = $this->createIfMissing('claimed_cells', CreateClaimedCellsTable::class, '2024-05-23-061031_CreateClaimedCellsTable.php');
        $this->created['explored_cells']     = $this->createIfMissing('explored_cells', CreateExploredCellsTable::class, '2024-03-24-212921_CreateExploredCellsTable.php');
        $this->created['tasks']              = $this->createIfMissing('tasks', CreateTasksTable::class, '2024-03-22-111828_CreateTasksTable.php');
        $this->created['character_tasks']    = $this->createIfMissing('character_tasks', CreateCharacterTasksTable::class, '2024-03-22-132411_CreateCharacterTasksTable.php');
        $this->created['weapons']            = $this->createIfMissing('weapons', CreateWeaponsTable::class, '2025-02-08-195713_CreateWeaponsTable.php');
        $this->created['outfits']            = $this->createIfMissing('outfits', CreateOutfitsTable::class, '2025-02-08-194808_CreateOutfitsTable.php');
        $this->created['characters_weapons'] = $this->createIfMissing('characters_weapons', CreateCharactersWeaponsTable::class, '2025-02-11-115603_CreateCharactersWeaponsTable.php');
        $this->created['characters_outfits'] = $this->createIfMissing('characters_outfits', CreateCharactersOutfitsTable::class, '2025-02-10-224703_CreateCharactersOutfitsTable.php');

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

        // ENUM building_type → +'defensive' (то же, что S26AddDefensiveStructures,
        // без его зависимости на tasks.handler_key/events/world_objects).
        if ($this->created['buildings'] || $this->created['character_buildings'] || ! $this->hasDefensiveEnumValue()) {
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
        $this->biomeId              = $this->ensureBiomeRow();

        $this->setBoolSetting('pvp.standoff.enabled', false);
        $this->setBoolSetting('pvp.standoff.require_tower', false);
        $this->setIntSetting('pvp.standoff.window_sec', 300);
        $this->setIntSetting('pvp.standoff.cooldown_sec', 900);
        $this->setIntSetting('pvp.standoff.hold_damage_reduction_percent', 10);
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

    // ---------------------------------------------------------------- resolveStandoffOutcome()

    public function testKillswitchOffSkipsAllStandoffLogicAndByteIdenticalCombatHappens(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', false);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], veryHighHealth: true);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], veryHighHealth: true);
        $this->placeWoodenWall($defender['id'], $cell);

        $standoffsBefore = $this->conn->table('pvp_standoffs')->countAllResults();
        $battlesBefore   = $this->conn->table('battle_logs')->countAllResults();
        $response        = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $standoffsAfter  = $this->conn->table('pvp_standoffs')->countAllResults();
        $battlesAfter    = $this->conn->table('battle_logs')->countAllResults();

        $this->assertSame($standoffsBefore, $standoffsAfter, 'killswitch OFF: ни одна строка pvp_standoffs не пишется');
        // handle() возвращает Request::emptyResponse() на успешном боевом пути (сам
        // результат уходит отдельным Request::sendMessage(), не в return) — «дошло
        // до боя» доказывается battle_logs, а не текстом возврата handle().
        $this->assertSame($battlesBefore + 1, $battlesAfter, 'killswitch OFF: путь атаки доходит до обычного боя, а не отбивается экраном окна');
        $this->assertStringNotContainsString('Осталось', $this->responseText($response), 'не должно быть экрана ожидания при выключенном окне');
    }

    public function testFirstAttackOnDefenderWithBaseOpensWindowInsteadOfFighting(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        $response = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $text     = $this->responseText($response);

        $this->assertStringContainsString('Осталось', $text, 'первая атака на защитника с базой обязана дать экран ожидания');
        $this->assertStringNotContainsString('⚠️ Ошибка', $text);

        $buttons = $this->flattenButtons($response);
        $callbacks = array_column($buttons, 'callback_data');
        $this->assertContains("standoffCheck_" . $this->currentStandoffId($attacker['id'], $defender['id']), $callbacks);
        $this->assertContains("standoffLeave_" . $this->currentStandoffId($attacker['id'], $defender['id']), $callbacks);

        $row = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker['id'])->where('defender_id', $defender['id'])->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('open', $row['status']);
    }

    public function testRepeatedTapOnFrozenTargetDoesNotPayCooldownAgain(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        $first = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);

        // pvp-detection-clarity-19 (AC#1): открытие НОВОГО окна само платит
        // анти-спам-кулдаун атакующего — это и есть ограничитель цикла
        // «атаковать → уйти → атаковать» (BLOCK-2 критично #A).
        $cache      = \Config\Services::cache();
        $afterFirst = $cache->get("pvp_attack_cd_{$attacker['id']}");
        $this->assertIsInt($afterFirst, 'открытие нового окна обязано зафиксировать анти-спам-кулдаун атакующего');

        $second = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);

        $this->assertStringContainsString('Осталось', $this->responseText($first));
        $this->assertStringContainsString('Осталось', $this->responseText($second), 'повторный тап по замороженной цели обязан отбиться тем же экраном');

        // AC#4: «экран ожидания» — повторный тап по СВОЕМУ уже открытому окну —
        // остаётся бесплатным, кулдаун не продлевается заново вторым тапом.
        $this->assertSame($afterFirst, $cache->get("pvp_attack_cd_{$attacker['id']}"), 'повторный тап по замороженной цели не должен продлевать кулдаун заново');

        $rows = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker['id'])->where('defender_id', $defender['id'])->countAllResults();
        $this->assertSame(1, $rows, 'повторный тап не должен открывать второе окно');
    }

    public function testExpiredWindowByTimeAllowsNormalAttackWithoutCronTick(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], veryHighHealth: true);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], veryHighHealth: true);
        $this->placeWoodenWall($defender['id'], $cell);

        // Открытое (status='open'), но давно истёкшее по времени окно — крон ни разу не прошёл.
        $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'open', -60);

        $battlesBefore = $this->conn->table('battle_logs')->countAllResults();
        $response      = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $battlesAfter  = $this->conn->table('battle_logs')->countAllResults();

        $this->assertStringNotContainsString('Осталось', $this->responseText($response), 'истёкшее по времени окно не должно отбивать атаку');
        $this->assertSame($battlesBefore + 1, $battlesAfter, 'атака после истечения обязана дойти до боя (battle_logs — не только внутренний переход статуса)');
    }

    public function testStandoffCheckActionRefreshesScreenThenReportsResolution(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $standoffId = $this->currentStandoffId($attacker['id'], $defender['id']);
        $this->assertGreaterThan(0, $standoffId);

        $stillOpen = (new StandoffCheckAction($this->callbackQuery($attacker['tgId'], "standoffCheck_{$standoffId}")))->handle();
        $this->assertStringContainsString('Осталось', $this->responseText($stillOpen), '«⏳ Проверить» на живом окне перерисовывает тот же экран');

        // Чужой тап (не атакующий этого окна) — объяснение, не раскрытие состояния.
        $stranger = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $strangerResp = (new StandoffCheckAction($this->callbackQuery($stranger['tgId'], "standoffCheck_{$standoffId}")))->handle();
        $this->assertStringContainsString('не твоё', $this->responseText($strangerResp));

        $this->conn->table('pvp_standoffs')->where('id', $standoffId)->update(['status' => 'fled']);
        $resolved = (new StandoffCheckAction($this->callbackQuery($attacker['tgId'], "standoffCheck_{$standoffId}")))->handle();
        $this->assertStringContainsString('сбежала', $this->responseText($resolved));
    }

    public function testStandoffLeaveActionCancelsOnceAndRefusesSecondTap(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $standoffId = $this->currentStandoffId($attacker['id'], $defender['id']);

        $first = (new StandoffLeaveAction($this->callbackQuery($attacker['tgId'], "standoffLeave_{$standoffId}")))->handle();
        $this->assertStringContainsString('передумал', $this->responseText($first));

        $row = $this->conn->table('pvp_standoffs')->where('id', $standoffId)->get()->getRowArray();
        $this->assertSame('cancelled', $row['status']);

        $second = (new StandoffLeaveAction($this->callbackQuery($attacker['tgId'], "standoffLeave_{$standoffId}")))->handle();
        $this->assertStringContainsString('уже отреагировал', $this->responseText($second));

        // pvp-detection-clarity-19 (AC#1, BLOCK-2 критично #A): первый invokeAttack()
        // выше уже открыл окно и потратил анти-спам-кулдаун атакующего — немедленное
        // повторное открытие после «Уйти» обязано упереться именно в него, а не
        // крутиться бесплатно (это и была регрессия -13/-14 в сумме).
        $again     = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $againText = $this->responseText($again);

        $this->assertStringContainsString('Подождите', $againText, 'немедленное повторное открытие после «Уйти» обязано упереться в собственный кулдаун атакующего');

        $rowsAfterCooldownBlock = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker['id'])->where('defender_id', $defender['id'])->countAllResults();
        $this->assertSame(1, $rowsAfterCooldownBlock, 'тап, отбитый кулдауном, не создаёт вторую строку окна');

        // Non-goal story `-14` остаётся в силе отдельно от кулдауна атакующего:
        // `cancelled` не армирует кулдаун ЗАЩИТНИКА
        // ({@see \App\Services\PVE\PvpStandoffService::COOLDOWN_ARMING_STATUSES}).
        // Сняв только кулдаун атакующего (эмулируя, что 30 сек прошли — реальный
        // сценарий следующего честного тапа), реоткрытие обязано удаться сразу,
        // без штрафа за прошлую отмену на стороне защитника.
        $cache = \Config\Services::cache();
        $cache->delete("pvp_attack_cd_{$attacker['id']}");
        $this->conn->table('characters')->where('id', $attacker['id'])->update(['health' => 99999]);
        $this->conn->table('characters')->where('id', $defender['id'])->update(['health' => 99999]);
        $reopened     = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $reopenedText = $this->responseText($reopened);

        $this->assertStringNotContainsString('Подождите', $reopenedText, 'после снятия своего кулдауна атакующий не должен нести штраф за прошлую отмену защитника');
        $this->assertStringContainsString('Осталось', $reopenedText, 'новое окно открывается заново — отменённое не кулдаунит защитника');

        $rows = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker['id'])->where('defender_id', $defender['id'])->countAllResults();
        $this->assertSame(2, $rows, 'вторая попытка — отдельная новая строка окна, не бой мимо гейта');
    }

    public function testCounterAttackReachesCombatBypassingFreezeAndDefenderOwnCooldown(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], veryHighHealth: true);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], veryHighHealth: true);
        $this->placeWoodenWall($defender['id'], $cell);

        $standoffId = $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'open', 300);

        // Защитник только что «атаковал» что-то своё — его собственный анти-спам
        // кулдаун активен. «⚔️ Ударить первым» обязан пройти НЕСМОТРЯ на это.
        $cache = \Config\Services::cache();
        $cache->save("pvp_attack_cd_{$defender['id']}", time(), 30);

        $before = $this->conn->table('battle_logs')->countAllResults();
        // Защитник (базовладелец) жмёт `attackPlayer_<attacker_id>` с алерта.
        $response = $this->invokeAttack($defender['tgId'], $defender['id'], $attacker['id']);
        $text     = $this->responseText($response);
        $after    = $this->conn->table('battle_logs')->countAllResults();

        $this->assertStringNotContainsString('Подождите', $text, 'контратака не должна отбиваться своим кулдауном');
        $this->assertStringNotContainsString('Осталось', $text, 'контратака не должна отбиваться гейтом окна');
        // handle() возвращает Request::emptyResponse() на успешном боевом пути — «дошло
        // до реального боя, а не только до смены статуса» доказывается battle_logs.
        $this->assertSame($before + 1, $after, 'бой обязан записаться в battle_logs — это и есть проверка «дошло до боя»');

        $row = $this->conn->table('pvp_standoffs')->where('id', $standoffId)->get()->getRowArray();
        $this->assertSame('countered', $row['status']);
    }

    public function testLockButtonTapExplainsInsteadOfError(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', false);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], level: 1);

        $response = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $text     = $this->responseText($response);

        $this->assertStringContainsString('🔒', $text);
        $this->assertStringNotContainsString('⚠️ Ошибка', $text, 'UX-Discoverability: тап по замку объясняет, а не отказывает');
        $this->assertStringContainsString('качай уровень', $text);

        // AC#4 (pvp-detection-clarity-19): тап по замку — «уровень» имеет
        // lock-кнопку в PlayerDetectionService, поэтому бесплатен.
        $cache = \Config\Services::cache();
        $this->assertNull($cache->get("pvp_attack_cd_{$attacker['id']}"), 'объяснение замка не должно стоить анти-спам-кулдауна');
    }

    // ---------------------------------------------------------------- pvp-detection-clarity-13 (BLOCK #1 / major #5)

    public function testFarCellDoesNotOpenStandoffOrAlertDefender(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $farCell  = $this->createSelfConsistentCell();
        $nearCell = $this->createDistantCell(500, 500);
        $defender = $this->insertCharacter($farCell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($nearCell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $farCell);

        $response = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $text     = $this->responseText($response);

        $this->assertStringContainsString('далеко', $text, 'BLOCK #1: смежность обязана быть проверена ДО открытия окна');
        $this->assertStringNotContainsString('Осталось', $text);

        $rows = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker['id'])->where('defender_id', $defender['id'])->countAllResults();
        $this->assertSame(0, $rows, 'далёкая цель не должна открывать окно и поднимать тревогу защитнику');

        // pvp-detection-clarity-19 (BLOCK-2 major #B, AC#3): отбитый по смежности
        // тап — как на origin/develop — снова стоит атакующему анти-спам-кулдаун.
        $cache = \Config\Services::cache();
        $this->assertIsInt($cache->get("pvp_attack_cd_{$attacker['id']}"), 'тап по далёкой цели обязан фиксировать анти-спам-кулдаун');
    }

    public function testRestrictedPairDoesNotOpenStandoff(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        // Уровень 1 < min_level по умолчанию (5) — checkPvPAllowed() обязан отбить тап
        // раньше, чем resolveStandoffOpen() успеет открыть окно и позвать защитника.
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], level: 1);
        $this->placeWoodenWall($defender['id'], $cell);

        $response = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $text     = $this->responseText($response);

        $this->assertStringContainsString('🔒', $text);
        $this->assertStringNotContainsString('Осталось', $text);

        $rows = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker['id'])->where('defender_id', $defender['id'])->countAllResults();
        $this->assertSame(0, $rows, 'запрещённая PvP-пара (уровень ниже порога) не должна открывать окно');

        // pvp-detection-clarity-19 (AC#4): «уровень» — одна из трёх причин с
        // lock-кнопкой в PlayerDetectionService, поэтому тап по ней бесплатен
        // (та же lock-explanation, что тап прямо по кнопке «🔒»).
        $cache = \Config\Services::cache();
        $this->assertNull($cache->get("pvp_attack_cd_{$attacker['id']}"), 'запрет с lock-кнопкой (уровень) не должен стоить анти-спам-кулдауна');
    }

    /**
     * pvp-detection-clarity-19 (BLOCK-2 major #B): `map_missing` — единственная
     * причина `checkPvPAllowed()`, у которой нет lock-метки в
     * `PlayerDetectionService::lockLabel()` — и попасть на неё с рендеренной
     * кнопки нельзя вовсе: детект-список берёт игроков INNER JOIN'ом по `map`,
     * такого игрока там нет. Значит это настоящая запрещённая пара «не с
     * кнопки-замка» (AC#3), и она обязана платить кулдаун, как далёкая цель.
     */
    public function testRestrictedPairWithoutLockButtonChargesCooldown(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        // Общая (несуществующая на `map`) клетка у обоих — isCellsCloseEnough()
        // проходит по равенству cell_number без чтения `map`, а checkPvPAllowed()
        // падает на собственном чтении `map` и возвращает `reason_code=map_missing`.
        $ghostCell = 987_654_321;
        $defender  = $this->insertCharacter($ghostCell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker  = $this->insertCharacter($ghostCell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);

        $response = $this->invokeAttack($attacker['tgId'], $attacker['id'], $defender['id']);
        $text     = $this->responseText($response);

        $this->assertStringContainsString('🔒', $text);
        $this->assertStringNotContainsString('Осталось', $text);

        $rows = $this->conn->table('pvp_standoffs')->where('attacker_id', $attacker['id'])->where('defender_id', $defender['id'])->countAllResults();
        $this->assertSame(0, $rows, 'запрещённая пара без карты не должна открывать окно');

        $cache = \Config\Services::cache();
        $this->assertIsInt($cache->get("pvp_attack_cd_{$attacker['id']}"), 'причина без lock-кнопки обязана платить анти-спам-кулдаун');
    }

    public function testAttackerHittingForeignOpenWindowSeesNoOwnershipTrapButtons(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell      = $this->createSelfConsistentCell();
        $defender  = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker1 = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker2 = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->placeWoodenWall($defender['id'], $cell);

        $this->invokeAttack($attacker1['tgId'], $attacker1['id'], $defender['id']);
        $ownStandoffId = $this->currentStandoffId($attacker1['id'], $defender['id']);
        $this->assertGreaterThan(0, $ownStandoffId);

        $response = $this->invokeAttack($attacker2['tgId'], $attacker2['id'], $defender['id']);
        $text     = $this->responseText($response);

        // BLOCK major #5: второй атакующий не должен получить чужие кнопки
        // владения (`standoffCheck_<id>`/`standoffLeave_<id>` первого атакующего) —
        // либо кнопки его собственные, либо их нет вовсе.
        $buttons = $this->flattenButtons($response);
        foreach ($buttons as $button) {
            $callback = (string) $button['callback_data'];
            $this->assertStringNotContainsString((string) $ownStandoffId, $callback, 'чужой standoffId не должен попадать в кнопки второго атакующего');
        }
        $this->assertStringNotContainsString('не твоё', $text, 'экран не должен содержать ловушку владения');
        $this->assertStringContainsString('чужой тревогой', $text, 'текст обязан честно объяснить причину отказа');

        // pvp-detection-clarity-19 (BLOCK-2 minor #F): экран без чужих кнопок
        // владения — не тупик без единой кнопки; должен остаться хотя бы один
        // рабочий путь дальше (на карту), в котором игроку не откажут.
        $this->assertNotEmpty($buttons, 'экран «чужая тревога» не должен быть тупиком без единой кнопки');

        // pvp-detection-clarity-23 (BLOCK-3 major #1): проверяем ФОРМУ РЯДОВ на
        // выходе нормализатора — одиночная кнопка в ряду нарушает 🔴-правило
        // ButtonPacker и AC story -19 «2-3 в ряд», даже если кнопок в сумме много.
        foreach ($this->keyboardRows($response) as $row) {
            $this->assertGreaterThanOrEqual(2, count($row), 'ни один ряд клавиатуры не может нести одиночную кнопку');
        }

        $rows = $this->conn->table('pvp_standoffs')->where('defender_id', $defender['id'])->countAllResults();
        $this->assertSame(1, $rows, 'второй тап не должен открывать второе окно на того же защитника');
    }

    /**
     * pvp-detection-clarity-19 — мост между двумя независимыми списками причин:
     * `PlayerDetectionService::lockLabel()` решает, рисовать ли замок с конкретной
     * подсказкой на карте, `AttackPlayerAction::restrictionReasonHasLockButton()`
     * решает, платит ли тап по `checkPvPAllowed()`-отказу кулдаун. Связаны только
     * соглашением («2 системы ключей без моста» — тот же класс дыры, что уже
     * ловили раньше: молчаливый рассинхрон даёт мёртвую/откаченную фичу с
     * зелёными тестами). `PlayerDetectionService.php` держит story `-22` —
     * читаем его приватный `lockLabel()` тем же Reflection-приёмом, что уже есть
     * в этом файле для приватных методов `AttackPlayerAction`, не трогая файл.
     *
     * Оба метода чистые (не читают `$this`), поэтому `newInstanceWithoutConstructor()`
     * безопасен — конструктор `PlayerDetectionService` тянет `GameBalance`/DI,
     * который `lockLabel()` не использует вовсе.
     *
     * Причины перечислены буквально по контракту `PvPRestrictionService::checkPvPAllowed()`
     * (`level` / `map_missing` / `safe_zone` / `account_age`) плюс контрольный
     * незнакомый код — доказывает, что ОБЕ стороны одинаково относят его к «нет
     * замка», а не расходятся молча.
     */
    public function testLockButtonReasonsMatchCooldownExemptReasons(): void
    {
        // pvp-detection-clarity-23 (BLOCK-3 minor #3): перечень реальных кодов
        // читается из источника (`checkPvPAllowed()`), а не переписывается
        // литералом в тесте — код, добавленный в сервис и забытый здесь,
        // раньше мог разъехаться молча. Контрольный незнакомый код остаётся
        // литералом намеренно: его нет и не может быть в источнике, он
        // доказывает, что ОБЕ стороны одинаково относят «неизвестный код» к
        // «нет замка».
        $reasonCodes = [...$this->pvpAllowedReasonCodes(), 'some_future_reason_code'];

        $detectionService = (new ReflectionClass(\App\Services\Player\PlayerDetectionService::class))
            ->newInstanceWithoutConstructor();
        $lockLabel = new ReflectionMethod(\App\Services\Player\PlayerDetectionService::class, 'lockLabel');
        $lockLabel->setAccessible(true);
        $genericLabel = (string) $lockLabel->invoke($detectionService, 'some_future_reason_code');

        $hasLockButton = new ReflectionMethod(AttackPlayerAction::class, 'restrictionReasonHasLockButton');
        $hasLockButton->setAccessible(true);

        foreach ($reasonCodes as $reasonCode) {
            $label            = (string) $lockLabel->invoke($detectionService, $reasonCode);
            $rendersAsLock    = $label !== $genericLabel;
            $chargesNoCooldown = (bool) $hasLockButton->invoke($this->action(), $reasonCode);

            $this->assertSame(
                $rendersAsLock,
                $chargesNoCooldown,
                "reason_code='{$reasonCode}': PlayerDetectionService::lockLabel() и "
                . "AttackPlayerAction::restrictionReasonHasLockButton() разошлись — "
                . 'один список знает эту причину замка, а другой нет'
            );
        }
    }

    // ---------------------------------------------------------------- Reflection: resolveStandoffPreGate()/resolveStandoffOpen()/applyHoldBonus()

    public function testResolveStandoffPreGateDisabledReturnsNoopDefaults(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', false);
        $svc = new PvpStandoffService();

        $preGate = $this->resolvePreGate($svc, ['id' => 1, 'cell_number' => 100], ['id' => 2, 'cell_number' => 100]);

        $this->assertFalse($preGate['enabled']);
        $this->assertFalse($preGate['block']);
        $this->assertFalse($preGate['isCounterAttack']);
        $this->assertSame(2, $preGate['baseOwnerId']);
        $this->assertSame(100, $preGate['baseOwnerCell']);

        $outcome = $this->resolveOpen($svc, ['id' => 1, 'cell_number' => 100], ['id' => 2, 'cell_number' => 100], $preGate);
        $this->assertSame(0, $outcome['holdBonusPercent'], 'killswitch OFF: resolveStandoffOpen() не открывает окно и не считает бонус');
    }

    public function testResolveStandoffPreGateDetectsCounterAttackAndSwapsBaseOwner(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $baseOwner = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $fieldGuy  = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $standoffId = $this->insertStandoffRow($fieldGuy['id'], $baseOwner['id'], $cell, 'open', 300);

        $svc = new PvpStandoffService();
        // Базовладелец жмёт «⚔️ Ударить первым»: локально он в слоте $attacker, а
        // цель ($defender) — исходный нападавший.
        $preGate = $this->resolvePreGate(
            $svc,
            ['id' => $baseOwner['id'], 'cell_number' => $cell],
            ['id' => $fieldGuy['id'], 'cell_number' => $cell]
        );

        $this->assertTrue($preGate['isCounterAttack']);
        $this->assertSame($standoffId, $preGate['counterStandoffId']);
        $this->assertSame($baseOwner['id'], $preGate['baseOwnerId'], 'профиль обороны обязан резолвиться для владельца базы, а не для того, кто локально в слоте $defender');
        $this->assertSame($cell, $preGate['baseOwnerCell']);

        // resolveStandoffOpen() для контратаки — no-op: она уже полностью решена
        // в Фазе 1 и не должна пытаться открыть новое окно.
        $outcome = $this->resolveOpen(
            $svc,
            ['id' => $baseOwner['id'], 'cell_number' => $cell],
            ['id' => $fieldGuy['id'], 'cell_number' => $cell],
            $preGate
        );
        $this->assertFalse($outcome['block']);
        $this->assertTrue($outcome['isCounterAttack']);
        $this->assertSame($standoffId, $outcome['counterStandoffId']);
    }

    public function testResolveStandoffOpenReadsMostRecentHeldRowForHoldBonus(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);
        $this->setIntSetting('pvp.standoff.hold_damage_reduction_percent', 15);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'held', -300);

        $svc          = new PvpStandoffService();
        $attackerData = ['id' => $attacker['id'], 'cell_number' => $cell];
        $defenderData = ['id' => $defender['id'], 'cell_number' => $cell];
        $preGate      = $this->resolvePreGate($svc, $attackerData, $defenderData);
        $outcome      = $this->resolveOpen($svc, $attackerData, $defenderData, $preGate);

        $this->assertFalse($outcome['block']);
        $this->assertFalse($outcome['isCounterAttack']);
        $this->assertSame(15, $outcome['holdBonusPercent']);
    }

    public function testResolveStandoffOpenSkipsHoldBonusOnceHeldRowIsOlderThanCooldown(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);
        $this->setIntSetting('pvp.standoff.cooldown_sec', 900);
        $this->setIntSetting('pvp.standoff.hold_damage_reduction_percent', 15);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $standoffId = $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'held', -300);
        // База исчезла (снос/уход), а не «ещё на кулдауне»: строка `held` состарилась
        // за пределы pvp.standoff.cooldown_sec, значит она уже не «недавний бой».
        // Время — часами БД (NOW() - INTERVAL), не PHP date().
        $this->conn->query('UPDATE pvp_standoffs SET updated_at = (NOW() - INTERVAL 1000 SECOND) WHERE id = ?', [$standoffId]);

        $svc          = new PvpStandoffService();
        $attackerData = ['id' => $attacker['id'], 'cell_number' => $cell];
        $defenderData = ['id' => $defender['id'], 'cell_number' => $cell];
        $preGate      = $this->resolvePreGate($svc, $attackerData, $defenderData);
        $outcome      = $this->resolveOpen($svc, $attackerData, $defenderData, $preGate);

        $this->assertSame(0, $outcome['holdBonusPercent'], 'held-строка старше cooldown_sec — бонус утекал бы бессрочно, если база исчезла, а не только кулдаунит');
    }

    public function testResolveStandoffOpenDisablesHoldBonusWhenCooldownSecIsZero(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);
        $this->setIntSetting('pvp.standoff.cooldown_sec', 0);
        $this->setIntSetting('pvp.standoff.hold_damage_reduction_percent', 15);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'held', -300);

        $svc          = new PvpStandoffService();
        $attackerData = ['id' => $attacker['id'], 'cell_number' => $cell];
        $defenderData = ['id' => $defender['id'], 'cell_number' => $cell];
        $preGate      = $this->resolvePreGate($svc, $attackerData, $defenderData);
        $outcome      = $this->resolveOpen($svc, $attackerData, $defenderData, $preGate);

        $this->assertSame(0, $outcome['holdBonusPercent'], 'cooldown_sec=0 отключает бонус вовсе — иначе граница пропадает');
    }

    public function testResolveStandoffOpenIgnoresHeldRowIfSupersededByLaterStatus(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', true);

        $cell     = $this->createSelfConsistentCell();
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'held', -600);
        $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'fled', -300);

        $svc          = new PvpStandoffService();
        $attackerData = ['id' => $attacker['id'], 'cell_number' => $cell];
        $defenderData = ['id' => $defender['id'], 'cell_number' => $cell];
        $preGate      = $this->resolvePreGate($svc, $attackerData, $defenderData);
        $outcome      = $this->resolveOpen($svc, $attackerData, $defenderData, $preGate);

        $this->assertSame(0, $outcome['holdBonusPercent'], 'самая свежая строка ("fled") перекрывает старую "held"');
    }

    public function testApplyHoldBonusStaysUnderTotalReductionCap(): void
    {
        $this->setIntSetting('pvp.standoff.hold_damage_reduction_percent', 30);
        $defense = new DefenseStructureService();
        $cap     = $defense->totalReductionCapPercent();

        $profile = [
            'owner_id'         => 5,
            'damage_reduction' => ($cap - 5) / 100.0,
            'fence_damage'     => 3,
            'initiative_bonus' => 0.0,
            'structure_ids'    => [11],
        ];

        $result = $this->applyHoldBonus($profile, $defense, 5, 30);

        $this->assertIsArray($result);
        $this->assertSame($cap / 100.0, $result['damage_reduction'], 'потолок defense.total_damage_reduction_max_percent не пробивается надбавкой');
        $this->assertSame(5, $result['owner_id']);
        $this->assertSame([11], $result['structure_ids']);
    }

    public function testApplyHoldBonusReturnsNullWhenNoDefenseProfile(): void
    {
        // 🟡 Хвост #18 (ADR-030, pvp-detection-clarity-13): защитник без единой
        // живой постройки не получает синтезированный профиль обороны — «укрыться»
        // усиливает существующую защиту, а не создаёт её из ничего.
        $defense = new DefenseStructureService();
        $result  = $this->applyHoldBonus(null, $defense, 7, 10);

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------- helpers

    private function action(): AttackPlayerAction
    {
        return (new ReflectionClass(AttackPlayerAction::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string,mixed> $attacker
     * @param array<string,mixed> $defender
     * @return array<string,mixed>
     */
    private function resolvePreGate(PvpStandoffService $svc, array $attacker, array $defender): array
    {
        $m = new ReflectionMethod(AttackPlayerAction::class, 'resolveStandoffPreGate');
        $m->setAccessible(true);

        return $m->invoke($this->action(), $svc, $attacker, $defender);
    }

    /**
     * @param array<string,mixed> $attacker
     * @param array<string,mixed> $defender
     * @param array<string,mixed> $preGate
     * @return array<string,mixed>
     */
    private function resolveOpen(PvpStandoffService $svc, array $attacker, array $defender, array $preGate): array
    {
        $m = new ReflectionMethod(AttackPlayerAction::class, 'resolveStandoffOpen');
        $m->setAccessible(true);

        return $m->invoke($this->action(), $svc, $attacker, $defender, $preGate);
    }

    /**
     * @param array<string,mixed>|null $profile
     * @return array<string,mixed>|null
     */
    private function applyHoldBonus(?array $profile, DefenseStructureService $defense, int $ownerId, int $bonus): ?array
    {
        $m = new ReflectionMethod(AttackPlayerAction::class, 'applyHoldBonus');
        $m->setAccessible(true);

        return $m->invoke($this->action(), $profile, $defense, $ownerId, $bonus);
    }

    private function invokeAttack(int $tgId, int $attackerId, int $targetId): ServerResponse
    {
        return (new AttackPlayerAction($this->callbackQuery($tgId, "attackPlayer_{$targetId}")))->handle();
    }

    private function currentStandoffId(int $attackerId, int $defenderId): int
    {
        $row = $this->conn->table('pvp_standoffs')
            ->where('attacker_id', $attackerId)
            ->where('defender_id', $defenderId)
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();

        return is_array($row) ? (int) $row['id'] : 0;
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

    /**
     * pvp-detection-clarity-23 (BLOCK-3 minor #3): `checkPvPAllowed()` не
     * выставляет свои коды отказа программным списком (ни константы, ни enum,
     * ни метода-перечисления) — только `'reason_code' => '<код>'` в каждом
     * `return`-блоке. Источник читается сканом исходника (тот же приём, что
     * `token_get_all` в `CallbackDataRoutingTest`/`CommunityGuardTest`), а не
     * переписывается литералом в тесте: код, добавленный в сервис и забытый
     * здесь, ловится по расхождению множеств, а не тонет молча.
     *
     * @return list<string>
     */
    private function pvpAllowedReasonCodes(): array
    {
        $file = (new ReflectionClass(\App\Services\Player\PvPRestrictionService::class))->getFileName();
        $src  = $file !== false ? (string) file_get_contents($file) : '';

        preg_match_all("/'reason_code'\\s*=>\\s*'([a-z_]+)'/", $src, $matches);

        $codes = array_values(array_unique($matches[1] ?? []));
        $this->assertNotEmpty($codes, 'checkPvPAllowed() не отдал ни одного reason_code сканом исходника — источник переехал?');

        return $codes;
    }

    private function responseText(ServerResponse $response): string
    {
        $result = $response->getResult();
        if (! is_object($result) || ! method_exists($result, 'getText')) {
            return '';
        }

        return (string) ($result->getText() ?? '');
    }

    /**
     * @return list<array{text:string,callback_data:string}>
     */
    private function flattenButtons(ServerResponse $response): array
    {
        $flat = [];
        foreach ($this->keyboardRows($response) as $row) {
            foreach ($row as $button) {
                $flat[] = $button;
            }
        }

        return $flat;
    }

    /**
     * pvp-detection-clarity-23: ряды КАК ОНИ ЕСТЬ на выходе `ButtonPacker::pack()`
     * (не расплющенный список кнопок) — иначе тест не может проверить правило
     * «ноль одиночек в ряду», которое живёт именно в форме рядов.
     *
     * @return list<list<array{text:string,callback_data:string}>>
     */
    private function keyboardRows(ServerResponse $response): array
    {
        $result  = $response->getResult();
        $raw     = is_object($result) ? ($result->reply_markup ?? null) : null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded) || ! isset($decoded['inline_keyboard']) || ! is_array($decoded['inline_keyboard'])) {
            return [];
        }

        $rows = [];
        foreach ($decoded['inline_keyboard'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $packedRow = [];
            foreach ($row as $button) {
                if (is_array($button)) {
                    $packedRow[] = $button;
                }
            }
            $rows[] = $packedRow;
        }

        return $rows;
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
        $existing = $this->conn->table('biomes')->where('name', 'StandoffGateTestForest')->get()->getRowArray();
        if (is_array($existing)) {
            return (int) $existing['id'];
        }
        $this->conn->table('biomes')->insert([
            'name'            => 'StandoffGateTestForest',
            'description'     => 'test',
            'biome_type'      => 'plain',
            'danger_level'    => 1,
            'occurrence_rate' => 1.0,
        ]);

        return (int) $this->conn->insertID();
    }

    /**
     * Одна клетка `map`, где `cell_number` совпадает с `id` (та же гарантия, что
     * на проде — {@see \App\Services\Player\PlayerStateService::isCharacterOnBase()}
     * доккомментарий): и `characters.cell_number` (сравнивается с `map.cell_number`
     * в `AttackPlayerAction`), и `character_buildings.map_cell_id` (сравнивается
     * напрямую в `DefenseStructureService`) обязаны указывать на одно и то же.
     */
    private function createSelfConsistentCell(): int
    {
        // pvp-detection-clarity CI-fix: явный id вместо insertID()-после-вставки. На схеме без
        // AUTO_INCREMENT (её создаёт другой тест раньше в общем прогоне — DashboardAnalyticsServiceTest)
        // insertID() возвращает 0 при КАЖДОЙ вставке без явного id — вторая клетка валится дублем
        // PRIMARY '0' ещё до того, как успевает дойти до update(). random_int уникален на весь
        // прогон, инвариант id==cell_number соблюдён вставкой сразу, без промежуточного update.
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
     * Клетка вдали от {@see createSelfConsistentCell()} (dx/dy > 1 — за пределами
     * `isCellsCloseEnough()`), но всё ещё ниже `pvp.restriction.safe_zone_min_y`
     * (900 по умолчанию), чтобы тест ловил именно смежность, а не южную зону.
     */
    private function createDistantCell(int $x, int $y): int
    {
        // pvp-detection-clarity CI-fix: тот же приём, что и createSelfConsistentCell() выше —
        // явный id вместо insertID()-после-вставки, инвариант id==cell_number сразу в insert().
        $id = random_int(900_000_000, 999_999_999);
        $this->conn->table('map')->insert([
            'id'           => $id,
            'cell_number'  => $id,
            'coordinate_x' => $x,
            'coordinate_y' => $y,
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
            // Старше pvp.restriction.min_account_age_days (10д по умолчанию).
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
