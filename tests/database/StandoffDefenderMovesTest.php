<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\PVP\RunAwayAction;
use App\Controllers\Telegram\Commands\Actions\PVP\StandoffHoldAction;
use App\Database\Migrations\Adr186CreatePvpStandoffs;
use App\Database\Migrations\Adr186SeedStandoffSettings;
use App\Database\Migrations\CreateActionLogTable;
use App\Database\Migrations\CreateCharactersTable;
use App\Database\Migrations\CreateGameSettingsTable;
use App\Database\Migrations\CreateMapTable;
use App\Database\Migrations\CreateTelegramUsersTable;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * pvp-detection-clarity-09 — «🛡 Укрыться» (`StandoffHoldAction`, `held`) и побег
 * под живой тревогой (`RunAwayAction`, теперь ещё и `fled`). Третий ход
 * защитника («⚔️ Ударить первым») тестирует `-08` (`StandoffAttackGateTest`) — он
 * живёт в `AttackPlayerAction`, не в файлах этой story.
 *
 * Схема строится прогоном настоящих классов миграций (тот же приём, что
 * `StandoffAttackGateTest`/`PvpStandoffServiceTest`); создаём только отсутствующие
 * таблицы — CI гоняет набор на пустой базе, локальный стенд их обычно уже несёт.
 *
 * Кнопки/поток НЕ проверяются здесь — `## Implementation notes` истории отдельно
 * фиксирует: реальный клик, доставка сообщений двум разным чатам и рендер —
 * только Tier-3 (MCP Chrome + Telegram Web, два аккаунта).
 *
 * @internal
 */
