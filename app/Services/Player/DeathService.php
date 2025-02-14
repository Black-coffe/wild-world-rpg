<?php

namespace App\Services\Player;

use App\Models\ClaimedCellModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CharacterModel;

class DeathService
{
    protected $claimedCellModel;
    protected $characterResourceModel;
    protected $craftedItemsLogModel;
    protected $characterModel;

    public function __construct()
    {
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->characterModel         = new CharacterModel();
    }

    /**
     * Главный метод:
     * @param int      $loserId   — ID проигравшего (умершего) персонажа
     * @param int|null $winnerId  — ID победителя (или null, если не нужно передавать ресурсы)
     * @return array Сводка данных (hasBase, penalty, что передали победителю и т.д.)
     */
    public function handlePlayerDeathAndReward(int $loserId, ?int $winnerId = null): array
    {
        $loserRow = $this->characterModel->find($loserId);
        if (!$loserRow) {
            return ['success' => false];
        }

        // 1) Проверяем, включена ли страховка (insurance=1)
        if ($loserRow['insurance'] == 1) {
            // Считаем стоимость страховки
            $cost = $this->calculateInsuranceCost($loserRow);

            if ($loserRow['gold'] >= $cost) {
                // Денег хватает => списываем страховку, штраф = 0%
                $this->characterModel->update($loserId, [
                    'gold'      => $loserRow['gold'] - $cost,
                    'insurance' => 0, // страховка одноразовая
                ]);

                $deathPenalty = 0.0;
                $hasBase = false;
            } else {
                // Денег не хватает => страховка не сработала
                $this->characterModel->update($loserId, ['insurance' => 0]);
                $hasBase = $this->checkIfPlayerHasActiveBase($loserId);
                $deathPenalty = $hasBase ? 0.03 : 0.50;
            }
        } else {
            $hasBase = $this->checkIfPlayerHasActiveBase($loserId);
            $deathPenalty = $hasBase ? 0.03 : 0.50;
        }

        // 4) Собираем имущество проигравшего
        $loserResources    = $this->getLoserResources($loserId);
        $loserGold         = $this->getLoserGold($loserId);
        $loserCraftedItems = $this->getLoserCraftedItems($loserId);

        // 5) Считаем, сколько снимаем
        $lostResources    = $this->computeResourceLoss($loserResources, $deathPenalty);
        $lostGold         = (int) floor($loserGold * $deathPenalty);
        $lostCraftedItems = $this->computeCraftLoss($loserCraftedItems, $deathPenalty);

        // 6) «Физически» списываем у проигравшего (ресурсы, золото, крафтовые предметы)
        $this->applyLosses($loserId, $lostResources, $lostGold);
        $this->applyCraftLosses($loserId, $lostCraftedItems);

        // 7) Передаём часть победителю (если есть winnerId)
        $transferredResources = [];
        $transferredCraft     = [];
        $transferredGold      = 0;

        if ($winnerId) {
            if (!$hasBase) {
                $transferredResources = $this->transferPartOfResources($winnerId, $lostResources, 0.5);
                $transferredCraft     = $this->transferPartOfCraft($winnerId, $lostCraftedItems, 0.5);
                $transferGold = (int) floor($lostGold / 2);
                if ($transferGold > 0) {
                    $this->increaseGoldForWinner($winnerId, $transferGold);
                    $transferredGold = $transferGold;
                }
            } else {
                $transferredResources = $this->transferPartOfResources($winnerId, $lostResources, 1.0);
                $transferredCraft     = $this->transferPartOfCraft($winnerId, $lostCraftedItems, 1.0);
                if ($lostGold > 0) {
                    $this->increaseGoldForWinner($winnerId, $lostGold);
                    $transferredGold = $lostGold;
                }
            }
        }

        // 9) Перемещаем игрока на базу или в новую ячейку (respawn)
        $this->respawnPlayer($loserId);

        // 10) Возвращаем сводку
        return [
            'hasBase'               => $hasBase,
            'penalty'               => $deathPenalty,
            'transferredResources'  => $transferredResources,
            'transferredCraftItems' => $transferredCraft,
            'transferredGold'       => $transferredGold,
            'success'               => true,
        ];
    }

