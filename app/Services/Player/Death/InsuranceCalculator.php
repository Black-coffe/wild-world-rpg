<?php

declare(strict_types=1);

namespace App\Services\Player\Death;

use DateTime;

/**
 * F2.8 — чистая функция стоимости страховки.
 *
 * Извлечена из DeathService::calculateInsuranceCost (строки 383-414
 * legacy v0.4.0). Формула:
 *   resourceCost   = (totalResources / 1000) * 10
 *   timeCost       = (monthsInGame / 12) * 5
 *   levelCost      = (level / 10) * 20
 *   experienceCost = (experience / 10) * 5
 *   attributesCost = ((str+agi+int+tired) / 100) * 10
 *   goldCost       = (goldInChest / 10000) * 50
 *   total = sum() round to int
 *
 * Чистая функция (только арифметика + DateTime), легко тестируется.
 * Зависимости (totalResources, monthsInGame) принимает на вход —
 * считает их caller через свои Models.
 */
final class InsuranceCalculator
{
    /**
     * @param array<string,mixed> $character row из characters (level, experience,
     *                                       strength, agility, intellect, tired,
     *                                       gold, created_at).
     * @param int $totalResources           Кол-во записей в character_resources
     *                                       (caller считает через countAllResults).
     */
    public function calculate(array|\App\Entities\CharacterEntity $character, int $totalResources): int
    {
        // v0.51.121 hotfix: cast Time|null|string → string. CI4 Entity wraps
        // `created_at` як Time object (per F1.4.4-B v0.48.0 dates array).
        // Prior code passed Time directly до `new DateTime(...)` → fatal:
        // "DateTime::__construct(): Argument #1 must be of type string,
        // CodeIgniter\\I18n\\Time given". Latent since v0.48.0 — surfaced
        // 2026-05-08 19:36 коли AutoPveHandler triggered death+insurance.
        $createdAtRaw = $character['created_at'] ?? '1970-01-01';
        $createdAt    = $createdAtRaw instanceof \DateTimeInterface
            ? $createdAtRaw->format('Y-m-d H:i:s')
            : (string) $createdAtRaw;
        $monthsInGame = (new DateTime($createdAt))
            ->diff(new DateTime())
            ->m + 1;

        $level      = (int) ($character['level']      ?? 0);
        $experience = (int) ($character['experience'] ?? 0);
        $strength   = (int) ($character['strength']   ?? 0);
        $agility    = (int) ($character['agility']    ?? 0);
        $intellect  = (int) ($character['intellect']  ?? 0);
        $tired      = (int) ($character['tired']      ?? 0);
        $goldInChest= (int) ($character['gold']       ?? 0);

        $resourceCost    = ($totalResources / 1000) * 10;
        $timeCost        = ($monthsInGame   / 12)   * 5;
        $levelCost       = ($level          / 10)   * 20;
        $experienceCost  = ($experience     / 10)   * 5;
        $attributesCost  = (($strength + $agility + $intellect + $tired) / 100) * 10;
        $goldCost        = ($goldInChest    / 10000) * 50;

        $totalCost = $resourceCost
                   + $timeCost
                   + $levelCost
                   + $experienceCost
                   + $attributesCost
                   + $goldCost;

        return (int) round($totalCost);
    }
}
