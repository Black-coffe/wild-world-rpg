<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Entities\BattleCharacter;
use Psr\Log\LoggerInterface;

class DamageService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Рассчитывает итоговый урон, учитывая бонусы, разницу уровней и броню (с учетом бонуса).
     *
     * @param BattleCharacter $attacker
     * @param BattleCharacter $defender
     * @param string $biome
     * @return float
     */
    public function calculateDamage(BattleCharacter $attacker, BattleCharacter $defender, string $biome): float
    {
        $baseDamage = $attacker->damageValue;
        $levelDifference = ($attacker->level - $defender->level) * 0.02;
        $strengthBonus = $attacker->strength * 0.0025;
        $agilityBonus  = $attacker->agility * 0.0015;
        $totalArmor = $defender->armor + ($defender->armorBonus ?? 0);
        $armorEffect = $totalArmor / (100 + $totalArmor);

        $this->logger->debug("Расчет урона: {$attacker->name} бьет {$defender->name}. 
        Базовый урон: {$baseDamage}, 
        Сила: {$attacker->strength}, 
        Ловкость: {$attacker->agility}, 
        Разница уровней: {$levelDifference}, 
        Общая броня: {$totalArmor} (Эффект: {$armorEffect})");

        if ($attacker->strength < 5 && $attacker->agility < 5) {
            $multiplier = 1 + (1 - ($attacker->level / max(1, $defender->level))) * 0.5;
            $baseDamage *= $multiplier;
            $this->logger->warning("{$attacker->name} слишком слаб, добавляем динамическое усиление: x{$multiplier}");
        }

        $finalDamage = $baseDamage * (1 + $levelDifference + $strengthBonus + $agilityBonus) * (1 - $armorEffect);

        // E21 Ф1 (ADR-121) — боевой food-баф «Сытость». Множители default 1.0 (нейтрально):
        // сытый игрок-атакующий бьёт сильнее (outgoing), сытый защищающийся получает меньше
        // (incoming). Выставляются только игроку в PvEService::attack; NPC = 1.0 → byte-identical.
        $finalDamage *= $attacker->outgoingDamageMultiplier;
        $finalDamage *= $defender->incomingDamageMultiplier;

        $finalDamage = max($baseDamage * 0.5, round($finalDamage, 2));

        $this->logger->info("Урон, нанесённый {$attacker->name}: {$finalDamage}");
        return $finalDamage;
    }

    public function computeDamageRatio(float $difference): float
    {
        if ($difference > 100) {
            return 1.2;
        } elseif ($difference < -100) {
            return 0.8;
        }
        $ratio = 1 + ($difference / 400);
        return max(0.8, min(1.2, $ratio));
    }
}
