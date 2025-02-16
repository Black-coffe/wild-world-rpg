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
        $strengthBonus = $attacker->strength * 0.001;
        $agilityBonus = $attacker->agility * 0.0005;
        $armorEffect = $defender->armor / (100 + $defender->armor);

        // 🔹 **Добавляем "ярость" только для агрессивных NPC**
        if ($attacker instanceof CharacterEntity && $attacker->isRage && $attacker->health < ($attacker->maxHealth * 0.2)) {
            $rageMultiplier = 1.3; // 🔥 Увеличиваем урон на 30% (не 20%)
        } else {
            $rageMultiplier = 1.0;
        }
        $baseDamage *= $rageMultiplier;

        // 🔹 **Добавляем шанс уклонения (dodgeChance)**
        $dodgeChance = $defender->agility * 0.006; // 🔥 Чуть уменьшили шанс уклонения (раньше 0.01)
        if (rand(0, 100) < ($dodgeChance * 100)) {
            return 0; // Промах
        }

        // 🔹 **Добавляем блок, но ограничиваем его повторения**
        $blockChance = $defender->agility * 0.005; // 🔥 Увеличили шанс блока
        if (rand(0, 100) < ($blockChance * 100)) {
            static $lastBlocked = false; // Запоминаем, был ли блок в прошлом раунде
            if ($lastBlocked) {
                $lastBlocked = false; // 🔥 Если был блок в прошлом раунде, теперь он не сработает
            } else {
                $lastBlocked = true;
                return 0; // Полный блок урона
            }
        }

        // 🔹 **Добавляем критический урон (x1.5, x2, x2.5)**
        $critChance = $attacker->agility * 0.005;
        $critMultipliers = [1.2, 1.5, 2.0, 2.5];
        $critMultiplier = 1.0; // 🔥 Теперь переменная всегда определена!

        if (rand(1, 100) < ($critChance * 100)) {
            $critMultiplier = $critMultipliers[random_int(0, count($critMultipliers) - 1)];
        }

        // 🔹 **Добавляем случайные колебания урона**
        $randomFactor = rand(95, 105) / 100; // 🔥 Уменьшили колебания урона (раньше 90-110)
        $baseDamage *= $randomFactor;

        // 🔹 **Финальный расчёт урона**
        $finalDamage = $baseDamage * (1 + $levelDifference + $strengthBonus + $agilityBonus)
            * (1 - $armorEffect) * $critMultiplier;

        return max(0, round($finalDamage, 2));
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
