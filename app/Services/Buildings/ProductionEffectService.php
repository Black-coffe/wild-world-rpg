<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\GameBalance;

/**
 * ADR-155, слайс 3 контент-паса L1→L10 — правдивая строка «что даёт ИМЕННО ТВОЯ постройка».
 *
 * Три постройки в игре реально что-то производят по кроновой формуле: Спортзал (сила),
 * Ручная скважина (вода) и Теплица (урожай). До этой правки владелец видел на экране только
 * рукописное описание из БД — общее для всех уровней и в случае Спортзала **завышавшее отдачу
 * в 6 раз** («каждые 5ть минут» против фактических 30).
 *
 * Сервис считает отдачу ДЛЯ УРОВНЯ КОНКРЕТНОЙ постройки из тех же источников, что использует
 * крон ({@see \App\TaskHandlers\Built\GymProductionHandler},
 * {@see \App\TaskHandlers\Built\HandPumpProductionHandler},
 * {@see \App\TaskHandlers\GreenhouseProductionHandler}): `Config\GameBalance` + live-значения
 * GameSettings. Разъехаться с игрой такая строка не может по построению — в этом и смысл
 * (правило «числа в текст из кода, а не руками»).
 *
 * Read-only, без БД. Самодостаточно в тексте (media-off, ADR-020).
 */
class ProductionEffectService
{
    use GameSettingsReaderTrait;

    private GameBalance $cfg;

    public function __construct(?GameBalance $cfg = null)
    {
        if ($cfg === null) {
            $resolved = config('GameBalance');
            $cfg      = $resolved instanceof GameBalance ? $resolved : new GameBalance();
        }
        $this->cfg = $cfg;
    }

    /**
     * Строка отдачи по ключу постройки (`Config\Buildings`) и её уровню.
     * Постройка без производственной формулы → null (её описание качественное, считать нечего).
     */
    public function lineFor(string $nameEn, int $level): ?string
    {
        return match ($nameEn) {
            'Gym'        => $this->gymLine($level),
            'HandPump'   => $this->handPumpLine($level),
            'Greenhouse' => $this->greenhouseLine($level),
            default      => null,
        };
    }

    /** Спортзал: прибавка силы за тик + сколько это в сутки. */
    public function gymLine(int $level): ?string
    {
        $base = $this->cfg->gymStrengthByLevel[$level] ?? null;
        if (! is_numeric($base)) {
            return null;
        }
        // Множитель отдачи (ADR-156) — тот же, что применяет крон: строка не имеет права
        // разойтись с начислением, иначе снова обещание мимо факта.
        $perTick  = ((float) $base) * $this->gsFloat('building.gym.strength_multiplier', 1.0);
        $interval = max(1, $this->gsInt('building.gym.tick_interval_minutes', (int) $this->cfg->gymTickIntervalMinutes));
        $perDay   = $perTick * (1440 / $interval);

        return sprintf(
            '⚡ *Даёт сейчас:* +%s силы каждые %d мин — это примерно %s силы в сутки',
            $this->num($perTick),
            $interval,
            $this->num($perDay)
        );
    }

    /** Ручная скважина: вода за минуту (биом-множитель называем словами, он зависит от клетки). */
    public function handPumpLine(int $level): ?string
    {
        $perMinute = $this->cfg->handPumpLevels[$level] ?? null;
        if ($perMinute === null) {
            return null;
        }

        return sprintf(
            '⚡ *Даёт сейчас:* %d воды в минуту (примерно %d в сутки); в сухих биомах меньше, во влажных больше',
            $perMinute,
            $perMinute * 1440
        );
    }

    /** Теплица: урожай за минуту и за сутки + расход воды (главное, что съедает запасы). */
    public function greenhouseLine(int $level): ?string
    {
        $row = $this->cfg->greenhouseLevels[$level] ?? null;
        if (! is_array($row)) {
            return null;
        }

        $water = $row['water'] ?? 0;

        $parts = [];
        foreach (self::HARVEST_LABELS as $key => $label) {
            $amount = $row[$key] ?? 0;
            if ($amount > 0) {
                $parts[] = ($amount * 1440) . ' ' . $label;
            }
        }
        if ($parts === []) {
            return null;
        }

        return sprintf(
            '⚡ *Даёт сейчас:* за сутки примерно %s — пока хватает воды (расход %d в сутки)',
            implode(', ', $parts),
            $water * 1440
        );
    }

    /** Названия урожая для текста (ключи — как в GameBalance::greenhouseLevels). */
    private const HARVEST_LABELS = [
        'Fruit'     => 'фруктов',
        'Berries'   => 'ягод',
        'Mushrooms' => 'грибов',
        'Crops'     => 'злаков',
    ];

    /** Число без хвостовых нулей: 0.01 → «0.01», 1.90 → «1.9», 3.0 → «3». */
    private function num(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
