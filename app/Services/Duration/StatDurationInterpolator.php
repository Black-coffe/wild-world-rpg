<?php

declare(strict_types=1);

namespace App\Services\Duration;

use App\Entities\CharacterEntity;

/**
 * ADR-160 — общая математика «время задачи по статам персонажа».
 *
 * Формула жила двумя независимыми копиями в стройке (`GenericBuildingAction` и
 * `GenericBuildingInfoAction`) и третьей — в крафте (`CraftDurationService`). Копии
 * стройки **уже разъехались**: экран информации урезал статы до целых
 * (`(int) $character['agility']`), а сама стройка считала по сырым дробным.
 *
 * ⚠️ Масштаб того расхождения был мал (потерянная дробь сдвигает счёт < 1 при потолке
 * 2000 → доли минуты, т.е. лишь граница округления) — обещание «Строительство займёт
 * ~N минут» игроку почти никогда не врало. Ценность выноса не в этом, а в том, что
 * копии расходятся всегда: вопрос лишь когда и насколько.
 *
 * Это тот же класс, что лечил ADR-158 внутри крафта: две копии одной формулы всегда
 * расходятся, вопрос только когда.
 *
 * Смысл формулы: чем опытнее персонаж, тем ближе время к `min_duration` задачи;
 * новичок получает `max_duration`. Веса статов общие для всех подсистем, а «потолок
 * счёта» (при котором достигается минимум) — свой: крафт 1000, стройка 100 (ADR-162;
 * до калибровки 2000 — при нём стройка вообще не реагировала на статы).
 */
final class StatDurationInterpolator
{
    public const EXP_WEIGHT = 0.3;
    public const AGI_WEIGHT = 0.3;
    public const INT_WEIGHT = 0.4;

    /** Потолок счёта у крафта: 1000 × (сумма весов = 1.0). */
    public const CRAFT_SCORE_CAP = 1000.0;

    /**
     * Потолок счёта у стройки. **Был 2000 — калиброван до 100 в ADR-162.**
     *
     * При 2000 механика не работала вовсе: счёт статов на проде не превышал 115 у 659
     * персонажей (5.75% потолка), поэтому все стройки приходились на `max_duration`, а
     * заложенный в контент диапазон (Арсенал 60–260) не получал никто ни разу. 100 стоит
     * чуть ниже исторического рекорда счёта → быстрый край достижим верхушкой прогрессии.
     *
     * Это лишь fallback: живое значение — `buildings.duration.stat_score_cap` в GameSettings.
     * Константа обязана совпадать с дефолтом ключа, иначе «сломанная настройка» молча
     * возвращала бы поведение, которое мы только что признали неверным.
     */
    public const BUILD_SCORE_CAP = 100.0;

    /**
     * Взвешенный счёт статов. Веса намеренно ОДНИ для всех подсистем: разъехавшиеся
     * веса означали бы, что один и тот же прирост статов по-разному влияет на крафт и
     * стройку без всякой причины.
     *
     * @param array<array-key,mixed>|CharacterEntity $character
     */
    public static function score(array|CharacterEntity $character): float
    {
        return self::num($character['experience'] ?? 0) * self::EXP_WEIGHT
            + self::num($character['agility'] ?? 0) * self::AGI_WEIGHT
            + self::num($character['intellect'] ?? 0) * self::INT_WEIGHT;
    }

    /**
     * Минуты для задачи: обратная интерполяция между min и max по счёту статов.
     *
     * Ветеран (счёт ≥ потолка) получает `$minMinutes`, новичок (счёт 0) — `$maxMinutes`.
     * Результат всегда внутри `[min, max]` — кламп сохраняет прежнее поведение обеих
     * подсистем, включая порченые данные (`max < min` → отдаём `min`).
     *
     * @param array<array-key,mixed>|CharacterEntity $character
     */
    public static function minutes(
        array|CharacterEntity $character,
        int $minMinutes,
        int $maxMinutes,
        float $scoreCap
    ): int {
        $ratio = $scoreCap > 0.0
            ? min(1.0, max(0.0, self::score($character) / $scoreCap))
            : 1.0;

        // Форма выражения — как в крафте (`min + (max-min) * (1-ratio)`), она же
        // алгебраически равна прежней форме стройки (`max - (max-min) * ratio`);
        // совпадение после round() зафиксировано property-тестом.
        $interpolated = $minMinutes + ($maxMinutes - $minMinutes) * (1.0 - $ratio);

        return max($minMinutes, min($maxMinutes, (int) round($interpolated)));
    }

    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
