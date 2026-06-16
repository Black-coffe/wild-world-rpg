<?php

declare(strict_types=1);

namespace App\Services\Player\Death;

/**
 * F2.8 — чистая функция выбора штрафа смерти.
 *
 * Извлечена из DeathService::handlePlayerDeathAndReward (строки 39-61
 * legacy v0.4.0). Логика:
 *   - страховка сработала (платное списание) → 0% потерь
 *   - страховка НЕ сработала + есть база → 3% потерь
 *   - страховка НЕ сработала + нет базы  → 50% потерь
 *
 * E31 (ADR-131): анти-фрустрация — при $newbie-конфиге (из GameSettings, передаётся вызывающим)
 * и level в окне (0, level_max] возвращаем СНИЖЕННЫЕ потери новичку. $newbie=null или level_max=0
 * → dormant (легаси 3%/50%, byte-identical). Страховка всегда приоритетнее.
 *
 * Чистая (без БД, без I/O — конфиг передаётся аргументом), тестируется без mock'ов.
 */
final class DeathPenaltyCalculator
{
    /**
     * @param bool                     $insuranceCovered страховка сработала (хватило золота).
     * @param bool                     $hasBase          есть claimed_cell со статусом 'active'.
     * @param int                      $level            уровень погибшего (для newbie-смягчения).
     * @param array{level_max:int,penalty_no_base:float,penalty_with_base:float}|null $newbie
     *        конфиг смягчения новичкам (из GameSettings) или null = dormant (легаси).
     */
    public function decide(bool $insuranceCovered, bool $hasBase, int $level = 0, ?array $newbie = null): float
    {
        if ($insuranceCovered) {
            return 0.0;
        }

        // E31 — newbie-смягчение: level в окне (0, level_max] → сниженные потери.
        if ($newbie !== null && $level > 0 && $level <= $newbie['level_max']) {
            return $hasBase ? $newbie['penalty_with_base'] : $newbie['penalty_no_base'];
        }

        return $hasBase ? 0.03 : 0.50;
    }
}
