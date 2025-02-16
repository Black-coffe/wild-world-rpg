<?php

namespace App\Services\PVE;

use App\Entities\CharacterEntity;
use Psr\Log\LoggerInterface;

class RewardService
{
    private LoggerInterface $logger;

    private const WINNER_EXP_PERCENT = 0.05; // +5% к текущему опыту
    private const GOLD_DROP_MULTIPLIER = 0.1; // NPC дропает 10% от уровня в золоте

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Выдаёт награды победителю
     */
    public function grantRewards(CharacterEntity $winner, CharacterEntity $loser): array
    {
        $expGained = max(5, round($winner->experience * self::WINNER_EXP_PERCENT));
        $goldGained = max(10, round($loser->level * 0.15 * 100)); // 🔥 Было 0.1, теперь 0.15

        $winner->experience += $expGained;
        $winner->gold += $goldGained;

        return [
            'exp' => $expGained,
            'gold' => $goldGained
        ];
    }

}
