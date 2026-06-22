<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Entities\BattleCharacter;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Psr\Log\LoggerInterface;

class DamageService
{
    use GameSettingsReaderTrait;

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

        // WB1 (ADR-137 «Узлы»): в бою с участием босса-узла клампим разницу уровней по
        // combat.nodes.leveldiff_cap. Без капа узел L150+ уаншотает игрока в первом раунде
        // и многоходовый бой с отступлением (ядро механики) невозможен. Гейт по isBoss →
        // обычный PvE/PvP байт-идентичен (cap даже не читается из game_settings).
        $levelDelta = $attacker->level - $defender->level;
        if ($attacker->isBoss || $defender->isBoss) {
            $cap        = $this->gsInt('combat.nodes.leveldiff_cap', 30);
            $levelDelta = max(-$cap, min($cap, $levelDelta));
        }
        $levelDifference = $levelDelta * 0.02;
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
            // WB6 (ADR-137 «Узлы»): в бою с узлом-боссом этот «бутстрап слабого атакующего»
            // НЕ должен ОСЛАБлять (множитель <1). Иначе высокоуровневый игрок с низкими str/agi
            // против слабого (южного L1-5) узла получал множитель <0 → ОТРИЦАТЕЛЬНЫЙ урон → HP
            // узла РОС, бой невыигрываем. Гейт по isBoss → обычный PvE байт-идентичен (бутстрап-
            // штраф для не-боссов сохранён, см. DamageServiceTest::...BootstrapHighLevelAttacker).
            if ($attacker->isBoss || $defender->isBoss) {
                $multiplier = max(1.0, $multiplier);
            }
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
