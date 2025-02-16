<?php

namespace App\Services\Interfaces;

/**
 * Интерфейс для всех боевых сервисов
 */
interface BattleServiceInterface
{
    public function startFight(array $player, array $npc): array;
}

/**
 * Интерфейс для расчета урона
 */
interface DamageServiceInterface
{
    public function calculateDamage(array $attacker, array $defender, string $biome): float;
}

/**
 * Интерфейс для наград
 */
interface RewardServiceInterface
{
    public function grantRewards(array $player, array $npc): void;
}
