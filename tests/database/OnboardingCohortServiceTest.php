<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Analytics\OnboardingCohortService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Снимок суточных когорт новичков (аудит 2026-08-12).
 *
 * Главный инвариант: **строка, посчитанная на полных логах, не перезаписывается пересчётом
 * на урезанных**. Ради него таблица и заведена — firehose живёт 30 дней, и без защиты
 * история гнила бы ровно тем же способом, от которого снимок спасает.
 *
 * Проверен обратной подменой: если убрать проверку `logs_complete` в `recompute()`,
 * `testCompleteRowIsNotOverwrittenByTrimmedLogs` краснеет (добыча 2 → 0).
 *
 * @internal
 */
final class OnboardingCohortServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TZ_OK = 'Y-m-d H:i:s';

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');

        foreach (['onboarding_cohort_daily', 'characters', 'telegram_users', 'player_action_log', 'explored_cells', 'crafted_items_log'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }

        $db->query('CREATE TABLE onboarding_cohort_daily (
            id INT AUTO_INCREMENT PRIMARY KEY, cohort_date DATE NOT NULL UNIQUE,
            regs INT DEFAULT 0, beyond_start INT DEFAULT 0, moved INT DEFAULT 0,
            gathered INT DEFAULT 0, crafted INT DEFAULT 0, back_d1 INT DEFAULT 0, back_d7 INT DEFAULT 0,
            logs_complete TINYINT(1) DEFAULT 0, computed_at DATETIME NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE telegram_users (id INT PRIMARY KEY, created_at DATETIME NOT NULL)');
        $db->query('CREATE TABLE characters (id INT PRIMARY KEY, telegram_user_id INT NOT NULL)');
        $db->query('CREATE TABLE player_action_log (id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NOT NULL, action_name VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
        $db->query('CREATE TABLE explored_cells (id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NOT NULL, created_at DATETIME NOT NULL)');
        $db->query('CREATE TABLE crafted_items_log (id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NOT NULL, created_at DATETIME NOT NULL)');
    }

    /** Новичок, зарегистрированный N суток назад, с заданным набором следов. */
    private function seedNewbie(int $id, int $daysAgo, array $opts = []): void
    {
        $db  = Database::connect('tests');
        $reg = date(self::TZ_OK, strtotime("-{$daysAgo} days 10:00:00"));

        $db->query('INSERT INTO telegram_users (id, created_at) VALUES (?, ?)', [$id, $reg]);
        $db->query('INSERT INTO characters (id, telegram_user_id) VALUES (?, ?)', [$id, $id]);

        $taps = (int) ($opts['taps'] ?? 1);
        for ($i = 0; $i < $taps; $i++) {
            $db->query(
                'INSERT INTO player_action_log (character_id, action_name, created_at) VALUES (?, ?, ?)',
                [$id, 'start', date(self::TZ_OK, strtotime($reg) + $i * 60)]
            );
        }
        if (! empty($opts['gathered'])) {
            $db->query(
                'INSERT INTO player_action_log (character_id, action_name, created_at) VALUES (?, ?, ?)',
                [$id, 'gather', date(self::TZ_OK, strtotime($reg) + 600)]
            );
        }
        if (! empty($opts['moved'])) {
            $db->query('INSERT INTO explored_cells (character_id, created_at) VALUES (?, ?)',
                [$id, date(self::TZ_OK, strtotime($reg) + 300)]);
        }
        if (! empty($opts['back_d1'])) {
            $db->query('INSERT INTO explored_cells (character_id, created_at) VALUES (?, ?)',
                [$id, date(self::TZ_OK, strtotime($reg) + 26 * 3600)]);
        }
        if (! empty($opts['crafted'])) {
            $db->query('INSERT INTO crafted_items_log (character_id, created_at) VALUES (?, ?)',
                [$id, date(self::TZ_OK, strtotime($reg) + 900)]);
        }
    }

    private function row(string $date): ?array
    {
        return Database::connect('tests')->table('onboarding_cohort_daily')
            ->where('cohort_date', $date)->get()->getRowArray();
    }

    public function testCountsFunnelWithin24hHorizon(): void
    {
        $this->seedNewbie(1, 3, ['taps' => 4, 'moved' => true, 'gathered' => true, 'crafted' => true, 'back_d1' => true]);
        $this->seedNewbie(2, 3, ['taps' => 1]);                       // только /start
        $this->seedNewbie(3, 3, ['taps' => 3, 'moved' => true]);      // дошёл до карты, не добыл

        (new OnboardingCohortService())->recompute(10);

        $r = $this->row(date('Y-m-d', strtotime('-3 days')));
        $this->assertNotNull($r);
        $this->assertSame(3, (int) $r['regs']);
        $this->assertSame(2, (int) $r['beyond_start'], 'один сделал ровно один тап');
        $this->assertSame(2, (int) $r['moved']);
        $this->assertSame(1, (int) $r['gathered']);
        $this->assertSame(1, (int) $r['crafted']);
        $this->assertSame(1, (int) $r['back_d1']);
    }

    public function testActionsAfter24hDoNotCount(): void
    {
        $this->seedNewbie(10, 5, ['taps' => 1]);
        $reg = date(self::TZ_OK, strtotime('-5 days 10:00:00'));
        Database::connect('tests')->query(
            'INSERT INTO player_action_log (character_id, action_name, created_at) VALUES (?, ?, ?)',
            [10, 'gather', date(self::TZ_OK, strtotime($reg) + 48 * 3600)]
        );

        (new OnboardingCohortService())->recompute(10);

        $r = $this->row(date('Y-m-d', strtotime('-5 days')));
        $this->assertSame(0, (int) $r['gathered'], 'добыча на третьи сутки в окно 24ч не попадает');
    }

    /**
     * 🔴 Сердце защиты: логи урезали, но полная строка остаётся нетронутой.
     */
    public function testCompleteRowIsNotOverwrittenByTrimmedLogs(): void
    {
        $this->seedNewbie(20, 20, ['taps' => 3, 'moved' => true, 'gathered' => true]);
        $this->seedNewbie(21, 20, ['taps' => 3, 'moved' => true, 'gathered' => true]);

        $svc  = new OnboardingCohortService();
        $date = date('Y-m-d', strtotime('-20 days'));

        $svc->recompute(30);
        $before = $this->row($date);
        $this->assertSame(2, (int) $before['gathered']);
        $this->assertSame(1, (int) $before['logs_complete'], 'логи полные — строка достоверна');

        // Чистка съела всё старше 10 суток.
        Database::connect('tests')->query(
            'DELETE FROM player_action_log WHERE created_at < ?',
            [date(self::TZ_OK, strtotime('-10 days'))]
        );

        $res   = $svc->recompute(30);
        $after = $this->row($date);

        $this->assertSame(2, (int) $after['gathered'], 'снимок обязан пережить чистку логов');
        $this->assertSame(1, (int) $after['logs_complete']);
        $this->assertGreaterThan(0, $res['skipped_stale'], 'пропуск устаревших пересчётов должен считаться');
    }

    public function testFreshCohortIsMarkedIncompleteWhenLogsAreTrimmed(): void
    {
        $this->seedNewbie(30, 25, ['taps' => 2]);
        Database::connect('tests')->query(
            'DELETE FROM player_action_log WHERE created_at < ?',
            [date(self::TZ_OK, strtotime('-10 days'))]
        );

        (new OnboardingCohortService())->recompute(30);

        $r = $this->row(date('Y-m-d', strtotime('-25 days')));
        $this->assertNotNull($r, 'строка появляется даже на урезанных логах');
        $this->assertSame(0, (int) $r['logs_complete'], 'но честно помечена как неполная');
    }
}
