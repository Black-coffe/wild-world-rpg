<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-200 — пассивный урон не убивает.
 *
 * Источники, которые игрок в этот момент не выбирал (мировые события, суточный голод), опускают здоровье
 * не ниже пола `health.passive_floor` (GameSettings, дефолт 1.0) — выше порога рулетки смерти
 * (`DeathRouletteHandler`: `health <= 0.99`). Здоровье, уже стоящее ниже пола (после похода, шага в опасный
 * биом), пассивный урон не меняет и не поднимает. Лечение не трогается.
 *
 * Выбранный риск (поход, шаги, бой) идёт мимо этого класса и остаётся смертельным.
 * Яд держит свой пол 1.0 в `DebuffService` (ADR-169).
 */
class PassiveHealthFloor
{
    public const KEY = 'health.passive_floor';

    /** Фолбэк без таблицы `game_settings`; совпадает с посевом `SeedPassiveFloorSettings`. */
    public const DEFAULT = 1.0;

    /**
     * Жёсткий минимум пола — инвариант ADR-200, а не число баланса: ниже него пол попадает в рулетку смерти
     * (0.99) и правило «пассивное не убивает» перестаёт держаться. Тот же `hard_min` у ключа в админке.
     */
    public const HARD_MIN = 1.0;

    public function __construct(private ?GameSettingsService $settings = null)
    {
    }

    public function floor(): float
    {
        $this->settings ??= new GameSettingsService();
        $value = $this->settings->get(self::KEY, self::DEFAULT);

        return max(self::HARD_MIN, is_numeric($value) ? (float) $value : self::DEFAULT);
    }

    /**
     * Здоровье после пассивного урона.
     *
     * @param float $current здоровье до удара (свежее, под блокировкой строки)
     * @param float $target  здоровье, которое дал бы удар без пола
     * @param float $floor   пол {@see floor()}
     */
    public static function apply(float $current, float $target, float $floor): float
    {
        if ($target >= $current) {
            return $target; // лечение или ноль — как было
        }
        if ($current <= $floor) {
            return $current; // уже на полу или ниже — не добиваем и не поднимаем
        }

        return max($floor, $target);
    }
}
