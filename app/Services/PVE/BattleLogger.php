<?php

namespace App\Services\PVE;

use Psr\Log\LoggerInterface;

class BattleLogger
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Логирует подробную информацию о каждом раунде боя.
     *
     * @param array $logEntry Массив с данными раунда.
     */
    public function logRound(array $logEntry): void
    {
        $message = "Раунд #{$logEntry['round']}: {$logEntry['attacker']} атакует {$logEntry['defender']}. " .
            "Базовый урон: {$logEntry['base_damage']}, " .
            "Разница уровней: {$logEntry['level_difference']}, " .
            "Бонус силы: {$logEntry['strength_bonus']}, " .
            "Бонус ловкости: {$logEntry['agility_bonus']}, " .
            "Общая броня: {$logEntry['total_armor']} (Эффект брони: {$logEntry['armor_effect']}), " .
            "Итоговый урон: {$logEntry['final_damage']}. " .
            "Оставшееся здоровье защитника: {$logEntry['defender_health_after']}.";
        $this->logger->info($message);
    }
}
