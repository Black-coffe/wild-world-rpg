<?php

use App\Filters\TelegramRateLimitFilter;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Тест-двойник фильтра — тот же паттерн, что в {@see TelegramRateLimitGroupScopeTest}.
 */
final class GroupCeilingSpyTelegramRateLimitFilter extends TelegramRateLimitFilter
{
    /** @var list<array{method: string, params: array<string, scalar>}> */
    public array $calls = [];

    /** @param array<string, scalar> $params */
    protected function callTelegram(string $method, array $params): void
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
    }
}

/**
 * story community-chat-bot-25, дефект 2 «Лимит на весь чат» — групповое ведро
 * `tg_rate_group_{chatId}` до этой story делило числовой лимит с персональным
 * окном игрока (60/мин). Тест проверяет, что групповой потолок теперь читается
 * из `GameSettings` (`experimental.community_chat.rate_limit_per_minute`) и
 * отличается от персонального, а персональное окно игрока не меняется ни по
 * ключу, ни по величине.
 *
 * Изолированная схема `game_settings` (паттерн {@see \Tests\Unit\Services\CommunityChatSenderTest}):
 * своя DROP+CREATE в `wildworld_tests`, а не реальная миграция — та отстаёт
 * локально на сотни непрогнанных шагов (см. `feedback_local_green_on_empty_test_db_proves_nothing`).
 *
 * @internal
 */
final class TelegramRateLimitGroupCeilingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const SETTING_KEY    = 'experimental.community_chat.rate_limit_per_minute';
    private const PERSONAL_LIMIT = 60;
    private const GROUP_LIMIT    = 5;
    private const USER           = 444444;
    private const GROUP          = -1009988776;

    /** @var BaseConnection<\mysqli, \mysqli_result> */
    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->query('DROP TABLE IF EXISTS game_settings');
        $this->conn->query('
            CREATE TABLE game_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(64) NOT NULL UNIQUE,
                category VARCHAR(32) NOT NULL,
                value_type ENUM(\'int\', \'float\', \'bool\', \'string\') NOT NULL,
                value_int INT NULL,
                value_float DECIMAL(12,4) NULL,
                value_bool TINYINT(1) NULL,
                value_string VARCHAR(255) NULL,
                default_value_text TEXT NOT NULL,
                rationale_text TEXT NOT NULL,
                effect_text TEXT NOT NULL,
                above_effect_text TEXT NOT NULL,
                below_effect_text TEXT NOT NULL,
                recommended_min VARCHAR(64) NULL,
                recommended_max VARCHAR(64) NULL,
                hard_min VARCHAR(64) NULL,
                hard_max VARCHAR(64) NULL,
                updated_by VARCHAR(128) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->conn->table('game_settings')->insert([
            'setting_key'        => self::SETTING_KEY,
            'category'           => 'experimental',
            'value_type'         => 'int',
            'value_int'          => 600,
            'default_value_text' => '600',
            'rationale_text'     => 'test fixture',
            'effect_text'        => 'test fixture',
            'above_effect_text'  => 'test fixture',
            'below_effect_text'  => 'test fixture',
        ]);

        putenv('telegram.RATE_LIMIT_PER_MINUTE=' . self::PERSONAL_LIMIT);
        \Config\Services::cache()->delete('tg_rate_' . self::USER);
        \Config\Services::cache()->delete('tg_rate_group_' . self::GROUP);
        Time::setTestNow('2026-08-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        putenv('telegram.RATE_LIMIT_PER_MINUTE');
        \Config\Services::cache()->delete('tg_rate_' . self::USER);
        \Config\Services::cache()->delete('tg_rate_group_' . self::GROUP);
        $this->conn->query('DROP TABLE IF EXISTS game_settings');

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function groupTap(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'message'   => [
                'message_id' => $updateId,
                'from'       => ['id' => self::USER, 'is_bot' => false],
                'chat'       => ['id' => self::GROUP, 'type' => 'supergroup'],
                'text'       => 'привет всем',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function privateTap(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'message'   => [
                'message_id' => $updateId,
                'from'       => ['id' => self::USER, 'is_bot' => false],
                'chat'       => ['id' => self::USER, 'type' => 'private'],
                'text'       => '🏠 База',
            ],
        ];
    }

    /** @param array<string, mixed> $update */
    private function tap(TelegramRateLimitFilter $filter, array $update): mixed
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getBody')->willReturn(json_encode($update));

        return $filter->before($request);
    }

    /**
     * 🔴 Главный тест story: групповой потолок читается из GameSettings и
     * отличается от персонального — тут он МЕНЬШЕ персонального, чтобы отличить
     * «свой лимит» от «случайно унаследованный персональный».
     */
    public function testGroupCeilingReadFromGameSettingsDiffersFromPersonal(): void
    {
        (new GameSettingsService())->set(self::SETTING_KEY, self::GROUP_LIMIT);

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::GROUP_LIMIT; $i++) {
            $this->assertNull($this->tap($filter, $this->groupTap($i)), "Групповой тап #{$i} в пределах группового лимита");
        }

        $blocked = $this->tap($filter, $this->groupTap(self::GROUP_LIMIT));

        $this->assertInstanceOf(ResponseInterface::class, $blocked, 'Групповой лимит из GameSettings обязан сработать раньше персонального 60/мин');
        $this->assertSame(200, $blocked->getStatusCode());
    }

    /** Превышение группового лимита не расходует персональное окно игрока того же from.id. */
    public function testGroupCeilingExceededDoesNotConsumePersonalWindow(): void
    {
        (new GameSettingsService())->set(self::SETTING_KEY, self::GROUP_LIMIT);

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        for ($i = 0; $i <= self::GROUP_LIMIT + 10; $i++) {
            $this->tap($filter, $this->groupTap($i));
        }

        $this->assertNull(
            $this->tap($filter, $this->privateTap(9000)),
            'Личный апдейт того же игрока обязан пройти после превышения группового лимита'
        );
    }

    /** Без правки в GameSettings групповое ведро использует значение фикстуры (600), не персональный 60/мин. */
    public function testGroupCeilingFallsBackToConfiguredDefaultWhenNotChanged(): void
    {
        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        for ($i = 0; $i <= self::PERSONAL_LIMIT; $i++) {
            $this->assertNull($this->tap($filter, $this->groupTap($i)), 'Значение из GameSettings (600) выше персонального 60/мин');
        }
    }

    /** Персональное окно не меняется ни по ключу, ни по величине — предупреждение приходит на 61-м тапе, как раньше. */
    public function testPersonalWindowUnaffectedByGroupSetting(): void
    {
        (new GameSettingsService())->set(self::SETTING_KEY, self::GROUP_LIMIT);

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        for ($i = 0; $i <= self::PERSONAL_LIMIT; $i++) {
            $this->tap($filter, $this->privateTap($i));
        }

        $this->assertCount(1, $filter->calls, 'Личный лимит по-прежнему считается величиной 60/мин, независимо от группового ключа');
    }
}
