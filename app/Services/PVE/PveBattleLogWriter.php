<?php

declare(strict_types=1);

namespace App\Services\PVE;

/**
 * v0.51.88 (PvEService decomp Step 3) — extract battle log writing
 * (init + per-round + outcome + save) у dedicated writer service.
 *
 * PveBattleLogService stateful per-battle (init/addRoundLog/setOutcome/saveLog),
 * тому writer створює свіжий instance на кожен `write()` call (mirroring
 * existing inline behavior у PvEService).
 */
final class PveBattleLogWriter
{
    /**
     * @param array<string, mixed>|\App\Entities\CharacterEntity $playerData
     * @param array<string, mixed> $npcData
     * @param array<string, mixed> $fightResult expected keys: log[], winner (object), loser (object|null)
     */
    public function write(array|\App\Entities\CharacterEntity $playerData, array $npcData, array $fightResult): void
    {
        $service = new PveBattleLogService();
        $service->init($playerData, $npcData);

        $roundNumber = 1;
        if (isset($fightResult['log']) && is_array($fightResult['log'])) {
            foreach ($fightResult['log'] as $round) {
                $service->addRoundLog(
                    $roundNumber,
                    $round['attacker'] ?? '',
                    $round['defender'] ?? '',
                    $round['base_damage'] ?? 0,
                    $round['level_difference'] ?? 0,
                    $round['strength_bonus'] ?? 0,
                    $round['agility_bonus'] ?? 0,
                    $round['total_armor'] ?? 0,
                    $round['armor_effect'] ?? 0,
                    $round['final_damage'] ?? 0,
                    $round['defender_health_after'] ?? 0,
                    $round['luckyStrike'] ?? false
                );
                $roundNumber++;
            }
        }

        $winner = $fightResult['winner'];
        $loser  = $fightResult['loser'] ?? null;

        $service->setOutcome([
            'type'     => 'normal',
            'winnerId' => (int) $winner->id,
            'loserId'  => is_object($loser) ? (int) $loser->id : null,
        ]);
        $service->saveLog(
            'PVE',
            (int) $playerData['id'],
            isset($npcData['npc_id']) ? (int) $npcData['npc_id'] : null,
            (int) $winner->id
        );
    }
}
