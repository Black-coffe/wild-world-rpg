<?php

declare(strict_types=1);

namespace App\Services\Craft;

/**
 * ADR-158 — результат расчёта длительности крафта вместе с ответом на вопрос
 * «почему столько».
 *
 * Строка правды нужна потому, что бонусы времени были полностью невидимы: игрок,
 * имеющий −78% от базы, видел только итоговое число и просил «ускорить крафт»,
 * не зная, что ускорение у него уже есть. Невидимый множитель читается как поломка.
 */
final class CraftDurationBreakdown
{
    /**
     * @param int $minutes итоговые минуты на одну штуку
     * @param int $baseMinutes минуты до применения множителей
     * @param list<array{label:string, mult:float}> $factors только действующие ускорители
     */
    public function __construct(
        public readonly int $minutes,
        public readonly int $baseMinutes,
        public readonly array $factors
    ) {
    }

    public function hasBonuses(): bool
    {
        return $this->factors !== [] && $this->minutes < $this->baseMinutes;
    }

    /** Насколько быстрее базы, в целых процентах (78 = «−78%»). */
    public function savedPercent(): int
    {
        if ($this->baseMinutes <= 0 || ! $this->hasBonuses()) {
            return 0;
        }

        return (int) round((1 - $this->minutes / $this->baseMinutes) * 100);
    }

    /** «1 ч 20 мин» / «45 мин» — человеческая длительность. */
    public static function humanize(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' мин';
        }
        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return $rest === 0 ? $hours . ' ч' : $hours . ' ч ' . $rest . ' мин';
    }

    /**
     * Строка правды для экрана крафта. Без бонусов — просто время, без «−0%»
     * и без пустого перечисления (не врём и не шумим).
     *
     * @param int $quantity сколько штук запускается (время линейно по количеству)
     */
    public function truthLine(int $quantity = 1): string
    {
        $quantity = max(1, $quantity);
        $total    = $this->minutes * $quantity;

        if (! $this->hasBonuses()) {
            return '⏱ ' . self::humanize($total);
        }

        $labels = [];
        foreach ($this->factors as $factor) {
            $labels[] = $factor['label'];
        }

        return '⏱ ' . self::humanize($total)
            . ' _(база ' . self::humanize($this->baseMinutes * $quantity)
            . ', −' . $this->savedPercent() . '%)_'
            . "\n     ускоряют: " . implode(' · ', $labels);
    }
}