final class StandoffDefenderMovesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private \CodeIgniter\Database\BaseConnection $conn;

    /** @var array<string,bool> */
    private array $created           = [];
    private bool $seededStandoffSettings = false;

    /** @var list<int> */
    private array $characterIds = [];
    /** @var list<int> */
    private array $telegramUserIds = [];
    /** @var list<int> */
    private array $mapIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();

        $this->created['telegram_users'] = $this->createIfMissing('telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php');
        $this->created['characters']     = $this->createIfMissing('characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php');
        $this->created['map']            = $this->createIfMissing('map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php');
        $this->created['game_settings']  = $this->createIfMissing('game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php');
        $this->created['action_log']     = $this->createIfMissing('action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php');
        $this->created['pvp_standoffs']  = $this->createIfMissing('pvp_standoffs', Adr186CreatePvpStandoffs::class, '2026-09-11-210000_Adr186CreatePvpStandoffs.php');

        $existing = $this->conn->table('game_settings')->where('setting_key', 'pvp.standoff.enabled')->get()->getRowArray();
        if (empty($existing)) {
            $this->requireMigration('Adr186SeedStandoffSettings', '2026-09-11-210100_Adr186SeedStandoffSettings.php');
            (new Adr186SeedStandoffSettings())->up();
            $this->seededStandoffSettings = true;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->characterIds as $id) {
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

        $downOrder = [
            ['pvp_standoffs', Adr186CreatePvpStandoffs::class, '2026-09-11-210000_Adr186CreatePvpStandoffs.php'],
            ['action_log', CreateActionLogTable::class, '2024-03-18-134951_CreateActionLogTable.php'],
            ['game_settings', CreateGameSettingsTable::class, '2026-05-19-100000_CreateGameSettingsTable.php'],
            ['map', CreateMapTable::class, '2024-03-18-105708_CreateMapTable.php'],
            ['characters', CreateCharactersTable::class, '2024-03-20-154155_CreateCharactersTable.php'],
            ['telegram_users', CreateTelegramUsersTable::class, '2024-03-20-153728_CreateTelegramUsersTable.php'],
        ];

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

    // ---------------------------------------------------------------- StandoffHoldAction («🛡 Укрыться»)

    public function testHoldClosesWindowAsHeldAndAudits(): void
    {
        $cell     = $this->createCell(0, 100);
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $standoffId = $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'open', 300);

        $response = (new StandoffHoldAction($this->callbackQuery($defender['tgId'], "standoffHold_{$standoffId}")))->handle();
        $text     = $this->responseText($response);

        $this->assertStringNotContainsString('⚠️ Ошибка', $text);
        $this->assertStringContainsString('готов', mb_strtolower($text), 'защитник получает подтверждение своего хода');

        $row = $this->conn->table('pvp_standoffs')->where('id', $standoffId)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('held', $row['status']);

        $audit = $this->conn->table('action_log')
            ->where('character_id', $defender['id'])
            ->where('action_name', 'pvp_standoff_held')
            ->countAllResults();
        $this->assertSame(1, $audit, 'закрытие окна как held обязано писать аудит-код pvp_standoff_held');
    }

    public function testHoldRefusesSecondTapWithoutSecondTransition(): void
    {
        $cell     = $this->createCell(0, 200);
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $standoffId = $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'open', 300);

        $first  = (new StandoffHoldAction($this->callbackQuery($defender['tgId'], "standoffHold_{$standoffId}")))->handle();
        $second = (new StandoffHoldAction($this->callbackQuery($defender['tgId'], "standoffHold_{$standoffId}")))->handle();

        $this->assertStringNotContainsString('уже отреагировал', $this->responseText($first));
        $this->assertStringContainsString('уже отреагировал', $this->responseText($second));

        $audit = $this->conn->table('action_log')
            ->where('character_id', $defender['id'])
            ->where('action_name', 'pvp_standoff_held')
            ->countAllResults();
        $this->assertSame(1, $audit, 'второй тап не должен применить второй переход — ровно одна строка аудита');
    }

    public function testHoldRejectsTapFromSomeoneElse(): void
    {
        $cell     = $this->createCell(0, 300);
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $stranger = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $standoffId = $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'open', 300);

        $response = (new StandoffHoldAction($this->callbackQuery($stranger['tgId'], "standoffHold_{$standoffId}")))->handle();

        $this->assertStringContainsString('не твоя', $this->responseText($response));
        $this->assertStringNotContainsString('⚠️ Ошибка', $this->responseText($response));

        $row = $this->conn->table('pvp_standoffs')->where('id', $standoffId)->get()->getRowArray();
        $this->assertSame('open', $row['status'], 'чужой тап не должен трогать статус окна');
    }

    public function testHoldOnExpiredOrClosedWindowExplainsInsteadOfThrowing(): void
    {
        $cell     = $this->createCell(0, 400);
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $standoffId = $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'expired', -60);

        $response = (new StandoffHoldAction($this->callbackQuery($defender['tgId'], "standoffHold_{$standoffId}")))->handle();

        $this->assertStringContainsString('уже отреагировал', $this->responseText($response));
    }

    // ---------------------------------------------------------------- RunAwayAction (побег + fled)

    public function testRunAwayUnderActiveWindowClosesItAsFledAndAudits(): void
    {
        $cell     = $this->createLowMobilityOrigin(0, 500);
        $this->createFleeDestinations(0, 500, 10);
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], lowMobility: true);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $standoffId = $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'open', 300);

        $response = (new RunAwayAction($this->callbackQuery($defender['tgId'], 'runAway')))->handle();
        $text     = $this->responseText($response);

        $this->assertStringContainsString('Вы решили бежать', $text, 'сама механика побега не меняется');

        $row = $this->conn->table('pvp_standoffs')->where('id', $standoffId)->get()->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('fled', $row['status']);

        $audit = $this->conn->table('action_log')
            ->where('character_id', $defender['id'])
            ->where('action_name', 'pvp_standoff_fled')
            ->countAllResults();
        $this->assertSame(1, $audit, 'побег под живой тревогой обязан писать аудит-код pvp_standoff_fled');
    }

    public function testRunAwayWithoutActiveWindowIsUnchangedRegression(): void
    {
        $cell     = $this->createLowMobilityOrigin(0, 600);
        $this->createFleeDestinations(0, 600, 10);
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], lowMobility: true);

        $before   = $this->conn->table('pvp_standoffs')->countAllResults();
        $response = (new RunAwayAction($this->callbackQuery($defender['tgId'], 'runAway')))->handle();
        $after    = $this->conn->table('pvp_standoffs')->countAllResults();

        $this->assertStringContainsString('Вы решили бежать', $this->responseText($response));
        $this->assertSame($before, $after, 'побег вне окна не создаёт и не трогает строки pvp_standoffs');
    }

    public function testCrossButtonDoubleReactionAppliesExactlyOneTransition(): void
    {
        $cell     = $this->createLowMobilityOrigin(0, 700);
        $this->createFleeDestinations(0, 700, 10);
        $defender = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)], lowMobility: true);
        $attacker = $this->insertCharacter($cell, ['telegram_id' => random_int(100_000_000, 999_999_999)]);
        $standoffId = $this->insertStandoffRow($attacker['id'], $defender['id'], $cell, 'open', 300);

        // Первый ход — «🛡 Укрыться»: закрывает окно как held.
        (new StandoffHoldAction($this->callbackQuery($defender['tgId'], "standoffHold_{$standoffId}")))->handle();
        $afterHold = $this->conn->table('pvp_standoffs')->where('id', $standoffId)->get()->getRowArray();
        $this->assertSame('held', $afterHold['status']);

        // Второй ход подряд — «🏃 Убежать»: RunAwayAction не находит АКТИВНОГО окна
        // (activeAgainst() требует status='open'), поэтому НЕ трогает уже закрытую
        // строку и не пишет второй pvp_standoff_fled — ровно один применённый переход.
        $fleeResponse = (new RunAwayAction($this->callbackQuery($defender['tgId'], 'runAway')))->handle();
        $this->assertStringContainsString('Вы решили бежать', $this->responseText($fleeResponse), 'сам побег как механика не блокируется — это Non-goal этой истории');

        $afterFlee = $this->conn->table('pvp_standoffs')->where('id', $standoffId)->get()->getRowArray();
        $this->assertSame('held', $afterFlee['status'], 'закрытое окно не переоткрывается и не перезакрывается вторым ходом');

        $fledAudit = $this->conn->table('action_log')
            ->where('character_id', $defender['id'])
            ->where('action_name', 'pvp_standoff_fled')
            ->countAllResults();
        $this->assertSame(0, $fledAudit, 'вторая (уже закрытая) строка не должна получить fled-переход');
    }

    // ---------------------------------------------------------------- helpers

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

    /** Одна клетка `map` с заданными координатами, `cell_number` = `id`. */
    private function createCell(int $x, int $y): int
    {
        $this->conn->table('map')->insert([
            'cell_number'  => 0,
            'coordinate_x' => $x,
            'coordinate_y' => $y,
        ]);
        $id = (int) $this->conn->insertID();
        $this->conn->table('map')->where('id', $id)->update(['cell_number' => $id]);
        $this->mapIds[] = $id;

        return $id;
    }

    private function createLowMobilityOrigin(int $x, int $y): int
    {
        return $this->createCell($x, $y);
    }

    /**
     * `RunAwayAction` считает дистанцию побега из level/health/tired персонажа
     * (`distanceMin=10`, клампится вверх до 10, если факторы малы) — при
     * `lowMobility: true` (level=1, health=1, tired=1) дистанция клампится РОВНО
     * к 10, поэтому детерминированный побег требует клеток на дистанции 10 во
     * всех 8 направлениях от точки отправления (цикл пробует случайное
     * направление, первое совпадение — успех).
     */
    private function createFleeDestinations(int $originX, int $originY, int $distance): void
    {
        $directions = [
            [1, 0], [-1, 0], [0, 1], [0, -1],
            [1, 1], [-1, 1], [1, -1], [-1, -1],
        ];
        foreach ($directions as [$dx, $dy]) {
            $this->createCell($originX + $dx * $distance, $originY + $dy * $distance);
        }
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
     * @param array{telegram_id:int} $opts
     * @return array{id:int,tgId:int}
     */
    private function insertCharacter(int $cell, array $opts, bool $lowMobility = false): array
    {
        $this->conn->table('telegram_users')->insert(['telegram_id' => $opts['telegram_id']]);
        $telegramUserId          = (int) $this->conn->insertID();
        $this->telegramUserIds[] = $telegramUserId;

        // lowMobility=true → RunAwayAction::distancePossible клампится ровно к 10
        // (см. createFleeDestinations()), а не к случайному значению — детерминизм
        // без правки production-кода.
        $level = $lowMobility ? 1 : 10;
        $health = $lowMobility ? 1 : 100;
        $tired  = $lowMobility ? 1 : 100;

        $this->conn->table('characters')->insert([
            'name'             => 'T' . random_int(100000, 999999),
            'level'            => $level,
            'health'           => $health,
            'tired'            => $tired,
            'strength'         => 50,
            'agility'          => 50,
            'intellect'        => 50,
            'experience'       => 100,
            'gold'             => 2000,
            'cell_number'      => $cell,
            'telegram_user_id' => $telegramUserId,
            'created_at'       => date('Y-m-d H:i:s', time() - 60 * 86400),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $id                   = (int) $this->conn->insertID();
        $this->characterIds[] = $id;

        return ['id' => $id, 'tgId' => $opts['telegram_id']];
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
