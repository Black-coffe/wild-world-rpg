<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\GameSettings\GameSettingsService;
use App\Services\Social\LeaderboardService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Ранжирование топа игроков на реальном SQL.
 *
 * Два места, где легко соврать:
 *  1. «#7» обязано совпадать со строкой №7 в таблице → порядок ранга и порядок выборки
 *     должны быть одним и тем же (уровень → опыт → id).
 *  2. Окно «живых» считается ЧАСАМИ БД, не PHP ([[feedback_db_clock_seed_not_php_in_time_window_tests]]):
 *     сеем last_seen через NOW() - INTERVAL, иначе tz-skew валит тест на CI.
 *
 * @internal
 */
final class LeaderboardRankingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = ['characters', 'telegram_users'];

    /** @var list<int> */
    private array $charIds = [];

    /**
     * `GameSettingsService` — final, мокать нельзя; таблицы `game_settings` в тестовой БД нет.
     * Поэтому подменяем сами аксессоры настроек (seam), оставляя SQL-ядро нетронутым —
     * именно его мы и проверяем.
     */
    private function svc(): LeaderboardService
    {
        return new class extends LeaderboardService {
            public function enabled(): bool
            {
                return true;
            }

            public function size(): int
            {
                return 10;
            }

            public function activeDays(): int
            {
                return 7;
            }
        };
    }

    /**
     * Сеет игрока. $seenDaysAgo === null → last_seen NULL (легаси-ветеран, ушёл).
     */
    private function seed(string $name, int $level, float $exp, ?int $seenDaysAgo): int
    {
        $db = Database::connect('tests');

        $seen = $seenDaysAgo === null
            ? null
            : $db->query('SELECT DATE_SUB(NOW(), INTERVAL ? DAY) AS t', [$seenDaysAgo])->getRowArray()['t'];

        $db->table('telegram_users')->insert([
            'telegram_id' => random_int(900_000_000, 999_999_999),
            'first_name'  => $name,
            'last_seen'   => $seen,
        ]);
        $tuId = (int) $db->insertID();

        $db->table('characters')->insert([
            'telegram_user_id' => $tuId,
            'name'             => $name,
            'level'            => $level,
            'experience'       => $exp,
        ]);
        $id = (int) $db->insertID();
        $this->charIds[] = $id;

        return $id;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE characters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_user_id INT NULL,
            name VARCHAR(100) NULL,
            level INT NOT NULL DEFAULT 1,
            experience DECIMAL(7,2) NOT NULL DEFAULT 0.01
        )');
        $db->query('CREATE TABLE telegram_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT NULL,
            first_name VARCHAR(100) NULL,
            last_seen DATETIME NULL
        )');
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        parent::tearDown();
    }

    public function testActiveTabExcludesDepartedVeterans(): void
    {
        // Ровно прод-картина: ветеран-легенда ушёл, живые слабее.
        $ghost  = $this->seed('Yupi', 169, 100.0, null);
        $alive1 = $this->seed('Arich', 40, 50.0, 1);
        $alive2 = $this->seed('San', 26, 20.0, 3);

        $active = $this->svc()->topActive();
        $names  = array_column($active, 'name');

        $this->assertSame(['Arich', 'San'], $names, 'Во вкладке «Живые» ушедшего ветерана быть не должно');
        $this->assertNotContains('Yupi', $names);

        // А в легендах он первый — это его место.
        $legends = $this->svc()->topLegends();
        $this->assertSame('Yupi', $legends[0]['name']);
        $this->assertSame(1, $legends[0]['rank']);
        $this->assertGreaterThan(0, $ghost);
        $this->assertGreaterThan(0, $alive1);
        $this->assertGreaterThan(0, $alive2);
    }

    public function testRankMatchesRowOrder(): void
    {
        $first  = $this->seed('First', 50, 10.0, 1);
        $second = $this->seed('Second', 20, 10.0, 1);
        $third  = $this->seed('Third', 10, 10.0, 1);

        $svc = $this->svc();

        $this->assertSame(1, $svc->rankOfActive($first));
        $this->assertSame(2, $svc->rankOfActive($second));
        $this->assertSame(3, $svc->rankOfActive($third));

        $rows = $svc->topActive();
        $this->assertSame($first, $rows[0]['id'], 'Строка №1 обязана принадлежать игроку с рангом #1');
        $this->assertSame($third, $rows[2]['id']);
    }

    public function testSameLevelBrokenByExperienceThenId(): void
    {
        $rich = $this->seed('Rich', 5, 99.0, 1);
        $poor = $this->seed('Poor', 5, 1.0, 1);

        $svc = $this->svc();
        $this->assertSame(1, $svc->rankOfActive($rich), 'При равном уровне выше тот, у кого больше опыта');
        $this->assertSame(2, $svc->rankOfActive($poor));

        // Полностью равные → детерминированный tie-break по id (иначе ранги «плавают»).
        $tieA = $this->seed('TieA', 3, 7.0, 1);
        $tieB = $this->seed('TieB', 3, 7.0, 1);
        $this->assertLessThan($svc->rankOfActive($tieB), $svc->rankOfActive($tieA));
    }

    public function testInactivePlayerHasNoActiveRankButHasLegendRank(): void
    {
        $this->seed('Alive', 10, 5.0, 1);
        $sleeper = $this->seed('Sleeper', 99, 5.0, 30); // заходил 30 дней назад — вне окна 7д

        $svc = $this->svc();
        $this->assertSame(0, $svc->rankOfActive($sleeper), 'Вне окна активности ранга нет → экран зовёт вернуться');
        $this->assertSame(1, $svc->rankOfLegends($sleeper), 'Но за всё время он первый по уровню');
    }

    public function testTotalsCountOnlyWhatTabShows(): void
    {
        $this->seed('A', 5, 1.0, 1);
        $this->seed('B', 4, 1.0, 2);
        $this->seed('Ghost', 100, 1.0, null);

        $svc = $this->svc();
        $this->assertSame(2, $svc->totalActive(), 'В «Живых» считаем только заходивших в окно');
        $this->assertSame(3, $svc->totalLegends());
    }

    public function testUnknownCharacterRanksZero(): void
    {
        $this->assertSame(0, $this->svc()->rankOfActive(0));
        $this->assertSame(0, $this->svc()->rankOfLegends(123456789));
    }
}
