<?php

declare(strict_types=1);

namespace App\Services\Player\Death;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;

/**
 * F2.8b — обработка имущества при смерти: списание у проигравшего +
 * передача части победителю.
 *
 * Извлечено из DeathService::handlePlayerDeathAndReward (строки 64-99 +
 * compute/apply/transfer методы legacy v0.5.0). Логика 1:1, обвязка та же:
 *
 *   compute   — чистые расчёты (тестируются unit-тестами)
 *   apply     — DB writes (списание у проигравшего)
 *   transfer  — DB writes (передача победителю)
 *
 * Models инжектируются через конструктор для testability.
 */
class LootProcessor
{
    private CharacterModel $characterModel;
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsLogModel $craftedItemsLogModel;

    public function __construct(
        ?CharacterModel $characterModel = null,
        ?CharacterResourceModel $characterResourceModel = null,
        ?CraftedItemsLogModel $craftedItemsLogModel = null
    ) {
        $this->characterModel         = $characterModel         ?? new CharacterModel();
        $this->characterResourceModel = $characterResourceModel ?? new CharacterResourceModel();
        $this->craftedItemsLogModel   = $craftedItemsLogModel   ?? new CraftedItemsLogModel();
    }

    // ---- compute (pure) ----

    /**
     * @param list<array{id:int,id_resources:int,quantity:int|string}> $loserResources
     * @return list<array{charResId:int,resourceId:int,lossAmount:int}>
     */
    public function computeResourceLoss(array $loserResources, float $deathPenalty): array
    {
        $lost = [];
        foreach ($loserResources as $res) {
            $oldQty = (int) $res['quantity'];
            if ($oldQty <= 0) {
                continue;
            }
            $lossAmount = (int) floor($oldQty * $deathPenalty);
            if ($lossAmount > 0) {
                $lost[] = [
                    'charResId'  => (int) $res['id'],
                    'resourceId' => (int) $res['id_resources'],
                    'lossAmount' => $lossAmount,
                ];
            }
        }
        return $lost;
    }

    /**
     * V24 (ADR-056): строки с `insured=1` пропускаются — селективный полис
     * защищает дорогие предметы (robots/workbench/transport). Killswitch
     * `craft_insurance.enabled` обрабатывается выше (caller не передаёт
     * insured-флаг если выключено).
     *
     * @param list<array{id:int,crafted_item_id:int,quantity:int|string,insured?:int|string}> $loserCraftedItems
     * @return list<array{logId:int,craftedItemId:int,lossAmount:int}>
     */
    public function computeCraftLoss(array $loserCraftedItems, float $deathPenalty): array
    {
        $lost = [];
        foreach ($loserCraftedItems as $item) {
            $insured = (int) ($item['insured'] ?? 0);
            if ($insured === 1) {
                continue;
            }
            $oldQty = (int) $item['quantity'];
            if ($oldQty <= 0) {
                continue;
            }
            $lossAmount = (int) floor($oldQty * $deathPenalty);
            if ($lossAmount > 0) {
                $lost[] = [
                    'logId'         => (int) $item['id'],
                    'craftedItemId' => (int) $item['crafted_item_id'],
                    'lossAmount'    => $lossAmount,
                ];
            }
        }
        return $lost;
    }

    // ---- apply (DB writes у проигравшего) ----

    /**
     * @param list<array{charResId:int,resourceId:int,lossAmount:int}> $lostResources
     */
    public function applyLosses(int $loserId, array $lostResources, int $lostGold): void
    {
        foreach ($lostResources as $lr) {
            $this->characterResourceModel->decreaseQtyById(
                $lr['charResId'],
                $lr['lossAmount']
            );
        }

        if ($lostGold > 0) {
            $loserRow = $this->characterModel->find($loserId);
            if ($loserRow) {
                $newGold = max(0, (int) $loserRow['gold'] - $lostGold);
                $this->characterModel->update($loserId, ['gold' => $newGold]);
            }
        }
    }

    /**
     * @param list<array{logId:int,craftedItemId:int,lossAmount:int}> $lostCraftedItems
     */
    public function applyCraftLosses(int $loserId, array $lostCraftedItems): void
    {
        foreach ($lostCraftedItems as $lc) {
            $row = $this->craftedItemsLogModel->find($lc['logId']);
            if (!$row) {
                continue;
            }
            $newQty = (int) $row['quantity'] - $lc['lossAmount'];
            if ($newQty <= 0) {
                $this->craftedItemsLogModel->delete($lc['logId']);
            } else {
                $this->craftedItemsLogModel->update($lc['logId'], ['quantity' => $newQty]);
            }
        }
    }

    // ---- transfer (DB writes — победителю) ----

    /**
     * @param list<array{charResId:int,resourceId:int,lossAmount:int}> $lostResources
     * @return list<array{resourceId:int,amount:int}>
     */
    public function transferResourcesToWinner(int $winnerId, array $lostResources, float $factor): array
    {
        $transferred = [];
        foreach ($lostResources as $lr) {
            $amtToWinner = (int) floor($lr['lossAmount'] * $factor);
            if ($amtToWinner <= 0) {
                continue;
            }
            $this->characterResourceModel->increaseResources(
                $winnerId,
                $lr['resourceId'],
                $amtToWinner
            );
            $transferred[] = [
                'resourceId' => $lr['resourceId'],
                'amount'     => $amtToWinner,
            ];
        }
        return $transferred;
    }

    /**
     * @param list<array{logId:int,craftedItemId:int,lossAmount:int}> $lostCraftedItems
     * @return list<array{craftedItemId:int,amount:int}>
     */
    public function transferCraftToWinner(int $winnerId, array $lostCraftedItems, float $factor): array
    {
        $transferred = [];
        foreach ($lostCraftedItems as $lc) {
            $amtToWinner = (int) floor($lc['lossAmount'] * $factor);
            if ($amtToWinner <= 0) {
                continue;
            }
            $this->increaseCraftForWinner($winnerId, $lc['craftedItemId'], $amtToWinner);
            $transferred[] = [
                'craftedItemId' => $lc['craftedItemId'],
                'amount'        => $amtToWinner,
            ];
        }
        return $transferred;
    }

    public function transferGoldToWinner(int $winnerId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $winnerRow = $this->characterModel->find($winnerId);
        if (!$winnerRow) {
            return;
        }
        $newGold = (int) $winnerRow['gold'] + $amount;
        $this->characterModel->update($winnerId, ['gold' => $newGold]);
    }

    private function increaseCraftForWinner(int $winnerId, int $craftedItemId, int $amount): void
    {
        $existing = $this->craftedItemsLogModel
            ->where('character_id', $winnerId)
            ->where('crafted_item_id', $craftedItemId)
            ->first();

        if ($existing) {
            $newQty = (int) $existing['quantity'] + $amount;
            $this->craftedItemsLogModel->update($existing['id'], ['quantity' => $newQty]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id'      => $winnerId,
                'crafted_item_id'   => $craftedItemId,
                'task_id'           => null,
                'type'              => 'loot',
                'direction_craft'   => 'pvp_loot',
                'crafting_location' => 'battlefield',
                'durability_count'  => 0,
                'quantity'          => $amount,
            ]);
        }
    }
}
