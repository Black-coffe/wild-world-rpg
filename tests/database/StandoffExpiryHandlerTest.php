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

    /**
     * pvp-detection-clarity-27 — twin-урок `feedback_transport_double_hides_dead_send_path`:
     * прогон целиком через `handle()`, а не прямой вызов `notifyAttackerExpired()`, иначе
     * несожжённый флаг остался бы незамеченным.
     */
    public function testFailedPingKeepsFlagUnsetAndRetriesNextTick(): void
    {
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $cell     = 900_000_005;
        $id       = $this->insertStandoffRow($attacker, $defender, $cell, 'open', -60);

        $notifier = $this->failingNotifier();
        $handler  = $this->makeHandler($notifier);

        $handler->handle();

        $row = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('expired', $row['status'], 'окно закрывается независимо от исхода отправки');
        $this->assertSame(0, (int) $row['notified_expired'], 'провал отправки не должен сжигать одноразовый флаг');
        $this->assertCount(1, $notifier->calls, 'первая попытка отправки состоялась');

        // Следующий тик крона обязан попробовать снова — строка уже `expired`, но
        // `notified_expired=0` держит её доступной именно для повторной попытки.
        $handler->handle();

        $rowAfter = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($rowAfter);
        $this->assertSame('expired', $rowAfter['status']);
        $this->assertSame(0, (int) $rowAfter['notified_expired'], 'вторая попытка тоже провалилась — флаг всё ещё 0');
        $this->assertCount(2, $notifier->calls, 'следующий тик пробует отправить снова, а не пропускает строку');
    }

    /**
     * pvp-detection-clarity-27 — RETRY_HORIZON_SEC (600 сек, см. комментарий у константы).
     * Без него флип `notify_attacker_on_expiry` off→on или стабильно падающая отправка
     * (заблокировавший бота игрок) собирали бы недельной давности строки и рассылали бы
     * их живым игрокам на первом же тике после включения.
     */
    public function testStaleExpiredRowOutsideRetryHorizonIsNotPickedUp(): void
    {
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $cell     = 900_000_006;
        // Уже закрыта (`expired`), пинг ни разу не ушёл (`notified_expired=0`), но
        // истекла за пределами 600-секундного горизонта ретрая.
        $id = $this->insertStandoffRow($attacker, $defender, $cell, 'expired', -700);

        $notifier = $this->failingNotifier();
        $handler  = $this->makeHandler($notifier);

        $handler->handle();

        $this->assertSame([], $notifier->calls, 'строка старше горизонта ретрая не должна порождать попытку отправки');

        $row = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('expired', $row['status'], 'статус не трогается — строка уже была закрыта');
        $this->assertSame(0, (int) $row['notified_expired'], 'флаг остаётся 0 — пинг про неё больше не пробуем слать');
    }

    /**
     * pvp-detection-clarity-27 (CI-находка, run 34625203061) — на CI нет валидного
     * Telegram-ключа: `telegram()` бросает `TelegramException` и на первой попытке, и в
     * собственной аварийной ветке. `handle()` обязан пережить это — закрыть просроченные
     * окна (уборка не зависит от Telegram) и молча пропустить рассылку пингов, оставив
     * `notified_expired=0` для ретрая следующим тиком (в пределах RETRY_HORIZON_SEC).
     */
    public function testBrokenTelegramBridgeDoesNotCrashHandleAndStillClosesExpiredWindow(): void
    {
        $defender = $this->insertCharacter();
        $attacker = $this->insertCharacter();
        $cell     = 900_000_007;
        $id       = $this->insertStandoffRow($attacker, $defender, $cell, 'open', -60);

        $notifier = $this->capturingNotifier();
        $handler  = $this->makeHandlerWithBrokenTelegram($notifier);

        // Не должно бросать наружу — упавшая инициализация моста гасит только пинги.
        $handler->handle();

        $row = $this->conn->table('pvp_standoffs')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('expired', $row['status'], 'уборка (перевод в expired) не зависит от Telegram');
        $this->assertSame(0, (int) $row['notified_expired'], 'пинг не отправлялся — флаг не сожжён');
        $this->assertSame([], $notifier->calls, 'notifier не должен звонить, если мост не поднялся');
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

    /**
     * @return object{calls: list<int>}&StandoffNotifier
     */
    private function failingNotifier(): object
    {
        return new class () extends StandoffNotifier {
            /** @var list<int> */
            public array $calls = [];

            protected function sendExpiredPing(int $chatId, string $text): bool
            {
                $this->calls[] = $chatId;
                return false;
            }
        };
    }

    /**
     * pvp-detection-clarity-27 (CI-находка) — CI не несёт валидного Telegram API-ключа,
     * `BaseTaskHandler::telegram()` бросает и на первой попытке, и в собственной
     * аварийной ветке (`new Telegram('invalid','invalid')` бросает то же исключение).
     * Локальный `.env` с ключом валидного формата этого не воспроизводит — поэтому
     * тест не полагается на окружение, а гарантированно ломает init подменой метода.
     */
    private function makeHandlerWithBrokenTelegram(StandoffNotifier $notifier): StandoffExpiryHandler
    {
        return new class (
            new PvpStandoffModel(),
            new PvpStandoffService(),
            $notifier,
            new GameSettingsService()
        ) extends StandoffExpiryHandler {
            protected function telegram(): \Longman\TelegramBot\Telegram
            {
                throw new \RuntimeException('simulated: Telegram-мост недоступен (нет ключа, как на CI)');
            }
        };
    }

    /**
     * pvp-detection-clarity-27 (CI-находка) — `telegram()` теперь реально вызывается
     * из `handle()` перед рассылкой пингов. Локальный `.env` несёт ключ валидного
     * формата и молча это скрывает, а CI (без ключа вовсе) — нет. Тесты, проверяющие
     * логику пинга/ретрая, не должны зависеть ни от того, ни от другого окружения:
     * override возвращает `Telegram`, собранный из заведомо валидного ПО ФОРМАТУ (не
     * по реальности) ключа — конструктор `Longman\TelegramBot\Telegram` только
     * проверяет `preg_match('/(\d+):[\w\-]+/')`, сетевых вызовов не делает — а
     * реальную доставку по-прежнему исполняет подменённый `sendExpiredPing()`.
     */
    private function stubTelegram(): \Longman\TelegramBot\Telegram
    {
        return new \Longman\TelegramBot\Telegram('123456:test-stub-format-only', 'test_stub_bot');
    }

    private function makeHandler(StandoffNotifier $notifier): StandoffExpiryHandler
    {
        $stub = $this->stubTelegram();
        return new class (
            new PvpStandoffModel(),
            new PvpStandoffService(),
            $notifier,
            new GameSettingsService(),
            $stub
        ) extends StandoffExpiryHandler {
            public function __construct(
                PvpStandoffModel $model,
                PvpStandoffService $service,
                StandoffNotifier $notifier,
                GameSettingsService $settings,
                private readonly \Longman\TelegramBot\Telegram $stub
            ) {
                parent::__construct($model, $service, $notifier, $settings);
            }

            protected function telegram(): \Longman\TelegramBot\Telegram
            {
                return $this->stub;
            }
        };
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