    /**
     * Метод перемещения (respawn) игрока после смерти.
     * Если у игрока есть активная база (claimed_cells, status = active),
     * то перемещаем его туда. Если базы нет, выбираем случайную ячейку из таблицы map,
     * где coordinate_y находится в диапазоне 900-999, а biome_id входит в [1,2,3,6].
     *
     * @param int $characterId
     * @return void
     */
    private function respawnPlayer(int $characterId): void
    {
        // Проверяем наличие активной базы
        $claimed = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        if ($claimed) {
            // Если база есть, берем map_cell_id базы
            $respawnCell = (int)$claimed['map_cell_id'];
        } else {
            // Если базы нет, выбираем случайную ячейку из таблицы map по условиям
            $mapModel = new \App\Models\MapModel();
            $cells = $mapModel->where('coordinate_y >=', 900)
                ->where('coordinate_y <=', 999)
                ->whereIn('biome_id', [1, 2, 3, 6])
                ->findAll();
            if (!empty($cells)) {
                $randomCell = $cells[array_rand($cells)];
                $respawnCell = (int)$randomCell['cell_number'];
            } else {
                // На всякий случай, если не найдено ни одной ячейки, ставим fallback-значение
                $respawnCell = 1;
            }
        }

        // Обновляем запись персонажа, устанавливая новое значение cell_number
        $this->characterModel->update($characterId, ['cell_number' => $respawnCell]);
    }

    /**
     * Проверка, есть ли у игрока активная база (status='active').
     */
    protected function checkIfPlayerHasActiveBase(int $charId): bool
    {
        $row = $this->claimedCellModel
            ->where('character_id', $charId)
            ->where('status', 'active')
            ->first();
        return !empty($row);
    }

    /**
     * Возвращает массив записей из таблицы character_resources (все ресурсы проигравшего).
     */
    protected function getLoserResources(int $charId): array
    {
        return $this->characterResourceModel
            ->where('id_characters', $charId)
            ->findAll();
    }

    /**
     * Возвращает текущее золото (поле gold в таблице characters).
     */
    protected function getLoserGold(int $charId): int
    {
        $row = $this->characterModel->find($charId);
        return $row ? (int)$row['gold'] : 0;
    }

    /**
     * Возвращает массив крафтовых предметов (crafted_items_log) проигравшего.
     */
    protected function getLoserCraftedItems(int $charId): array
    {
        return $this->craftedItemsLogModel
            ->where('character_id', $charId)
            ->findAll();
    }

