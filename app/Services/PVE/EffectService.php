<?php

namespace App\Services\PVE;

use App\Entities\CharacterEntity;
use Psr\Log\LoggerInterface;

class EffectService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Применяет все активные эффекты к персонажу
     */
    public function applyEffects(CharacterEntity $character): void
    {
        if ($character->tired < 30) {
            $this->logger->warning("⚠️ {$character->name} устал! Урон снижен.");
            $character->damageValue *= 0.9;
            $character->attackSpeed *= 0.85;
        }

        if ($character->health < 20) {
            $this->logger->warning("💀 {$character->name} ранен! Скорость атаки падает.");
            $character->attackSpeed *= 0.8;
        }

        $armor = $character->getEquippedItem('body');
        if ($armor && isset($armor['special_bonus'])) {
            if ($armor['special_bonus'] === 'damage_reduction') {
                $character->armor += 5;
                $this->logger->info("🛡 {$character->name} получил бонус к броне (+5).");
            }
        }
    }
}
