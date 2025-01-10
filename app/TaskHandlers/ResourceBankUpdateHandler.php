<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\ResourceModel;
use App\Models\ResourcesBankModel;

class ResourceBankUpdateHandler
{
    protected $characterModel;
    protected $resourceModel;
    protected $resourcesBankModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->resourceModel = new ResourceModel();
        $this->resourcesBankModel = new ResourcesBankModel();
    }

    public function process()
    {
        // Проверка временного диапазона
        $currentTime = (int)date('G');

        // Удалена временная проверка для демонстрации всего процесса
        $totalPlayers = $this->characterModel->countAllResults();
        $sumOfLevels = $this->characterModel->selectSum('level')->first()['level'];

        // Проверка на деление на ноль
        if ($totalPlayers == 0) {
            throw new \Exception("Нет игроков в системе.");
        }

        $resources = $this->resourceModel->findAll();

        foreach ($resources as $resource) {
            if ($resource['rarity'] == 0) {
                throw new \Exception("Редкость ресурса не может быть нулевой.");
            }

            $stockQuantity = $this->calculateStockQuantity($totalPlayers, $sumOfLevels, $resource);

            // Обновляем или вставляем новое количество ресурса в банк ресурсов
            $this->updateResourceStock($resource['id'], $stockQuantity);
        }
    }

    protected function calculateStockQuantity($totalPlayers, $sumOfLevels, $resource)
    {
        return round(
            $totalPlayers *
            $resource['initial_quantity'] *
            (1 + $sumOfLevels / 1000) *
            (11 / max(1, $resource['rarity'])) // Защита от деления на ноль
        );
    }

    protected function updateResourceStock(int $resourceId, int $quantity)
    {
        $existingStock = $this->resourcesBankModel->where('resource_id', $resourceId)->first();
        $currentTime = date('Y-m-d H:i:s'); // Получаем текущие дату и время

        if ($existingStock) {
            // Добавляем обновление поля 'last_update' с текущим временем
            $this->resourcesBankModel->update($existingStock['id'], [
                'current_quantity' => $quantity,
                'last_update' => $currentTime
            ]);
        } else {
            $this->resourcesBankModel->insert([
                'resource_id' => $resourceId,
                'current_quantity' => $quantity,
                'last_update' => $currentTime
            ]);
        }
    }
}
