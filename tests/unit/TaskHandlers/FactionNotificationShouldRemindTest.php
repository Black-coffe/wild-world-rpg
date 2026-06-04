<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\TaskHandlers\Other\FactionNotificationHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-097 — анти-спам потолок крон-уведомлений о выборе фракции.
 *
 * Тестируем ЧИСТЫЙ статик {@see FactionNotificationHandler::shouldRemind()} — без БД и
 * Telegram (статик не дёргает конструктор хендлера). Проверяем потолок (max_reminders),
 * каданс (interval_hours), граничные случаи и «без потолка» (0).
 *
 * @internal
 */
final class FactionNotificationShouldRemindTest extends CIUnitTestCase
{
    private const NOW = 1780000000; // фиксированный unix-time для детерминизма

    /** Часов назад → строка метки notified_at. */
    private function hoursAgo(int $hours): string
    {
        return date('Y-m-d H:i:s', self::NOW - $hours * 3600);
    }

    public function testRemindsWhenUnderCapAndIntervalElapsed(): void
    {
        // count=1 < max=5, последнее уведомление 25ч назад при интервале 24ч → напоминаем.
        $this->assertTrue(FactionNotificationHandler::shouldRemind(1, $this->hoursAgo(25), self::NOW, 5, 24));
    }

    public function testDoesNotRemindWhenIntervalNotElapsed(): void
    {
        // Потолок ок, но прошёл лишь 1ч при интервале 24ч → молчим.
        $this->assertFalse(FactionNotificationHandler::shouldRemind(1, $this->hoursAgo(1), self::NOW, 5, 24));
    }

    public function testDoesNotRemindWhenCapReached(): void
    {
        // count=5 НЕ меньше max=5 → потолок исчерпан, даже если интервал давно прошёл.
        $this->assertFalse(FactionNotificationHandler::shouldRemind(5, $this->hoursAgo(100), self::NOW, 5, 24));
    }

    public function testDoesNotRemindWhenCapExceeded(): void
    {
        // Легаси-персонаж с 288 нагами и max=5 → немедленная тишина.
        $this->assertFalse(FactionNotificationHandler::shouldRemind(288, $this->hoursAgo(100), self::NOW, 5, 24));
    }

    public function testRemindsAtLastAllowedReminder(): void
    {
        // count=4 < max=5 — последнее разрешённое напоминание.
        $this->assertTrue(FactionNotificationHandler::shouldRemind(4, $this->hoursAgo(25), self::NOW, 5, 24));
    }

    public function testZeroMaxMeansUnlimited(): void
    {
        // max=0 → без потолка: напоминаем при любом count, если интервал прошёл.
        $this->assertTrue(FactionNotificationHandler::shouldRemind(999, $this->hoursAgo(25), self::NOW, 0, 24));
    }

    public function testNullNotifiedAtReminds(): void
    {
        // Нет валидной метки → напоминаем (если потолок ок).
        $this->assertTrue(FactionNotificationHandler::shouldRemind(0, null, self::NOW, 5, 24));
        // …но не если потолок исчерпан.
        $this->assertFalse(FactionNotificationHandler::shouldRemind(5, null, self::NOW, 5, 24));
    }

    public function testCustomIntervalRespected(): void
    {
        // Интервал 72ч: 50ч назад → ещё рано; 80ч назад → пора.
        $this->assertFalse(FactionNotificationHandler::shouldRemind(0, $this->hoursAgo(50), self::NOW, 5, 72));
        $this->assertTrue(FactionNotificationHandler::shouldRemind(0, $this->hoursAgo(80), self::NOW, 5, 72));
    }
}
