<?php

namespace App\Services\Player\Gather;

use DateTime;

/**
 * F2.7 — чистые формулы добычи ресурсов.
 *
 * Извлечено из GatherTaskHandler v0.5.0:
 *   - getAllowedRarities       (level → list rarity int)
 *   - getBaseQuantityByRarity  (rarity → base/10min int)
 *   - getHealthTirednessFactor (character → 0.1..2.0 multiplier)
 *   - calculateSpentMinutes    (DateTime diff)
 *
 * Все методы pure (без БД, без I/O), легко тестируются.
 *
 * Источник истины — `lore/admin/Tips-categories.md` формула:
 *   foundResources = resources × time_factor × difficulty_factor × random_multiplier
 *
 * Полная decomp F2.7 — оставшиеся методы (event modifiers, tool selection,
 * resource save, notify) выносим в отдельные сервисы в F2.7b.
 */
final class GatherFormulaService
{
    /**
     * По уровню персонажа определяет какие rarity он может добывать.
     * Чем выше level — тем более редкие ресурсы доступны.
     */
    public function getAllowedRarities(int $level): array
    {
        if ($level <= 1)  return [10];
        if ($level <= 2)  return [9, 10];
        if ($level <= 3)  return [8, 9, 10];
        if ($level <= 4)  return [7, 8, 9, 10];
        if ($level <= 5)  return [6, 7, 8, 9, 10];
        if ($level <= 6)  return [5, 6, 7, 8, 9, 10];
        if ($level <= 7)  return [4, 5, 6, 7, 8, 9, 10];
        if ($level <= 8)  return [3, 4, 5, 6, 7, 8, 9, 10];
        if ($level <= 9)  return [2, 3, 4, 5, 6, 7, 8, 9, 10];
        return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
    }

    /**
     * Базовое количество ресурса rarity X за блок 10 минут.
     */
    public function getBaseQuantityByRarity(int $rarity): int
    {
        return match ($rarity) {
            10 => 60,
            9  => 58,
            8  => 50,
            7  => 38,
            6  => 25,
            5  => 18,
            4  => 10,
            3  => 5,
            2  => 3,
            1  => 2,
            default => 0,
        };
    }

    /**
     * Multiplier по здоровью + усталости персонажа.
     * Формула: 1 + 0.125 × ((health-50)/50 + (tired-50)/50)
     * Зажат в [0.1, 2.0].
     *
     * health=100 + tired=100 → 1 + 0.125×2 = 1.25
     * health=50  + tired=50  → 1.0
     * health=0   + tired=0   → 0.1 (clamped)
     */
    public function getHealthTirednessFactor(array $character): float
    {
        $healthVal = ((float) $character['health'] - 50) / 50.0;
        $tiredVal  = ((float) $character['tired']  - 50) / 50.0;

        $factor = 1 + (0.125 * ($healthVal + $tiredVal));

        return max(0.1, min(2.0, $factor));
    }

    /**
     * Минут между start и end. Используется для расчёта блоков
     * (10-минутки + остаток).
     */
    public function calculateSpentMinutes(string $startTime, string $endTime): int
    {
        $start = new DateTime($startTime);
        $end   = new DateTime($endTime);
        $diff  = $start->diff($end);

        $minutes  = $diff->days * 24 * 60;
        $minutes += $diff->h * 60;
        $minutes += $diff->i;

        return $minutes;
    }
}
