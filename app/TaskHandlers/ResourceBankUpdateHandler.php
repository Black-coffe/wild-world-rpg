<?php

namespace App\TaskHandlers;

use App\Models\ResourceModel;
use App\Models\ResourcesBankModel;

class ResourceBankUpdateHandler
{
    protected $resourceModel;
    protected $resourcesBankModel;

    public function __construct()
    {
        $this->resourceModel = new ResourceModel();
        $this->resourcesBankModel = new ResourcesBankModel();
    }

    public function process()
    {
        // Получаем все ресурсы
        $resources = $this->resourceModel->findAll();

        foreach ($resources as $resource) {
            // Ищем запись в resources_bank
            $bankData = $this->resourcesBankModel
                ->where('resource_id', $resource['id'])
                ->first();

            // Если записи нет, пропускаем (нет купленных/проданных)
            if (!$bankData) {
                continue;
            }

            // Получаем показатели спроса/предложения
            $purchased = (int)$bankData['resources_purchased'];
            $sold      = (int)$bankData['resources_sold'];

            // Считаем ratio, зажатый в коридор [0.35 .. 3.5]
            $ratio = ($purchased + 1) / ($sold + 1);
            $priceFactor = max(0.35, min(3.5, $ratio));

            // Вычисляем новые цены, исходя из базовой price
            $basePrice = $resource['price'];
            $newPrice  = $basePrice * $priceFactor;

            // Предположим, покупка на 5% дороже, продажа на 5% дешевле
            $buyPrice  = round($newPrice * 1.05, 2);
            $sellPrice = round($newPrice * 0.95, 2);

            // Обновляем в таблице resources
            $this->resourceModel->update($resource['id'], [
                'buy_price'  => $buyPrice,
                'sell_price' => $sellPrice,
            ]);

            // "Состариваем" (уменьшаем) показатели purchased/sold
            // чтобы при отсутствии сделок цена постепенно возвращалась к базовой
            $newPurchased = max(0, $purchased - 1);
            $newSold      = max(0, $sold - 1);

            // Обновляем банк
            $this->resourcesBankModel->update($bankData['id'], [
                'resources_purchased' => $newPurchased,
                'resources_sold'      => $newSold,
                'last_update'         => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
