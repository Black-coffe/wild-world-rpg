<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Entities\BattleCharacter;
use Config\GameBalance;
use Psr\Log\LoggerInterface;

class BattleService
{
    private DamageService $damageService;
    private EffectService $effectService;
    private BattleLogger $battleLogger;
    private LoggerInterface $logger;
    private GameBalance $cfg;

    /**
     * F2.10 wire-in (v0.51.3): pveMaxRounds читается из config('GameBalance')
     * вместо private const MAX_ROUNDS = 100.
     *
     * Также v0.51.3 cleanup:
     * - удалены 3 dead private methods: npcShouldFlee / npcShouldPanic / shouldEnterRageMode
     *   (никогда не вызывались, scaffolding для boss/panic mechanic, не реализовано).
     * - удалены 4 dead consts: PANIC_THRESHOLD / ESCAPE_CHANCE_LOW_HP /
     *   ESCAPE_CHANCE_HIGH_DAMAGE / RAGE_HP_THRESHOLD (без референсов после
     *   удаления методов).
     */
    public function __construct(
        DamageService $damageService,
        EffectService $effectService,
        BattleLogger $battleLogger,
        LoggerInterface $logger,
        ?GameBalance $cfg = null
    ) {
        $this->damageService = $damageService;
        $this->effectService = $effectService;
        $this->battleLogger = $battleLogger;
        $this->logger = $logger;
        $this->cfg = $cfg ?? config('GameBalance');
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

        while ($player->health > 0 && $npc->health > 0 && $round < $this->cfg->pveMaxRounds) {
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
}
