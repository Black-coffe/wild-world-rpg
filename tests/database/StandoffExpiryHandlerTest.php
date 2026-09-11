<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\Adr186CreatePvpStandoffs;
use App\Database\Migrations\Adr186SeedStandoffSettings;
use App\Database\Migrations\CreateActionLogTable;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateGameSettingsTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use App\Models\PvpStandoffModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\PVE\PvpStandoffService;
use App\Services\PVE\StandoffNotifier;
use App\TaskHandlers\PVP\StandoffExpiryHandler;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * pvp-detection-clarity-10 — `StandoffExpiryHandler`: ленивое истечение окна
 * (ADR-186 §1/§4). Схема строится прогоном настоящих классов миграций, только
 * те таблицы, которых ещё нет (тот же приём, что `PvpStandoffServiceTest`).
 * `notifyAttackerExpired()` реально исполняет только путь ДО отправки —
 * `sendExpiredPing()` подменена анонимным потомком `StandoffNotifier`, как и
 * в `PvpStandoffServiceTest::testAlertDefenderKeyboardHasExactlyThreeNamedMoves()`;
 * факт живой доставки в Telegram проверяется Tier-3.
 *
 * @internal
 */
final class StandoffExpiryHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    private bool $createdTelegramUsers   = false;
    private bool $createdCharacters      = false;
    private bool $createdGameSettings    = false;
    private bool $createdActionLog       = false;
    private bool $createdStandoffs       = false;
    private bool $seededStandoffSettings = false;

    /** @var list<int> */
    private array $characterIds = [];
    /** @var list<int> */
    private array $telegramUserIds = [];
    /** @var array<int,int> character_id => telegram_id (chat_id), для проверки адресата пинга */
    private array $telegramIdByCharacter = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        $this->createdTelegramUsers = $this->createIfMissing('telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php');
        $this->createdCharacters    = $this->createIfMissing('characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php');
        $this->createdGameSettings  = $this->createIfMissing('game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php');
        $this->createdActionLog     = $this->createIfMissing('action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php');
        $this->createdStandoffs     = $this->createIfMissing('pvp_standoffs', Adr186CreatePvpStandoffs::class, '2026-09-11-210000_Adr186CreatePvpStandoffs.php');

        $existing = $this->conn->table('game_settings')->where('setting_key', 'pvp.standoff.enabled')->get()->getRowArray();
        if (empty($existing)) {
            $this->requireMigration('Adr186SeedStandoffSettings', '2026-09-11-210100_Adr186SeedStandoffSettings.php');
            (new Adr186SeedStandoffSettings())->up();
            $this->seededStandoffSettings = true;
        }

        $this->setBoolSetting('pvp.standoff.enabled', true);
        $this->setBoolSetting('pvp.standoff.notify_attacker_on_expiry', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->characterIds as $id) {
            $this->conn->table('pvp_standoffs')->where('attacker_id', $id)->orWhere('defender_id', $id)->delete();
            $this->conn->table('action_log')->where('character_id', $id)->delete();
            $this->conn->table('characters')->where('id', $id)->delete();
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
        if ($this->createdActionLog) {
            $this->requireMigration('CreateActionLogTable', '2024-03-18-134951_CreateActionLogTable.php');
            $forge = Database::forge('tests');
            (new CreateActionLogTable($forge instanceof Forge ? $forge : null))->down();
        }
        if ($this->createdGameSettings) {
            $this->requireMigration('CreateGameSettingsTable', '2026-05-19-100000_CreateGameSettingsTable.php');
            $forge = Database::forge('tests');
            (new CreateGameSettingsTable($forge instanceof Forge ? $forge : null))->down();
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

    // ---------------------------------------------------------------- handle()

    public function testExpiredWindowIsClosedOnceAndPingedOnce(): void
    {
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $cell     = 900_000_001;
        $id       = $this->insertStandoffRow($attacker, $defender, $cell, 'open', -60);

        $notifier = $this->capturingNotifier();
        $handler  = $this->makeHandler($notifier);

        $handler->handle();

        $row = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('expired', $row['status'], 'просроченное open обязано стать expired');
        $this->assertSame(1, (int) $row['notified_expired'], 'notified_expired должен встать в 1 после пинга');
        $this->assertCount(1, $notifier->calls, 'ровно один пинг на окно');
        $this->assertSame($this->telegramIdByCharacter[$attacker], $notifier->calls[0], 'пинг обязан уйти именно атакующему (его chat_id)');

        // Повторный тик крона (двойной проход) не шлёт второй пинг и не трогает статус повторно.
        $handler->handle();
        $this->assertCount(1, $notifier->calls, 'повторный прогон не шлёт второй пинг');

        $rowAfter = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($rowAfter);
        $this->assertSame('expired', $rowAfter['status']);
    }

    public function testLiveWindowIsUntouched(): void
    {
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $cell     = 900_000_002;
        $id       = $this->insertStandoffRow($attacker, $defender, $cell, 'open', 300);

        $notifier = $this->capturingNotifier();
        $handler  = $this->makeHandler($notifier);

        $handler->handle();

        $row = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('open', $row['status'], 'живое окно (expires_at в будущем) не трогается');
        $this->assertSame(0, (int) $row['notified_expired']);
        $this->assertSame([], $notifier->calls, 'по живому окну пинг не уходит');
    }

    public function testKillswitchOffSkipsEverything(): void
    {
        $this->setBoolSetting('pvp.standoff.enabled', false);

        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $cell     = 900_000_003;
        $id       = $this->insertStandoffRow($attacker, $defender, $cell, 'open', -60);

        $notifier = $this->capturingNotifier();
        $handler  = $this->makeHandler($notifier);

        $handler->handle();

        $row = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('open', $row['status'], 'при выключенном килсвитче handler не трогает ни одной строки');
        $this->assertSame(0, (int) $row['notified_expired']);
        $this->assertSame([], $notifier->calls, 'при выключенном килсвитче пинг не уходит');
    }

    public function testNotifyAttackerOnExpiryOffTransitionsStatusButSendsNoPing(): void
    {
        $this->setBoolSetting('pvp.standoff.notify_attacker_on_expiry', false);

        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $cell     = 900_000_004;
        $id       = $this->insertStandoffRow($attacker, $defender, $cell, 'open', -60);

        $notifier = $this->capturingNotifier();
        $handler  = $this->makeHandler($notifier);

        $handler->handle();

        $row = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('expired', $row['status'], 'статус переводится в expired независимо от флага пинга');
        $this->assertSame([], $notifier->calls, 'выключенный notify_attacker_on_expiry — пинг не уходит');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return object{calls: list<int>}&StandoffNotifier
     */
    private function capturingNotifier(): object
    {
        return new class () extends StandoffNotifier {
            /** @var list<int> */
            public array $calls = [];

            protected function sendExpiredPing(int $chatId, string $text): bool
            {
                $this->calls[] = $chatId;
                return true;
            }
        };
    }

    private function makeHandler(StandoffNotifier $notifier): StandoffExpiryHandler
    {
        return new StandoffExpiryHandler(
            new PvpStandoffModel(),
            new PvpStandoffService(),
            $notifier,
            new GameSettingsService()
        );
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

    private function insertCharacter(): int
    {
        $telegramId = random_int(100_000_000, 999_999_999);
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => $telegramId,
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
        $id                                  = (int) $this->conn->insertID();
        $this->characterIds[]                = $id;
        $this->telegramIdByCharacter[$id]    = $telegramId;
        return $id;
    }

    private function insertStandoffRow(int $attackerId, int $defenderId, int $cellNumber, string $status, int $expiresInSeconds): int
    {
        // Время окна сеется от часов БД (NOW() + INTERVAL), а не от PHP date() —
        // handler читает expires_at <= NOW() тем же соединением.
        $this->conn->query(
            'INSERT INTO pvp_standoffs (attacker_id, defender_id, cell_number, started_at, expires_at, status, notified_expired, created_at, updated_at) '
            . 'VALUES (?, ?, ?, NOW(), NOW() + INTERVAL ? SECOND, ?, 0, NOW(), NOW())',
            [$attackerId, $defenderId, $cellNumber, $expiresInSeconds, $status]
        );
        return (int) $this->conn->insertID();
    }

    private function setBoolSetting(string $key, bool $value): void
    {
        $this->conn->table('game_settings')->where('setting_key', $key)->update(['value_bool' => $value ? 1 : 0]);
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
