<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Adr186SeedPvpRestrictionSettings;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateGameSettingsTable;
use App\Database\Migrations\CreateMapTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Services\Player\PvPRestrictionService;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * pvp-detection-clarity-02 — три гейта `PvPRestrictionService` (уровень / южная граница
 * безопасной зоны / возраст аккаунта) переехали в `GameSettings` (`pvp.restriction.*`,
 * категория `combat`), отказ несёт машиночитаемый `reason_code`.
 *
 * Схема (`telegram_users` → `characters` → `map` → `game_settings`) строится прогоном
 * настоящих классов миграций (`.claude/rules/tests-db.md`), только если таблицы ещё нет —
 * CI гоняет набор на пустой базе, локальный стенд их обычно уже несёт персистентно.
 * `characters`/`map` — персистентные на стенде из миграций: свои строки чистим по id,
 * DROP не трогает то, чего тест не создавал (как `TeleportChargeTest`).
 *
 * @internal
 */
final class PvPRestrictionServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    private bool $createdTelegramUsers = false;
    private bool $createdCharacters    = false;
    private bool $createdMap           = false;
    private bool $createdGameSettings  = false;
    private bool $seededPvpSettings    = false;

    /** @var list<int> */
    private array $characterIds = [];
    /** @var list<int> */
    private array $mapIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        if (! $this->conn->tableExists('telegram_users')) {
            $this->requireMigration('CreateTelegramUsersTable', '2024-03-20-153728_CreateTelegramUsersTable.php');
            $forge = Database::forge('tests');
            (new CreateTelegramUsersTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdTelegramUsers = true;
        }

        if (! $this->conn->tableExists('characters')) {
            $this->requireMigration('CreateCharactersTable', '2024-03-20-154155_CreateCharactersTable.php');
            $forge = Database::forge('tests');
            (new CreateCharactersTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdCharacters = true;
        }

        if (! $this->conn->tableExists('map')) {
            $this->requireMigration('CreateMapTable', '2024-03-18-105708_CreateMapTable.php');
            $forge = Database::forge('tests');
            (new CreateMapTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdMap = true;
        }

        if (! $this->conn->tableExists('game_settings')) {
            $this->requireMigration('CreateGameSettingsTable', '2026-05-19-100000_CreateGameSettingsTable.php');
            $forge = Database::forge('tests');
            (new CreateGameSettingsTable($forge instanceof Forge ? $forge : null))->up();
            $this->conn->resetDataCache();
            $this->createdGameSettings = true;
        }

        $existing = $this->conn->table('game_settings')->where('setting_key', 'pvp.restriction.min_level')->get()->getRowArray();
        if (empty($existing)) {
            $this->requireMigration('Adr186SeedPvpRestrictionSettings', '2026-09-11-220000_Adr186SeedPvpRestrictionSettings.php');
            (new Adr186SeedPvpRestrictionSettings())->up();
            $this->seededPvpSettings = true;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->characterIds as $id) {
            $this->conn->table('characters')->where('id', $id)->delete();
        }
        foreach ($this->mapIds as $id) {
            $this->conn->table('map')->where('id', $id)->delete();
        }

        if ($this->seededPvpSettings && ! $this->createdGameSettings) {
            $this->requireMigration('Adr186SeedPvpRestrictionSettings', '2026-09-11-220000_Adr186SeedPvpRestrictionSettings.php');
            (new Adr186SeedPvpRestrictionSettings())->down();
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

        parent::tearDown();
    }

    private function requireMigration(string $shortClass, string $file): void
    {
        $class = 'App\\Database\\Migrations\\' . $shortClass;
        if (! class_exists($class, false)) {
            require_once APPPATH . 'Database/Migrations/' . $file;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function insertCharacter(int $level, string $cellNumber, string $createdAt): array
    {
        $row = [
            'level'       => $level,
            'cell_number' => $cellNumber,
            'created_at'  => $createdAt,
            'updated_at'  => $createdAt,
        ];
        $this->conn->table('characters')->insert($row);
        $id = (int) $this->conn->insertID();
        $this->characterIds[] = $id;
        $row['id'] = $id;

        return $row;
    }

    private function insertMapCell(int $cellNumber, int $coordinateY): void
    {
        // pvp-detection-clarity CI-fix: id ЯВНО = cell_number (инвариант map.id == map.cell_number,
        // см. TowerAlertService::towersInBox()). `insertID()` на схеме без AUTO_INCREMENT (её
        // создаёт другой тест раньше в общем прогоне — DashboardAnalyticsServiceTest) возвращает 0
        // при каждой вставке без явного id, что валит вторую вставку дублем PRIMARY '0'.
        // `$cellNumber` здесь всегда `uniqueCell()` (900_000_000..999_999_999) — безопасен как id.
        $this->conn->table('map')->insert([
            'id'           => $cellNumber,
            'cell_number'  => $cellNumber,
            'coordinate_x' => 0,
            'coordinate_y' => $coordinateY,
        ]);
        $this->mapIds[] = $cellNumber;
    }

    private function uniqueCell(): int
    {
        return random_int(900_000_000, 999_999_999);
    }

    public function testAllowsWhenAllThreeGatesPass(): void
    {
        $cellA = $this->uniqueCell();
        $cellD = $this->uniqueCell();
        $this->insertMapCell($cellA, 100);
        $this->insertMapCell($cellD, 100);

        $attacker = $this->insertCharacter(10, (string) $cellA, date('Y-m-d H:i:s', strtotime('-30 days')));
        $defender = $this->insertCharacter(10, (string) $cellD, date('Y-m-d H:i:s', strtotime('-30 days')));

        $result = (new PvPRestrictionService())->checkPvPAllowed($attacker, $defender);

        $this->assertTrue($result['allowed']);
        $this->assertSame('', $result['reason_code']);
        $this->assertSame('Ok', $result['message']);
    }

    public function testDeniesByLevelGateReadFromGameSettings(): void
    {
        $cellA = $this->uniqueCell();
        $cellD = $this->uniqueCell();
        $this->insertMapCell($cellA, 100);
        $this->insertMapCell($cellD, 100);

        $attacker = $this->insertCharacter(10, (string) $cellA, date('Y-m-d H:i:s', strtotime('-30 days')));
        $defender = $this->insertCharacter(1, (string) $cellD, date('Y-m-d H:i:s', strtotime('-30 days')));

        $result = (new PvPRestrictionService())->checkPvPAllowed($attacker, $defender);

        $this->assertFalse($result['allowed']);
        $this->assertSame('level', $result['reason_code']);
        $this->assertStringContainsString('5', $result['message']);
    }

    public function testDeniesBySafeZoneGateReadFromGameSettings(): void
    {
        $cellA = $this->uniqueCell();
        $cellD = $this->uniqueCell();
        $this->insertMapCell($cellA, 900);
        $this->insertMapCell($cellD, 100);

        $attacker = $this->insertCharacter(10, (string) $cellA, date('Y-m-d H:i:s', strtotime('-30 days')));
        $defender = $this->insertCharacter(10, (string) $cellD, date('Y-m-d H:i:s', strtotime('-30 days')));

        $result = (new PvPRestrictionService())->checkPvPAllowed($attacker, $defender);

        $this->assertFalse($result['allowed']);
        $this->assertSame('safe_zone', $result['reason_code']);
    }

    public function testDeniesByAccountAgeGateReadFromGameSettings(): void
    {
        $cellA = $this->uniqueCell();
        $cellD = $this->uniqueCell();
        $this->insertMapCell($cellA, 100);
        $this->insertMapCell($cellD, 100);

        $attacker = $this->insertCharacter(10, (string) $cellA, date('Y-m-d H:i:s'));
        $defender = $this->insertCharacter(10, (string) $cellD, date('Y-m-d H:i:s', strtotime('-30 days')));

        $result = (new PvPRestrictionService())->checkPvPAllowed($attacker, $defender);

        $this->assertFalse($result['allowed']);
        $this->assertSame('account_age', $result['reason_code']);
    }

    /**
     * Доказательство, что порог реально приезжает из `GameSettings`, а не остался в коде:
     * персонаж уровня 10 разрешён при дефолте 5, но запрещён, когда ручку временно
     * подкрутили до 50 — и снова разрешён после возврата дефолта.
     */
    public function testLevelThresholdIsActuallyReadFromGameSettingsNotHardcoded(): void
    {
        $cellA = $this->uniqueCell();
        $cellD = $this->uniqueCell();
        $this->insertMapCell($cellA, 100);
        $this->insertMapCell($cellD, 100);

        $attacker = $this->insertCharacter(10, (string) $cellA, date('Y-m-d H:i:s', strtotime('-30 days')));
        $defender = $this->insertCharacter(10, (string) $cellD, date('Y-m-d H:i:s', strtotime('-30 days')));

        $service = new PvPRestrictionService();

        $baseline = $service->checkPvPAllowed($attacker, $defender);
        $this->assertTrue($baseline['allowed'], 'при дефолте 5 уровень 10 должен проходить гейт');

        $original = $this->conn->table('game_settings')->where('setting_key', 'pvp.restriction.min_level')->get()->getRowArray();
        $this->assertNotNull($original, 'ключ pvp.restriction.min_level обязан существовать после миграции');

        $this->conn->table('game_settings')->where('setting_key', 'pvp.restriction.min_level')->update(['value_int' => 50]);
        // GameSettingsService кеширует на 60с — новый инстанс сервиса не обходит кеш-ключ по имени,
        // поэтому чистим кеш явно перед повторным чтением.
        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'delete')) {
            $cache->delete('game_settings_pvp_restriction_min_level');
        }

        $raised = $service->checkPvPAllowed($attacker, $defender);
        $this->assertFalse($raised['allowed'], 'после подъёма ручки до 50 уровень 10 обязан упереться в level-гейт');
        $this->assertSame('level', $raised['reason_code']);

        // Возврат к прежнему значению — тест не должен оставлять ручку сдвинутой.
        $this->conn->table('game_settings')->where('setting_key', 'pvp.restriction.min_level')->update(['value_int' => (int) $original['value_int']]);
        if (is_object($cache) && method_exists($cache, 'delete')) {
            $cache->delete('game_settings_pvp_restriction_min_level');
        }

        $restored = $service->checkPvPAllowed($attacker, $defender);
        $this->assertTrue($restored['allowed'], 'после возврата дефолта уровень 10 снова обязан проходить гейт');
    }

    /**
     * Находка ревью (главная сессия, 11.09): непримененная миграция не должна молча снимать
     * правила боя. Удаляем все три строки `pvp.restriction.*` (как если бы миграция ещё не
     * прошла) и проверяем, что гейты продолжают работать по safety-net дефолтам
     * `GameSettingsService::get($key, 5|900|10)` — байт-в-байт как прежний хардкод, — а не
     * тихо отключаются (`(int) null = 0`, который снял бы защиту новичков вовсе).
     */
    public function testGatesFallBackToPriorHardcodeWhenSettingsRowsAreMissing(): void
    {
        $keys = [
            'pvp.restriction.min_level',
            'pvp.restriction.safe_zone_min_y',
            'pvp.restriction.min_account_age_days',
        ];

        $this->conn->table('game_settings')->whereIn('setting_key', $keys)->delete();
        $cache = service('cache');
        foreach ($keys as $key) {
            $cacheKey = 'game_settings_' . str_replace('.', '_', $key);
            if (is_object($cache) && method_exists($cache, 'delete')) {
                $cache->delete($cacheKey);
            }
        }

        try {
            $service = new PvPRestrictionService();

            // Level-safety-net = 5: уровень 4 всё ещё обязан упираться в гейт, а не проходить
            // как при min_level=0.
            $cellA = $this->uniqueCell();
            $cellD = $this->uniqueCell();
            $this->insertMapCell($cellA, 100);
            $this->insertMapCell($cellD, 100);
            $lowLevelAttacker = $this->insertCharacter(4, (string) $cellA, date('Y-m-d H:i:s', strtotime('-30 days')));
            $okDefender       = $this->insertCharacter(10, (string) $cellD, date('Y-m-d H:i:s', strtotime('-30 days')));
            $levelResult      = $service->checkPvPAllowed($lowLevelAttacker, $okDefender);
            $this->assertFalse($levelResult['allowed'], 'без строки в game_settings level-гейт обязан остаться включённым (safety net=5), а не выключиться');
            $this->assertSame('level', $levelResult['reason_code']);

            // Safe-zone-safety-net = 900: клетка на границе всё ещё обязана быть безопасной зоной.
            $cellSafeA = $this->uniqueCell();
            $cellSafeD = $this->uniqueCell();
            $this->insertMapCell($cellSafeA, 900);
            $this->insertMapCell($cellSafeD, 100);
            $inSafeZone   = $this->insertCharacter(10, (string) $cellSafeA, date('Y-m-d H:i:s', strtotime('-30 days')));
            $okDefender2  = $this->insertCharacter(10, (string) $cellSafeD, date('Y-m-d H:i:s', strtotime('-30 days')));
            $zoneResult   = $service->checkPvPAllowed($inSafeZone, $okDefender2);
            $this->assertFalse($zoneResult['allowed'], 'без строки в game_settings safe_zone-гейт обязан остаться включённым (safety net=900), а не «coordinate_y >= 0» всегда истинным по-другому смыслу — но и не выключиться вовсе');
            $this->assertSame('safe_zone', $zoneResult['reason_code']);

            // Account-age-safety-net = 10: свежий аккаунт всё ещё обязан быть под цензом.
            $cellFreshA = $this->uniqueCell();
            $cellFreshD = $this->uniqueCell();
            $this->insertMapCell($cellFreshA, 100);
            $this->insertMapCell($cellFreshD, 100);
            $freshAttacker = $this->insertCharacter(10, (string) $cellFreshA, date('Y-m-d H:i:s'));
            $okDefender3   = $this->insertCharacter(10, (string) $cellFreshD, date('Y-m-d H:i:s', strtotime('-30 days')));
            $ageResult     = $service->checkPvPAllowed($freshAttacker, $okDefender3);
            $this->assertFalse($ageResult['allowed'], 'без строки в game_settings account_age-гейт обязан остаться включённым (safety net=10), а не выключиться');
            $this->assertSame('account_age', $ageResult['reason_code']);
        } finally {
            // Восстанавливаем строки, чтобы обычный tearDown (который решает дропать таблицу
            // по флагу из setUp) отработал предсказуемо для остальных тестов набора.
            $this->requireMigration('Adr186SeedPvpRestrictionSettings', '2026-09-11-220000_Adr186SeedPvpRestrictionSettings.php');
            (new Adr186SeedPvpRestrictionSettings())->up();
        }
    }

    public function testBackwardCompatibleReasonFieldStillPresentForExistingCaller(): void
    {
        $cellA = $this->uniqueCell();
        $cellD = $this->uniqueCell();
        $this->insertMapCell($cellA, 100);
        $this->insertMapCell($cellD, 100);

        $attacker = $this->insertCharacter(10, (string) $cellA, date('Y-m-d H:i:s', strtotime('-30 days')));
        $defender = $this->insertCharacter(1, (string) $cellD, date('Y-m-d H:i:s', strtotime('-30 days')));

        $result = (new PvPRestrictionService())->checkPvPAllowed($attacker, $defender);

        // AttackPlayerAction:157 всё ещё читает `reason` — эта story добавляет поля, не убирает.
        $this->assertArrayHasKey('reason', $result);
        $this->assertSame($result['reason'], $result['message']);
    }
}
