<?php

namespace App\Services\PVE;

use App\Entities\CharacterEntity;
use Psr\Log\LoggerInterface;

class DamageService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function calculateDamage(CharacterEntity $attacker, CharacterEntity $defender, string $biome): float
    {
        $baseDamage = $attacker->damageValue;
        $levelDifference = ($attacker->level - $defender->level) * 0.02;
        $strengthBonus = $attacker->strength * 0.0025; // 🔹 Усилили влияние силы
        $agilityBonus = $attacker->agility * 0.0015;  // 🔹 Усилили влияние ловкости
        $armorEffect = $defender->armor / (100 + $defender->armor);

        // 🔹 Логирование расчёта урона
        log_message('debug', "Расчет урона: {$attacker->name} бьет {$defender->name}. 
        Базовый урон: {$baseDamage}, 
        Сила: {$attacker->strength}, 
        Ловкость: {$attacker->agility}, 
        Разница уровней: {$levelDifference}, 
        Эффект брони: {$armorEffect}");

        // 🔹 Коррекция урона для слабых NPC
        if ($attacker->strength < 5 && $attacker->agility < 5) {
            $multiplier = 1 + (1 - ($attacker->level / max(1, $defender->level))) * 0.5; // 🔹 Масштабируем бонус урона
            $baseDamage *= $multiplier;
            log_message('warning', "{$attacker->name} слишком слаб, добавляем динамическое усиление: x{$multiplier}");
        }

        // 🔹 Применяем бонусы и броню
        $finalDamage = $baseDamage * (1 + $levelDifference + $strengthBonus + $agilityBonus) * (1 - $armorEffect);

        // 🔹 Минимальный урон всегда >= 50% от базового
        $finalDamage = max($baseDamage * 0.5, round($finalDamage, 2));

        log_message('info', "Урон, нанесённый {$attacker->name}: {$finalDamage}");

        return $finalDamage;
    }

    public function computeDamageRatio(float $difference): float
    {
        // Ограничиваем разницу до 1.2x максимум
        if ($difference > 100) {
            return 1.2;
        } elseif ($difference < -100) {
            return 0.8;
        }

        // Уменьшаем влияние разницы уровней (чтобы NPC не умирал сразу)
        $ratio = 1 + ($difference / 400);

        return max(0.8, min(1.2, $ratio)); // Ограничиваем от 0.8 до 1.2
    }
}
