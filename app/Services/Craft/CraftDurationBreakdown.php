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
     * @param int|null $flooredAt ADR-159: пол, который срезал сжатие (null — пол не сработал)
     */
    public function __construct(
        public readonly int $minutes,
        public readonly int $baseMinutes,
        public readonly array $factors,
        public readonly ?int $flooredAt = null
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
            return '⏱ ' . self::humanize($total) . $this->floorNote();
        }

        $labels = [];
        foreach ($this->factors as $factor) {
            $labels[] = $factor['label'];
        }

        return '⏱ ' . self::humanize($total)
            . ' _(база ' . self::humanize($this->baseMinutes * $quantity)
            . ', −' . $this->savedPercent() . '%)_'
            . "\n     ускоряют: " . implode(' · ', $labels)
            . $this->floorNote();
    }

    /**
     * ADR-159: когда пол срезал сжатие, молчать нельзя. Иначе игрок с полным стеком
     * бонусов видит время, которое «не улучшается», и читает это как поломку своих
     * вложений (тот же класс, что невидимый множитель в ADR-158). Говорим прямо:
     * ускорение упёрлось в минимум, а не пропало.
     */
    private function floorNote(): string
    {
        if ($this->flooredAt === null) {
            return '';
        }

        // Рецепт короче настроенного пола: эффективный предел — его собственная база.
        // Печатать «минимум 7 мин» нельзя — число гуляло бы от рецепта к рецепту и
        // читалось как глобальное правило игры (поймано Tier-3 на седативном).
        if ($this->flooredAt >= $this->baseMinutes) {
            return "\n     дальше не ускорить: этот рецепт и так самый быстрый";
        }

        return "\n     дальше не ускорить: минимум " . self::humanize($this->flooredAt) . ' на штуку';
    }
}
