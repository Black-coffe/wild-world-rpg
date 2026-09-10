<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Player\HealthWarningSchedule;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * health-warning-backoff, story-02 — HealthWarningSchedule — чистый класс без БД и без
 * Telegram, поэтому набор зелёный на ПУСТОЙ БД (GameSettingsService деградирует на
 * `$default`, когда таблица `game_settings` недоступна — см. GameSettingsService::get()).
 * Все проверки идут на дефолтных числах брифа: base=33, multiplier=2.0, max=360,
 * critical_base=5, critical_max=30, daily_cap=5, bands="0.1,1,3,5".
 */
final class HealthWarningScheduleTest extends CIUnitTestCase
{
    private HealthWarningSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedule = new HealthWarningSchedule();
    }

    public function testFirstWarningAlwaysSendsWhenNoPriorState(): void
    {
        $now = strtotime('2026-01-01 12:00:00');

        $result = $this->schedule->decide(
            health: 4.5,
            lastNotifiedAt: null,
            warnStreak: 0,
            lastBand: null,
            warnsToday: 0,
            warnsDay: null,
            lastActionAt: null,
            activeDamageEvent: false,
            now: $now,
        );

        $this->assertTrue($result['should_send']);
        $this->assertSame(1, $result['warn_streak']);
        $this->assertSame(5.0, $result['last_band']);
        $this->assertSame($now, $result['last_notified_at']);
    }

    public function testIgnoredWarningBacksOffBeforeRepeating(): void
    {
        $t0    = strtotime('2026-01-01 12:00:00');
        $first = $this->schedule->decide(4.5, null, 0, null, 0, null, null, false, $t0);
        $this->assertTrue($first['should_send']);

        // Базовый интервал (33 мин) ещё не даёт права на второе предупреждение —
        // streak=1 после первой отправки требует 33*2=66 минут.
        $afterBaseInterval = $this->schedule->decide(
            4.5,
            $t0,
            $first['warn_streak'],
            $first['last_band'],
            $first['warns_today'],
            $first['warns_day'],
            null,
            false,
            $t0 + 33 * 60,
        );
        $this->assertFalse($afterBaseInterval['should_send']);

        $afterBackedOffInterval = $this->schedule->decide(
            4.5,
            $t0,
            $first['warn_streak'],
            $first['last_band'],
            $first['warns_today'],
            $first['warns_day'],
            null,
            false,
            $t0 + 66 * 60,
        );
        $this->assertTrue($afterBackedOffInterval['should_send']);
    }

    public function testPlayerReactionResetsIntervalToBase(): void
    {
        $t0    = strtotime('2026-01-01 12:00:00');
        $first = $this->schedule->decide(4.5, null, 0, null, 0, null, null, false, $t0);

        // Игрок нажал что-то через 10 минут после предупреждения — реакция.
        $result = $this->schedule->decide(
            4.5,
            $t0,
            $first['warn_streak'],
            $first['last_band'],
            $first['warns_today'],
            $first['warns_day'],
            $t0 + 600,
            false,
            $t0 + 40 * 60, // 40 мин: меньше 66 (backed-off), но больше 33 (базовый)
        );

        $this->assertTrue($result['should_send']);
        $this->assertSame(1, $result['warn_streak']);
    }

    public function testBandDropSendsImmediatelyAndResetsStreak(): void
    {
        $t0 = strtotime('2026-01-01 12:00:00');

        $result = $this->schedule->decide(
            health: 0.5, // упало из полосы 5.0 в полосу 1.0
            lastNotifiedAt: $t0,
            warnStreak: 3, // затухание уже сильно растянуто (33*2^3=264 мин)
            lastBand: 5.0,
            warnsToday: 0,
            warnsDay: date('Y-m-d', $t0),
            lastActionAt: null,
            activeDamageEvent: false,
            now: $t0 + 60, // всего 1 минута — затухание точно не истекло
        );

        $this->assertTrue($result['should_send']);
        $this->assertSame(0, $result['warn_streak']);
        $this->assertSame(1.0, $result['last_band']);
    }

    public function testBandImprovementAloneDoesNotTriggerSend(): void
    {
        $t0 = strtotime('2026-01-01 12:00:00');

        $result = $this->schedule->decide(
            health: 4.0, // поднялось из полосы 1.0 в полосу 5.0
            lastNotifiedAt: $t0,
            warnStreak: 1,
            lastBand: 1.0,
            warnsToday: 0,
            warnsDay: date('Y-m-d', $t0),
            lastActionAt: null,
            activeDamageEvent: false,
            now: $t0 + 60, // сильно меньше базового интервала
        );

        $this->assertFalse($result['should_send']);
        $this->assertSame(1.0, $result['last_band']); // не тронуто — не слали
    }

    public function testDailyCapBlocksNonCriticalButNotCritical(): void
    {
        $t0 = strtotime('2026-01-01 12:00:00');

        $blocked = $this->schedule->decide(
            health: 4.5,
            lastNotifiedAt: $t0,
            warnStreak: 0,
            lastBand: 5.0,
            warnsToday: 5, // daily_cap уже исчерпан
            warnsDay: date('Y-m-d', $t0),
            lastActionAt: null,
            activeDamageEvent: false,
            now: $t0 + 40 * 60, // базовый интервал давно истёк
        );
        $this->assertFalse($blocked['should_send']);
        $this->assertSame(5, $blocked['warns_today']); // не растёт при блокировке

        $criticalSend = $this->schedule->decide(
            health: 0.05,
            lastNotifiedAt: $t0,
            warnStreak: 0,
            lastBand: 0.10,
            warnsToday: 5, // потолок не применяется к критической полосе
            warnsDay: date('Y-m-d', $t0),
            lastActionAt: null,
            activeDamageEvent: false,
            now: $t0 + 6 * 60, // критический базовый интервал (5 мин) истёк
        );
        $this->assertTrue($criticalSend['should_send']);
    }

    public function testDayRolloverResetsDailyCounter(): void
    {
        $dayOne = strtotime('2026-01-01 23:00:00');
        $dayTwo = strtotime('2026-01-02 00:30:00');

        $result = $this->schedule->decide(
            health: 4.5,
            lastNotifiedAt: $dayOne - 3600,
            warnStreak: 0,
            lastBand: 5.0,
            warnsToday: 5,
            warnsDay: date('Y-m-d', $dayOne),
            lastActionAt: null,
            activeDamageEvent: false,
            now: $dayTwo,
        );

        $this->assertTrue($result['should_send']);
        $this->assertSame(1, $result['warns_today']); // обнулился и вырос на 1 отправленное
        $this->assertSame(date('Y-m-d', $dayTwo), $result['warns_day']);
    }

    public function testActiveDamageEventClampsIntervalDownNotUp(): void
    {
        // Ловит мутацию min→max в intervalMinutes(): без клэмпа вниз затухание (streak=3,
        // 33*2^3=264 мин) держало бы предупреждение 264 минуты даже при активном событии.
        $t0 = strtotime('2026-01-01 12:00:00');

        $result = $this->schedule->decide(
            health: 4.5, // band=5.0, тот же что lastBand — без пробивания через band-drop
            lastNotifiedAt: $t0,
            warnStreak: 3,
            lastBand: 5.0,
            warnsToday: 0,
            warnsDay: date('Y-m-d', $t0),
            lastActionAt: null,
            activeDamageEvent: true,
            now: $t0 + 6 * 60, // 6 мин: меньше 264 (без клэмпа), но больше 5 (критический потолок)
        );

        $this->assertTrue($result['should_send']);
    }

    public function testZeroHealthIsNotTreatedAsCritical(): void
    {
        // Осознанное решение (см. докблок класса): критический режим требует health > 0.

        // Здесь health=0.0 держится на некритическом интервале (33 мин), а не на критическом (5).
        $t0 = strtotime('2026-01-01 12:00:00');

        $result = $this->schedule->decide(
            health: 0.0,
            lastNotifiedAt: $t0,
            warnStreak: 0,
            lastBand: 0.1, // та же полоса, что у health=0.0 — без пробивания через band-drop
            warnsToday: 0,
            warnsDay: date('Y-m-d', $t0),
            lastActionAt: null,
            activeDamageEvent: false,
            now: $t0 + 6 * 60, // хватило бы на критический интервал (5 мин), не хватает на базовый (33)
        );

        $this->assertFalse($result['should_send']);
    }

    public function testParseBandsWellFormedString(): void
    {
        $this->assertSame([0.5, 2.0, 6.0, 10.0], HealthWarningSchedule::parseBands('10,6,2,0.5'));
    }

    public function testParseBandsToleratesWhitespaceAndOrder(): void
    {
        // Тот же набор границ, но с пробелами и не по убыванию — сортировка при разборе.
        $this->assertSame([0.5, 2.0, 6.0, 10.0], HealthWarningSchedule::parseBands(' 6 , 10,0.5, 2 '));
    }

    public function testParseBandsRejectsGarbage(): void
    {
        // Мусор (нечисловой кусок, пустая строка, одна лишняя запятая) — не падаем, а
        // сигналим null, чтобы вызывающий код (bandBoundaries()) подставил безопасный дефолт.
        $this->assertNull(HealthWarningSchedule::parseBands('abc'));
        $this->assertNull(HealthWarningSchedule::parseBands(''));
        $this->assertNull(HealthWarningSchedule::parseBands('5,,3'));
        $this->assertNull(HealthWarningSchedule::parseBands('5,x,3'));
    }

    public function testFieldsToPersistIsEmptyWhenNothingChangedAndNotSent(): void
    {
        // Ветка «не шлём, состояние не менялось» — хендлер должен пропустить UPDATE вовсе.
        $decision = [
            'should_send'      => false,
            'warn_streak'      => 2,
            'last_band'        => 5.0,
            'warns_today'      => 1,
            'warns_day'        => '2026-01-01',
            'last_notified_at' => 12345,
        ];

        $fields = HealthWarningSchedule::fieldsToPersist($decision, 2, 5.0, 1, '2026-01-01');

        $this->assertSame([], $fields);
    }

    public function testFieldsToPersistWritesOnlyChangedFieldsWhenNotSent(): void
    {
        // Реакция игрока сбросила streak, предупреждение при этом не уходит — именно эта
        // ветка держит на себе всё затухание и раньше не была проверена ничем.
        $decision = [
            'should_send'      => false,
            'warn_streak'      => 0, // сброшен реакцией
            'last_band'        => 5.0,
            'warns_today'      => 1,
            'warns_day'        => '2026-01-01',
            'last_notified_at' => 12345, // не тронуто decide(), но и не должно попасть в UPDATE
        ];

        $fields = HealthWarningSchedule::fieldsToPersist($decision, 2, 5.0, 1, '2026-01-01');

        $this->assertSame([
            'low_health_warn_streak' => 0,
            'low_health_last_band'   => 5.0,
            'low_health_warns_today' => 1,
            'low_health_warns_day'   => '2026-01-01',
        ], $fields);
    }

    public function testFieldsToPersistWritesEverythingWhenSent(): void
    {
        $decision = [
            'should_send'      => true,
            'warn_streak'      => 1,
            'last_band'        => 5.0,
            'warns_today'      => 1,
            'warns_day'        => '2026-01-01',
            'last_notified_at' => 1767268800, // 2026-01-01 12:00:00 UTC
        ];

        $fields = HealthWarningSchedule::fieldsToPersist($decision, 0, null, 0, null);

        $this->assertSame([
            'low_health_notified_at' => date('Y-m-d H:i:s', 1767268800),
            'low_health_warn_streak' => 1,
            'low_health_last_band'   => 5.0,
            'low_health_warns_today' => 1,
            'low_health_warns_day'   => '2026-01-01',
        ], $fields);
    }
}
