<?php

namespace App\Services\PVE;

use App\Entities\CharacterEntity;
use Psr\Log\LoggerInterface;

class BattleService
{
    private DamageService $damageService;
    private EffectService $effectService;
    private BattleLogger $battleLogger;
    private LoggerInterface $logger;

    private const MAX_ROUNDS = 100;
    private const PANIC_THRESHOLD = 10; // HP, при котором NPC паникует
    private const ESCAPE_CHANCE_LOW_HP = 40; // % шанс убежать, если HP < 30%
    private const ESCAPE_CHANCE_HIGH_DAMAGE = 50; // % шанс убежать, если урон > 40% HP за 1 ход
    private const RAGE_HP_THRESHOLD = 20; // % HP, при котором босс входит в ярость

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
     * Запускает бой между игроком и NPC
     */
    public function startFight(CharacterEntity $player, CharacterEntity $npc, string $biome): array
    {
        $round = 0;
        $battleLog = [];

        // Добавляем переменную для "первого атакующего"
        $firstAttackerName = null;

        while ($player->health > 0 && $npc->health > 0 && $round < self::MAX_ROUNDS) {
            $round++;

            // Определяем атакующего (каждый ход атакуют оба, но по очереди)
            $attacker = ($round % 2 === 0) ? $npc : $player;
            $defender = ($attacker === $player) ? $npc : $player;

            // Если это первый раунд, запоминаем атакующего
            if ($round === 1) {
                $firstAttackerName = $attacker->name;
            }

            // Рассчитываем урон
            $damage = $this->damageService->calculateDamage($attacker, $defender, $biome);

            // Применяем урон
            $defender->health = max(0, $defender->health - $damage);

            // Логируем
            $battleLog[] = [
                'round'          => $round,
                'attacker'       => $attacker->name,
                'defender'       => $defender->name,
                'damage'         => round($damage, 2),
                'defender_health'=> round($defender->health, 2),
            ];

            if ($defender->health <= 0) {
                // Победа
                return [
                    'winner'        => $attacker,
                    'loser'         => $defender,
                    'rounds'        => $round,
                    'log'           => $battleLog,
                    'firstAttacker' => $firstAttackerName,  // <-- ВАЖНО
                ];
            }
        }

        // Если вышли из цикла по лимиту
        return [
            'winner'        => null,
            'loser'         => null,
            'rounds'        => $round,
            'log'           => $battleLog,
            'firstAttacker' => $firstAttackerName,
        ];
    }

    private function npcShouldFlee(CharacterEntity $npc): bool
    {
        if ($npc->isBoss) {
            return false; // 🔥 Боссы не убегают
        }

        if ($npc->health < 10 && rand(1, 100) <= 80) { // 🔥 Теперь убегает только при HP < 10
            return true;
        }

        return false;
    }

    private function npcShouldPanic(CharacterEntity $npc): bool
    {
        if ($npc->health < 20 && rand(1, 100) <= 50) { // 🔥 Было 85%
            return true;
        }

        return false;
    }


    /**
     * Определяет, должен ли босс перейти в режим "Ярость"
     */
    private function shouldEnterRageMode(CharacterEntity $npc): bool
    {
        return $npc->isBoss && $npc->health < self::RAGE_HP_THRESHOLD;
    }
}
