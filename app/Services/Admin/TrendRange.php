<?php

declare(strict_types=1);

namespace App\Services\Admin;

use DateTimeImmutable;

/**
 * Окно time-series дашборда: либо пресет («последние N дней»), либо произвольный
 * диапазон дат (date range picker). Иммутабелен, валидируется при создании.
 *
 * Используется [[DashboardAnalyticsService]] для growth/battles/economy. Даты —
 * канонический `Y-m-d` из DateTimeImmutable → безопасны для интерполяции в SQL
 * (формат гарантированно `\d{4}-\d{2}-\d{2}`, кавычки/инъекция невозможны).
 */
final class TrendRange
{
    /** Жёсткий потолок ширины окна (защита от гигантских zero-fill массивов/запросов). */
    public const MAX_SPAN_DAYS = 366;

    private function __construct(
        public readonly string $start,  // Y-m-d (включительно, 00:00)
        public readonly string $end,    // Y-m-d (включительный день)
        public readonly int $days,      // число дней включительно
        public readonly bool $custom,   // true = произвольный диапазон, false = пресет
    ) {
    }

    /** Пресет «последние N дней» (заканчивается сегодня). N клампится в [1, MAX_SPAN_DAYS]. */
    public static function preset(int $days): self
    {
        $days  = max(1, min($days, self::MAX_SPAN_DAYS));
        $end   = new DateTimeImmutable('today');
        $start = $end->modify('-' . ($days - 1) . ' days');

        return new self($start->format('Y-m-d'), $end->format('Y-m-d'), $days, false);
    }

    /**
     * Произвольный диапазон из пользовательского ввода. Невалидные даты → null.
     * Нормализация: будущее → сегодня; перевёрнутый порядок → своп; ширина → потолок.
     */
    public static function fromDates(?string $from, ?string $to): ?self
    {
        $s = self::parse($from);
        $e = self::parse($to);
        if ($s === null || $e === null) {
            return null;
        }

        $today = new DateTimeImmutable('today');
        if ($s > $today) {
            $s = $today;
        }
        if ($e > $today) {
            $e = $today;
        }
        if ($s > $e) {
            [$s, $e] = [$e, $s];
        }

        $days = (int) $s->diff($e)->days + 1;
        if ($days > self::MAX_SPAN_DAYS) {
            $s    = $e->modify('-' . (self::MAX_SPAN_DAYS - 1) . ' days');
            $days = self::MAX_SPAN_DAYS;
        }

        return new self($s->format('Y-m-d'), $e->format('Y-m-d'), $days, true);
    }

    /** Диапазон из ввода ИЛИ пресет-фолбэк, если даты пустые/невалидные. */
    public static function resolve(?string $from, ?string $to, int $fallbackDays): self
    {
        return self::fromDates($from, $to) ?? self::preset($fallbackDays);
    }

    /** Строгий парс `Y-m-d` (round-trip ловит переполнения вроде «2026-13-40»). */
    private static function parse(?string $v): ?DateTimeImmutable
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v ? $d : null;
    }

    /**
     * Все даты диапазона по возрастанию (включительно) для zero-fill осей графиков.
     *
     * @return list<string> Y-m-d
     */
    public function dayKeys(): array
    {
        $out = [];
        $cur = new DateTimeImmutable($this->start);
        $end = new DateTimeImmutable($this->end);
        while ($cur <= $end) {
            $out[] = $cur->format('Y-m-d');
            $cur   = $cur->modify('+1 day');
        }

        return $out;
    }

    /** Нижняя граница для SQL (`>=`). */
    public function startSql(): string
    {
        return $this->start . ' 00:00:00';
    }

    /** Эксклюзивная верхняя граница для SQL (`<`): end + 1 день, 00:00. */
    public function endExclusiveSql(): string
    {
        return (new DateTimeImmutable($this->end))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    /** Человекочитаемая подпись окна (для подзаголовка/экспорта). */
    public function label(): string
    {
        if (! $this->custom) {
            return 'за ' . $this->days . ' дн.';
        }
        $f = static fn (string $d): string => (new DateTimeImmutable($d))->format('d.m.Y');

        return $f($this->start) . ' – ' . $f($this->end) . ' (' . $this->days . ' дн.)';
    }
}
