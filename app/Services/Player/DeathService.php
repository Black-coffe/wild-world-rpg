<?php

namespace App\Services\Player;

use App\Models\ClaimedCellModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CharacterModel;

/**
 * Сервис, отвечающий за механику смерти игрока,
 * а именно за списание ресурсов, крафт-предметов и золота
 * с учётом того, есть ли у игрока база или нет.
 */
class DeathService
{
    /** @var ClaimedCellModel */
    protected $claimedCellModel;

    /** @var CharacterResourceModel */
    protected $characterResourceModel;

    /** @var CraftedItemsLogModel */
    protected $craftedItemsLogModel;

    /** @var CharacterModel */
    protected $characterModel;

    public function __construct()
    {
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->characterModel         = new CharacterModel();
    }

    /**
     * Основной метод: обработка "смерти" персонажа.
     *
     * @param int $characterId  ID персонажа
     * @return array [
     *   'hasBase'   => bool  - было ли у игрока активное поселение,
     *   'penalty'   => float - применённый штраф (от 0 до 1),
     *   'success'   => bool  - всё ли прошло успешно,
     *   'message'   => string|nullable
     * ]
     */
    public function handlePlayerDeath(int $characterId): array
    {
        // 1. Проверяем, есть ли у персонажа база
        $hasBase = $this->checkIfPlayerHasActiveBase($characterId);

        // 2. Определяем процент списания: 0.50 или 0.03
        $penalty = $hasBase ? 0.03 : 0.50;

        // 3. Начинаем транзакцию (по желанию можно использовать глобальную транзакцию DB)
        // Пример (необязательно, зависит от вашей конфигурации):
        // $this->claimedCellModel->db->transStart();

        try {
            // 4. Списываем ресурсы (character_resources)
            $this->applyPenaltyToResources($characterId, $penalty);

            // 5. Списываем предметы (crafted_items_log)
            $this->applyPenaltyToCraftedItems($characterId, $penalty);

            // 6. Списываем золото (characters -> gold)
            $this->applyPenaltyToGold($characterId, $penalty);

            // $this->claimedCellModel->db->transComplete();

            // 7. Формируем итог
            return [
                'hasBase' => $hasBase,
                'penalty' => $penalty,
                'success' => true,
                'message' => null,
            ];

        } catch (\Throwable $e) {
            // $this->claimedCellModel->db->transRollback();

            return [
                'hasBase' => $hasBase,
                'penalty' => $penalty,
                'success' => false,
                'message' => 'Ошибка при списании: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Проверка, есть ли у игрока база (claimed_cells.status='active').
     */
    protected function checkIfPlayerHasActiveBase(int $characterId): bool
    {
        $row = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        return !empty($row);
    }

    /**
     * Списание ресурсов в таблице character_resources:
     * отнимаем (penalty)% от quantity, округляя в меньшую сторону (floor).
     */
    protected function applyPenaltyToResources(int $characterId, float $penalty): void
    {
        // Находим все ресурсы игрока
        $resources = $this->characterResourceModel
            ->where('id_characters', $characterId)
            ->findAll();

        if (!$resources) {
            return;
        }

        foreach ($resources as $res) {
            $oldQty = (int) $res['quantity'];
            if ($oldQty <= 0) {
                continue;
            }

            // Вычисляем новое количество:
            // Пример: если penalty=0.5 (50%), тогда оставляем 50% от старого.
            $newQty = (int) \floor($oldQty * (1 - $penalty));
            if ($newQty < 0) {
                $newQty = 0;
            }

            // Обновляем
            $this->characterResourceModel->update($res['id'], [
                'quantity' => $newQty
            ]);
        }
    }

    /**
     * Списание крафт-предметов в таблице crafted_items_log:
     * отнимаем (penalty)% от quantity, округляя в меньшую сторону (floor).
     */
    protected function applyPenaltyToCraftedItems(int $characterId, float $penalty): void
    {
        // Ищем все крафт-предметы игрока
        $craftedItems = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->findAll();

        if (!$craftedItems) {
            return;
        }

        foreach ($craftedItems as $item) {
            $oldQty = (int) $item['quantity'];
            if ($oldQty <= 0) {
                continue;
            }

            $newQty = (int) \floor($oldQty * (1 - $penalty));
            if ($newQty < 0) {
                $newQty = 0;
            }

            $this->craftedItemsLogModel->update($item['id'], [
                'quantity' => $newQty
            ]);
        }
    }

    /**
     * Списание золота (поле gold в characters):
     * отнимаем (penalty)% от gold, округляя вниз.
     */
    protected function applyPenaltyToGold(int $characterId, float $penalty): void
    {
        $charRow = $this->characterModel->find($characterId);
        if (!$charRow) {
            return;
        }

        $oldGold = (int) $charRow['gold'];
        if ($oldGold <= 0) {
            return;
        }

        $newGold = (int) \floor($oldGold * (1 - $penalty));
        if ($newGold < 0) {
            $newGold = 0;
        }

        // Обновляем
        $this->characterModel->update($characterId, [
            'gold' => $newGold
        ]);
    }
}
