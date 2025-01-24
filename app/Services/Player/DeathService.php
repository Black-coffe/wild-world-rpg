<?php

namespace App\Services\Player;

use App\Models\ClaimedCellModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CharacterModel;

/**
 * Сервис DeathService:
 * - Определяет, сколько % списать у проигравшего (3% или 50%).
 * - Списывает (ресурсы, крафт, золото).
 * - При наличии победителя отдаёт ему часть (3% или 25%).
 */
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
     * @param int      $loserId   — ID проигравшего
     * @param int|null $winnerId  — ID победителя (или null, если не нужно передавать ресурсы)
     * @return array Сводка данных (hasBase, penalty, что передали победителю и т.д.)
     */
    public function handlePlayerDeathAndReward(int $loserId, ?int $winnerId = null): array
    {
        // 1) Проверяем, есть ли у проигравшего база?
        $hasBase      = $this->checkIfPlayerHasActiveBase($loserId);
        $deathPenalty = $hasBase ? 0.03 : 0.50; // 3% или 50%

        // 2) Собираем «имущество» проигравшего
        $loserResources    = $this->getLoserResources($loserId);      // обычные ресурсы
        $loserGold         = $this->getLoserGold($loserId);           // золото
        $loserCraftedItems = $this->getLoserCraftedItems($loserId);   // крафтовые предметы

        // 3) Подсчитываем, сколько снимаем
        $lostResources    = $this->computeResourceLoss($loserResources, $deathPenalty);
        $lostGold         = (int) floor($loserGold * $deathPenalty);
        $lostCraftedItems = $this->computeCraftLoss($loserCraftedItems, $deathPenalty);

        // 4) «Физически» списываем у проигравшего
        $this->applyLosses($loserId, $lostResources, $lostGold);        // Ресурсы + золото
        $this->applyCraftLosses($loserId, $lostCraftedItems);           // Крафтовые предметы

        // 5) Если есть победитель — передаём ему часть
        $transferredResources = [];
        $transferredCraft     = [];
        $transferredGold      = 0;

        if ($winnerId) {
            if (!$hasBase) {
                // Без базы => 50% списано. Победитель получает 25% (половину от 50%).
                $transferredResources = $this->transferPartOfResources($winnerId, $lostResources, 0.5);
                $transferredCraft     = $this->transferPartOfCraft($winnerId, $lostCraftedItems, 0.5);

                $transferGold = (int) floor($lostGold / 2);
                if ($transferGold > 0) {
                    $this->increaseGoldForWinner($winnerId, $transferGold);
                    $transferredGold = $transferGold;
                }
            } else {
                // Есть база => 3% списано, всё (3%) уходит победителю
                $transferredResources = $this->transferPartOfResources($winnerId, $lostResources, 1.0);
                $transferredCraft     = $this->transferPartOfCraft($winnerId, $lostCraftedItems, 1.0);

                if ($lostGold > 0) {
                    $this->increaseGoldForWinner($winnerId, $lostGold);
                    $transferredGold = $lostGold;
                }
            }
        }

        // 6) Возвращаем сводку
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
     * Возвращает массив записей из таблицы character_resources.
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
     * Возвращает массив крафтовых предметов (таблица crafted_items_log)
     */
    protected function getLoserCraftedItems(int $charId): array
    {
        return $this->craftedItemsLogModel
            ->where('character_id', $charId)
            ->findAll();
    }

    /**
     * Подсчитывает, сколько ресурсов (из character_resources) будет потеряно.
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
                    'charResId'  => $res['id'],           // PK в таблице character_resources
                    'resourceId' => $res['id_resources'], // ID самого ресурса
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
        // 1) Уменьшаем обычные ресурсы
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
     * Применяет списание крафтовых предметов (уменьшает quantity или удаляет, если дошло до 0).
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
     * Отдаём победителю долю (factor) от потерянных ресурсов.
     * Например, factor=0.5 => половина от lostAmount, factor=1.0 => всё.
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
            // Сохраняем для отчёта
            $transferred[] = [
                'resourceId' => $lr['resourceId'],
                'amount'     => $amtToWinner,
            ];
        }
        return $transferred;
    }

    /**
     * То же самое для крафтовых предметов.
     */
    protected function transferPartOfCraft(int $winnerId, array $lostCraftedItems, float $factor): array
    {
        $transferred = [];
        foreach ($lostCraftedItems as $lc) {
            $amtToWinner = (int) floor($lc['lossAmount'] * $factor);
            if ($amtToWinner <= 0) {
                continue;
            }
            // Увеличиваем у победителя
            $this->increaseCraftForWinner($winnerId, $lc['craftedItemId'], $amtToWinner);

            // Сохраняем для отчёта
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
     * Увеличивает крафтовый предмет победителю в таблице crafted_items_log.
     * Если записи нет — создаёт.
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
            // Создаём новую запись
            $this->craftedItemsLogModel->insert([
                'character_id'    => $winnerId,
                'crafted_item_id' => $craftedItemId,
                'task_id'         => 0,     // или null, если поле не обязательно
                'type'            => 'loot',
                'direction_craft' => 'pvp_loot',
                'crafting_location' => 'battlefield',
                'durability_count' => 0,
                'quantity'         => $amount,
            ]);
        }
    }
}
