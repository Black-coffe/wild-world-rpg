<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;

/**
 * v0.51.105 (GatherTaskHandler decomp Step 2) — extract DB persistence
 * блок (character_resources insert/update + character stats bump) у
 * dedicated final class.
 *
 * persist() ortografує full sequence: resource quantity persist + stat gains.
 * Stat gains formula: per resource +0.0006 strength + 0.0001 agility/intellect
 * + 0.0005 health (capped @ 100).
 */
final class GatherResultPersister
{
    public function __construct(
        private ?CharacterModel $characterModel = null,
        private ?CharacterResourceModel $characterResourceModel = null
    ) {
        $this->characterModel         = $characterModel ?? new CharacterModel();
        $this->characterResourceModel = $characterResourceModel ?? new CharacterResourceModel();
    }

    /**
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     * @param array<string, mixed>|\App\Entities\CharacterEntity              $character
     * @param array<string, mixed>                                            $task         from character_tasks
     */
    public function persist(array $foundResources, array|\App\Entities\CharacterEntity $character, array $task): void
    {
        if (!empty($foundResources)) {
            $this->writeCharacterResources($foundResources, $character);
        }
        $this->bumpCharacterStats($character, $foundResources);
    }

    /**
     * v0.51.33 perf: 1 whereIn для существуючих rows замість N SELECT у loop'і.
     *
     * @param array<int, array{resource_id: int, amount: int, type?: string}> $foundResources
     * @param array<string, mixed>|\App\Entities\CharacterEntity              $character
     */
    private function writeCharacterResources(array $foundResources, array|\App\Entities\CharacterEntity $character): void
    {
        $resourceIds = array_values(array_unique(array_map(
            static fn ($r): int => (int) $r['resource_id'],
            $foundResources
        )));
        $existingRows = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->whereIn('id_resources', $resourceIds)
            ->findAll();
        $existingByResId = [];
        foreach ($existingRows as $row) {
            $existingByResId[(int) $row['id_resources']] = $row;
        }

        foreach ($foundResources as $res) {
            $amount = (int) $res['amount'];
            if ($amount < 1) {
                continue;
            }

            $existingResource = $existingByResId[(int) $res['resource_id']] ?? null;
            if ($existingResource) {
                $newQuantity = $existingResource['quantity'] + $amount;
                $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
            } else {
                $this->characterResourceModel->insert([
                    'id_characters'     => $character['id'],
                    'id_resources'      => $res['resource_id'],
                    'id_telegram_users' => $character['telegram_user_id'],
                    'quantity'          => $amount,
                ]);
            }
        }
    }

    /**
     * Per-resource stat gain: +0.0006 str + 0.0001 agi/int + 0.0005 health (capped @ 100).
     * expGain currently unused (stays at 0) — preserved для backward-compat.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     * @param array<int, mixed>                                  $foundResources
     */
    private function bumpCharacterStats(array|\App\Entities\CharacterEntity $character, array $foundResources): void
    {
        $expGain     = 0;
        $healthGain  = 0.0;
        $strength    = 0.0;
        $agility     = 0.0;
        $intellect   = 0.0;

        foreach ($foundResources as $res) {
            $strength   += 0.0006;
            $agility    += 0.0001;
            $intellect  += 0.0001;
            $healthGain += 0.0005;
        }

        $newHealth = min(100, $character['health'] + $healthGain);

        $updatedData = [
            'experience' => $character['experience'] + $expGain,
            'health'     => $newHealth,
            'strength'   => $character['strength'] + $strength,
            'agility'    => $character['agility'] + $agility,
            'intellect'  => $character['intellect'] + $intellect,
        ];

        $this->characterModel->update($character['id'], $updatedData);
    }
}
