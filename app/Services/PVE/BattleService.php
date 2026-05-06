<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Entities\BattleCharacter;
use Psr\Log\LoggerInterface;

class BattleService
{
    private DamageService $damageService;
    private EffectService $effectService;
    private BattleLogger $battleLogger;
    private LoggerInterface $logger;

    private const MAX_ROUNDS = 100;
    private const PANIC_THRESHOLD = 10;
    private const ESCAPE_CHANCE_LOW_HP = 40;
    private const ESCAPE_CHANCE_HIGH_DAMAGE = 50;
    private const RAGE_HP_THRESHOLD = 20;

    public function __construct(
        DamageService $damageService,
        EffectService $effectService,
        BattleLogger $battleLogger,
        LoggerInterface $logger
    ) {
        $this->damageService = $damageService;
        $this->effectService = $effectService;
        $this->battleLogger = $battleLogger;
        $this->logger = $logger;
    }

    /**
     * Запускает бой между игроком и NPC с подробным логированием каждого раунда.
     *
     * @param BattleCharacter $player
     * @param BattleCharacter $npc
     * @param string $biome
     * @return array Итог боя, подробный лог, победитель, проигравший, номер раундов и имя первого атакующего.
     */
    public function startFight(BattleCharacter $player, BattleCharacter $npc, string $biome): array
    {
        $round = 0;
        $battleLog = [];
        $firstAttackerName = null;

        while ($player->health > 0 && $npc->health > 0 && $round < self::MAX_ROUNDS) {
            $round++;
            // Чередование атак: в нечетном раунде атакует игрок, в четном – NPC.
            $attacker = ($round % 2 === 0) ? $npc : $player;
            $defender = ($attacker === $player) ? $npc : $player;

            if ($round === 1) {
                $firstAttackerName = $attacker->name;
            }

            // Сбор подробных параметров для логирования:
            $baseDamage = $attacker->damageValue;
            $levelDifference = ($attacker->level - $defender->level) * 0.02;
            $strengthBonus = $attacker->strength * 0.0025;
            $agilityBonus  = $attacker->agility * 0.0015;
            $totalArmor = $defender->armor + ($defender->armorBonus ?? 0);
            $armorEffect = $totalArmor / (100 + $totalArmor);

            // Вычисляем итоговый урон через DamageService
            $finalDamage = $this->damageService->calculateDamage($attacker, $defender, $biome);
            $defender->health = max(0, $defender->health - $finalDamage);

            // Формируем подробный лог раунда
            $roundLog = [
                'round'                => $round,
                'attacker'             => $attacker->name,
                'defender'             => $defender->name,
                'base_damage'          => $baseDamage,
                'level_difference'     => $levelDifference,
                'strength_bonus'       => $strengthBonus,
                'agility_bonus'        => $agilityBonus,
                'total_armor'          => $totalArmor,
                'armor_effect'         => $armorEffect,
                'final_damage'         => round($finalDamage, 2),
                'defender_health_after'=> round($defender->health, 2),
            ];
            $battleLog[] = $roundLog;

            // (Опционально можно вызвать $this->battleLogger->logRound($roundLog);)

            if ($defender->health <= 0) {
                return [
                    'winner'        => $attacker,
                    'loser'         => $defender,
                    'rounds'        => $round,
                    'log'           => $battleLog,
                    'firstAttacker' => $firstAttackerName,
                ];
            }
        }

        return [
            'winner'        => null,
            'loser'         => null,
            'rounds'        => $round,
            'log'           => $battleLog,
            'firstAttacker' => $firstAttackerName,
        ];
    }

    private function npcShouldFlee(BattleCharacter $npc): bool
    {
        if ($npc->isBoss) {
            return false;
        }
        if ($npc->health < 10 && rand(1, 100) <= 80) {
            return true;
        }
        return false;
    }

    private function npcShouldPanic(BattleCharacter $npc): bool
    {
        if ($npc->health < 20 && rand(1, 100) <= 50) {
            return true;
        }
        return false;
    }

    /**
     * Определяет, должен ли босс перейти в режим "Ярость".
     *
     * @param BattleCharacter $npc
     * @return bool
     */
    private function shouldEnterRageMode(BattleCharacter $npc): bool
    {
        return $npc->isBoss && $npc->health < self::RAGE_HP_THRESHOLD;
    }
}
