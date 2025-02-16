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
     * Логирует каждый раунд боя
     */
    public function logRound(array $logEntry): void
    {
        $this->logger->info(
            "⚔ Раунд #{$logEntry['round']}: {$logEntry['attacker']} атакует {$logEntry['defender']} — урон: {$logEntry['damage']}, остаток HP: {$logEntry['defender_health']}."
        );
    }
}
