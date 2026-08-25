<?php

use App\Filters\TelegramRateLimitFilter;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
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
        \Config\Services::cache()->delete('tg_rate_group_setting_miss');
        \Config\Services::cache()->delete('tg_rate_group_' . self::GROUP);
        \Config\Services::cache()->delete('tg_rate_group_setting_missing_notice');
        Time::setTestNow('2026-08-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        putenv('telegram.RATE_LIMIT_PER_MINUTE');
        \Config\Services::cache()->delete('tg_rate_' . self::USER);
        \Config\Services::cache()->delete('tg_rate_group_' . self::GROUP);
        \Config\Services::cache()->delete('tg_rate_group_setting_missing_notice');
        \Config\Services::cache()->delete('tg_rate_group_setting_miss');
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

    /**
     * Канальный пост: у него НЕТ `from` вообще (в канал пишет канал, а не пользователь) —
     * ровно тот случай, который дефект 1 story-61 не считал никаким ведром.
     *
     * @return array<string, mixed>
     */
    private function channelPostTap(int $updateId): array
    {
        return [
            'update_id'    => $updateId,
            'channel_post' => [
                'message_id' => $updateId,
                'chat'       => ['id' => self::GROUP, 'type' => 'channel'],
                'text'       => 'пост в канал',
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

    /**
     * story community-chat-bot-49, дефект: признак «строки настройки нет» переписывался
     * трижды (33 → 37 → 41), и ни разу этот путь не проверялся тестом — предыдущие тесты
     * файла всегда держат строку `game_settings` заведённой (фикстура setUp вставляет её
     * со значением 600), в том числе тест «не изменена» ниже по значению совпадает с
     * fallback-константой, но НЕ проверяет случай отсутствующей строки как таковой.
     *
     * Здесь строка удаляется целиком: `GameSettingsService::get()` идёт по ветке
     * `$row === null` → возвращает `$default` (в проде это `null`, сигнальное значение
     * для `groupMaxPerMinute()`), фильтр обязан откатиться на персональный лимит 60/мин.
     */
    public function testGroupCeilingFallsBackToPersonalWindowWhenSettingRowIsMissing(): void
    {
        $this->conn->table('game_settings')->where('setting_key', self::SETTING_KEY)->delete();
        $this->assertNull(
            (new GameSettingsService())->get(self::SETTING_KEY, null),
            'Предпосылка теста: строки настройки в БД действительно больше нет'
        );

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::PERSONAL_LIMIT; $i++) {
            $this->assertNull($this->tap($filter, $this->groupTap($i)), "Групповой тап #{$i} обязан пройти под персональным фолбэком 60/мин");
        }

        $blocked = $this->tap($filter, $this->groupTap(self::PERSONAL_LIMIT));

        $this->assertInstanceOf(
            ResponseInterface::class,
            $blocked,
            'При отсутствующей строке настройки групповое ведро обязано откатиться на персональный лимит 60/мин, а не остаться безлимитным'
        );
        $this->assertSame(200, $blocked->getStatusCode());
    }

    /**
     * Контракт story: «след отката наблюдаем в тесте, а не только в коде». Наблюдаемый
     * след — не лог-строка (файловый лог не читается тестом надёжно), а сам факт записи
     * в кэш `tg_rate_group_setting_missing_notice`, которую делает
     * `notifyGroupLimitFallbackOnce()` РОВНО тогда, когда `groupMaxPerMinute()` пошёл по
     * ветке отсутствующей настройки. Кэш пуст до отката и не пуст после — это и есть
     * проверяемый след, отдельный от значения лимита.
     */
    public function testGroupCeilingMissingSettingLeavesObservableFallbackTrace(): void
    {
        $this->conn->table('game_settings')->where('setting_key', self::SETTING_KEY)->delete();
        $cache = \Config\Services::cache();

        $this->assertNull(
            $cache->get('tg_rate_group_setting_missing_notice'),
            'Предпосылка теста: следа отката ещё нет'
        );

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();
        $this->tap($filter, $this->groupTap(1));

        $this->assertNotNull(
            $cache->get('tg_rate_group_setting_missing_notice'),
            'После обращения к отсутствующей настройке в кэше обязан остаться наблюдаемый след отката'
        );
    }

    /**
     * story-61, дефект 1 «channel_post не считается ничем»: `before()` выходил по
     * `$userId === null` РАНЬШЕ группового ведра, а у канального поста `from` нет
     * вовсе. Красный на прежнем поведении — старый код пропускал апдейт молча ДО
     * дефекта, лимит никогда не срабатывал.
     */
    public function testChannelPostWithoutFromCountsTowardGroupBucket(): void
    {
        (new GameSettingsService())->set(self::SETTING_KEY, self::GROUP_LIMIT);

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        for ($i = 0; $i < self::GROUP_LIMIT; $i++) {
            $this->assertNull($this->tap($filter, $this->channelPostTap($i)), "Канальный пост #{$i} в пределах группового лимита");
        }

        $blocked = $this->tap($filter, $this->channelPostTap(self::GROUP_LIMIT));

        $this->assertInstanceOf(
            ResponseInterface::class,
            $blocked,
            'Канальный пост без from обязан учитываться групповым ведром чата и блокироваться при превышении лимита'
        );
        $this->assertSame(200, $blocked->getStatusCode());
    }

    /**
     * story-61, дефект 1: смешанный поток — часть тапов обычные групповые сообщения,
     * часть канальные посты. Оба типа обязаны делить ОДНО и то же ведро чата.
     */
    public function testChannelPostAndGroupMessageShareTheSameBucket(): void
    {
        (new GameSettingsService())->set(self::SETTING_KEY, self::GROUP_LIMIT);

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        // Всего GROUP_LIMIT тапов ДОЛЖНЫ пройти (канальный + групповые вперемешку),
        // (GROUP_LIMIT + 1)-й — упереться в лимит: 1 канальный + (GROUP_LIMIT - 1) групповых.
        $this->assertNull($this->tap($filter, $this->channelPostTap(1)), 'Канальный пост #1 в пределах лимита');
        for ($i = 2; $i <= self::GROUP_LIMIT; $i++) {
            $this->assertNull($this->tap($filter, $this->groupTap($i)), "Групповое сообщение #{$i} в пределах лимита");
        }

        $blocked = $this->tap($filter, $this->channelPostTap(self::GROUP_LIMIT + 1));

        $this->assertInstanceOf(
            ResponseInterface::class,
            $blocked,
            'Канальный пост и групповое сообщение того же чата обязаны делить один счётчик'
        );
    }

    /**
     * story-61, дефект 2 «промах настройки не кэшируется»: `groupMaxPerMinute()` бил
     * `findByKey()` на КАЖДЫЙ апдейт группы, пока строки в `game_settings` не было.
     * Красный на прежнем поведении: без кэша-промаха появление строки в БД мгновенно
     * (на следующем же тапе) отражалось бы на результате — здесь же строка появляется
     * ПОСЛЕ первого тапа, а результат обязан остаться прежним (фолбэк 60/мин) до конца
     * своего кэш-окна, доказывая, что промах был закэширован, а не перечитан заново.
     */
    public function testGroupCeilingMissCachedIgnoresSettingAppearingWithinSameWindow(): void
    {
        $this->conn->table('game_settings')->where('setting_key', self::SETTING_KEY)->delete();

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        // Первый тап читает БД (строки нет), кэширует промах и откатывается на
        // персональный фолбэк — это и есть окно, дальше по нему живёт кэш.
        $this->tap($filter, $this->groupTap(0));

        // Строка появляется В БД мимо кэша (как если бы её завела миграция) —
        // но кэш промаха ещё не истёк.
        $this->conn->table('game_settings')->insert([
            'setting_key'        => self::SETTING_KEY,
            'category'           => 'experimental',
            'value_type'         => 'int',
            'value_int'          => self::GROUP_LIMIT,
            'default_value_text' => (string) self::GROUP_LIMIT,
            'rationale_text'     => 'test fixture',
            'effect_text'        => 'test fixture',
            'above_effect_text'  => 'test fixture',
            'below_effect_text'  => 'test fixture',
        ]);

        // Групповое ведро обязано остаться на персональном фолбэке (60/мин), а не
        // мгновенно подхватить новую строку — иначе кэша промаха не было бы вовсе.
        for ($i = 1; $i < self::PERSONAL_LIMIT; $i++) {
            $this->assertNull(
                $this->tap($filter, $this->groupTap($i)),
                "Тап #{$i} обязан оставаться под персональным фолбэком — промах ещё закэширован"
            );
        }
    }

    /**
     * story-61, дефект 2: групповое ведро обязано читать `game_settings` за
     * пропавшим ключом ОДИН раз за окно кэша, а не на каждый апдейт группы
     * (acceptance: «Отсутствие ключа настройки читается из БД один раз за окно
     * кэша, а не на каждый апдейт»).
     *
     * Разбор красноты предыдущих двух попыток (`$cache->getMetaData()`, затем
     * `Time::setTestNow()`-сдвиг на 61с): в тестах `\Config\Services::cache()`
     * подменяется `CIUnitTestCase::mockCache()` (входит в стандартный
     * `$setUpMethods` framework'а) на `CodeIgniter\Test\Mock\MockCache`, у
     * которого `get()` **вообще не проверяет TTL** — `expirations` заполняется,
     * но `get()` смотрит только в `$this->cache[$key]`. `getMetaData()` TTL
     * проверяет, но с перевёрнутым условием (возвращает `null` для ЕЩЁ ЖИВОЙ
     * записи) — баг самого framework-мока, не нашего кода. В этом окружении
     * ни экспайр, ни интроспекция метаданных ничего не докажут: `MockCache`
     * структурно не даёт кэш-записи «протухнуть» через `get()`.
     *
     * Честный сигнал в этих условиях — реальные SQL-запросы к `game_settings`:
     * CI4 шлёт событие `DBQuery` на КАЖДЫЙ исполненный запрос независимо от
     * кэш-слоя. Считаем такие запросы за серию тапов внутри одного окна — их
     * обязано быть 1, а не по одному на тап (это ровно исходная формулировка
     * дефекта в story).
     */
    public function testGroupCeilingMissReadsSettingsTableOnlyOncePerWindow(): void
    {
        $this->conn->table('game_settings')->where('setting_key', self::SETTING_KEY)->delete();

        $filter = new GroupCeilingSpyTelegramRateLimitFilter();

        $queries = 0;
        Events::on('DBQuery', static function (Query $query) use (&$queries): void {
            if (str_contains($query->getQuery(), 'game_settings')) {
                $queries++;
            }
        });

        try {
            for ($i = 0; $i < 5; $i++) {
                $this->assertNull($this->tap($filter, $this->groupTap($i)), "Тап #{$i} обязан пройти под персональным фолбэком");
            }
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $this->assertSame(
            1,
            $queries,
            'groupMaxPerMinute() обязан читать game_settings один раз за окно кэша, а не на каждый апдейт группы (5 тапов → 5 запросов при регрессии)'
        );
    }
}