    /**
     * Подсчитывает, сколько ресурсов (из character_resources) будет потеряно (lostAmount).
     */
    protected function computeResourceLoss(array $loserResources, float $deathPenalty): array
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
                    'charResId'  => $res['id'],           // PK в character_resources
                    'resourceId' => $res['id_resources'], // ID ресурса
                    'lossAmount' => $lossAmount,
                ];
            }
        }
        return $lost;
    }

    /**
     * Подсчитывает, сколько крафтовых предметов (crafted_items_log) будет потеряно.
     */
    protected function computeCraftLoss(array $loserCraftedItems, float $deathPenalty): array
    {
        $lost = [];
        foreach ($loserCraftedItems as $item) {
            $oldQty = (int) $item['quantity'];
            if ($oldQty <= 0) {
                continue;
            }
            $lossAmount = (int) floor($oldQty * $deathPenalty);
            if ($lossAmount > 0) {
                $lost[] = [
                    'logId'         => $item['id'],            // PK в crafted_items_log
                    'craftedItemId' => $item['crafted_item_id'],
                    'lossAmount'    => $lossAmount,
                ];
            }
        }
        return $lost;
    }

    /**
     * Применяет списание обычных ресурсов и золота у проигравшего.
     */
    protected function applyLosses(int $loserId, array $lostResources, int $lostGold): void
    {
        // 1) Уменьшаем ресурсы
        foreach ($lostResources as $lr) {
            $this->characterResourceModel->decreaseQtyById(
                $lr['charResId'],
                $lr['lossAmount']
            );
        }

        // 2) Уменьшаем золото
        if ($lostGold > 0) {
            $loserRow = $this->characterModel->find($loserId);
            if ($loserRow) {
                $newGold = max(0, $loserRow['gold'] - $lostGold);
                $this->characterModel->update($loserId, ['gold' => $newGold]);
            }
        }
    }

    /**
     * Применяет списание крафтовых предметов (уменьшает quantity или удаляет запись).
     */
    protected function applyCraftLosses(int $loserId, array $lostCraftedItems): void
    {
        foreach ($lostCraftedItems as $lc) {
            $logId = $lc['logId'];
            $row   = $this->craftedItemsLogModel->find($logId);
            if (!$row) {
                continue;
            }
            $newQty = $row['quantity'] - $lc['lossAmount'];
            if ($newQty <= 0) {
                // Удаляем запись
                $this->craftedItemsLogModel->delete($logId);
            } else {
                // Обновляем
                $this->craftedItemsLogModel->update($logId, ['quantity' => $newQty]);
            }
        }
    }

    /**
     * Передаём часть (factor) потерянных ресурсов (lostResources) победителю.
     * factor=0.5 => половина от lostAmount, factor=1 => всё.
     */
    protected function transferPartOfResources(int $winnerId, array $lostResources, float $factor): array
    {
        $transferred = [];
        foreach ($lostResources as $lr) {
            $amtToWinner = (int) floor($lr['lossAmount'] * $factor);
            if ($amtToWinner <= 0) {
                continue;
            }
            // Начисляем победителю
            $this->characterResourceModel->increaseResources(
                $winnerId,
                $lr['resourceId'],
                $amtToWinner
            );
            // Для отчёта
            $transferred[] = [
                'resourceId' => $lr['resourceId'],
                'amount'     => $amtToWinner,
            ];
        }
        return $transferred;
    }

    /**
     * То же самое для крафтовых предметов (crafted_items_log).
     */
    protected function transferPartOfCraft(int $winnerId, array $lostCraftedItems, float $factor): array
    {
        $transferred = [];
        foreach ($lostCraftedItems as $lc) {
            $amtToWinner = (int) floor($lc['lossAmount'] * $factor);
            if ($amtToWinner <= 0) {
                continue;
            }
            // Добавляем крафт победителю
            $this->increaseCraftForWinner($winnerId, $lc['craftedItemId'], $amtToWinner);

            $transferred[] = [
                'craftedItemId' => $lc['craftedItemId'],
                'amount'        => $amtToWinner,
            ];
        }
        return $transferred;
    }

    /**
     * Увеличиваем золото победителю.
     */
    protected function increaseGoldForWinner(int $winnerId, int $amount): void
    {
        $winnerRow = $this->characterModel->find($winnerId);
        if (!$winnerRow) {
            return;
        }
        $newGold = $winnerRow['gold'] + $amount;
        $this->characterModel->update($winnerId, ['gold' => $newGold]);
    }

    /**
     * Увеличиваем крафтовый предмет победителю (crafted_items_log).
     * Если записи нет — создаём новую.
     */
    protected function increaseCraftForWinner(int $winnerId, int $craftedItemId, int $amount): void
    {
        $existing = $this->craftedItemsLogModel
            ->where('character_id', $winnerId)
            ->where('crafted_item_id', $craftedItemId)
            ->first();

        if ($existing) {
            $newQty = $existing['quantity'] + $amount;
            $this->craftedItemsLogModel->update($existing['id'], [
                'quantity' => $newQty,
            ]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id'     => $winnerId,
                'crafted_item_id'  => $craftedItemId,
                'task_id'          => null,  // нет задачи
                'type'             => 'loot',
                'direction_craft'  => 'pvp_loot',
                'crafting_location'=> 'battlefield',
                'durability_count' => 0,
                'quantity'         => $amount,
            ]);
        }
    }

    /**
     * Считаем стоимость страховки (аналог CalculateInsuranceAction).
     * При желании формулу можно упростить или скорректировать.
     */
    private function calculateInsuranceCost(array $char): int
    {
        // Сколько у персонажа ресурсов
        $totalResources = $this->characterResourceModel
            ->where('id_characters', $char['id'])
            ->countAllResults();

        // Сколько месяцев в игре
        $createdAt = $char['created_at'] ?? '1970-01-01';
        $dtCreated = new \DateTime($createdAt);
        $monthsInGame = $dtCreated->diff(new \DateTime())->m + 1; // простой вариант

        $level      = $char['level'];
        $experience = $char['experience'];
        $strength   = $char['strength'];
        $agility    = $char['agility'];
        $intellect  = $char['intellect'];
        $tired      = $char['tired'];
        $goldInChest= $char['gold'];

        // Пример формулы
        $resourceCost    = ($totalResources / 1000) * 10;
        $timeCost        = ($monthsInGame / 12) * 5;
        $levelCost       = ($level / 10) * 20;
        $experienceCost  = ($experience / 10) * 5;
        $attributesCost  = (($strength + $agility + $intellect + $tired) / 100) * 10;
        $goldCost        = ($goldInChest / 10000) * 50;

        $totalCost = $resourceCost + $timeCost + $levelCost + $experienceCost + $attributesCost + $goldCost;

        return (int) round($totalCost);
    }
}
