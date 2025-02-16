<?php

namespace App\Services\PVE;

use App\Entities\CharacterEntity;
use Psr\Log\LoggerInterface;

class EquipmentService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Применяет бонусы от экипированного оружия и брони
     */
    public function applyEquipmentBonuses(CharacterEntity $character): void
    {
        $weapon = $character->getEquippedItem('weapon');
        $armor = $character->getEquippedItem('body');

        if ($weapon) {
            log_message('info', sprintf("⚔️ %s экипировал %s (+%.2f урона)", $character->name, $weapon['name'], $weapon['damage_value']));
        }

        if ($armor) {
            log_message('info', sprintf("🛡 %s надел %s (+%.2f брони)", $character->name, $armor['name'], $armor['armor_value']));
        }
    }

}
