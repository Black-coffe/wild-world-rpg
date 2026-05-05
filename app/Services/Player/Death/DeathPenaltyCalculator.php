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
 * Чистая (без БД, без I/O), тестируется без mock'ов.
 */
final class DeathPenaltyCalculator
{
    /**
     * @param bool $insuranceCovered страховка сработала (хватило золота на оплату).
     * @param bool $hasBase           у персонажа есть claimed_cell со статусом 'active'.
     */
    public function decide(bool $insuranceCovered, bool $hasBase): float
    {
        if ($insuranceCovered) {
            return 0.0;
        }
        return $hasBase ? 0.03 : 0.50;
    }
}
