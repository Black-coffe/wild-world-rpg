<?php

declare(strict_types=1);

namespace App\Services\Player\Trade;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\ResourcesBankModel;

/**
 * Идея #6 (Arseny, 21.01.2025) — service-extraction торговли ресурсами,
 * чтобы и action-handler, и ForceReply путь из GenericmessageCommand
 * исполняли одну и ту же бизнес-логику.
 *
 * Раньше Sell/BuyResourceAction содержали логику inline в finalize* методах.
 */
final class ResourceTradeService
{
    private CharacterModel         $characterModel;
    private CharacterResourceModel $characterResourceModel;
    private ResourceModel          $resourceModel;
    private ResourcesBankModel     $resourcesBankModel;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->resourcesBankModel     = new ResourcesBankModel();
    }

    /**
     * Продажа `qty` единиц ресурса по `sell_price`. `qty='all'` → продать всё.
     *
     * @param array<string,mixed> $character
     * @param int|string $qtyAction
     * @return array{success:bool, message:string, qty?:int, amount?:int}
     */
    public function sellResource(array $character, int $resourceId, int|string $qtyAction): array
    {
        $charRes = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->where('id_resources',  $resourceId)
            ->first();
        if (!$charRes) {
            return ['success' => false, 'message' => 'У вас нет такого ресурса.'];
        }

        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            return ['success' => false, 'message' => 'Ресурс не найден.'];
        }

        $sellQuantity = $qtyAction === 'all'
            ? (int) $charRes['quantity']
            : min((int) $qtyAction, (int) $charRes['quantity']);

        if ($sellQuantity <= 0) {
            return ['success' => false, 'message' => 'Некорректное количество для продажи.'];
        }

        $saleAmount = (int) round($sellQuantity * (float) $resource['sell_price']);

        $this->characterModel->update($character['id'], [
            'gold' => $character['gold'] + $saleAmount,
        ]);

        $newQuantity = $charRes['quantity'] - $sellQuantity;
        if ($newQuantity > 0) {
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQuantity]);
        } else {
            $this->characterResourceModel->delete($charRes['id']);
        }

        $bank = $this->resourcesBankModel->where('resource_id', $resourceId)->first();
        if ($bank) {
            $this->resourcesBankModel->update($bank['id'], [
                'resources_sold' => $bank['resources_sold'] + $sellQuantity,
                'last_update'    => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->resourcesBankModel->insert([
                'resource_id'      => $resourceId,
                'current_quantity' => 0,
                'resources_sold'   => $sellQuantity,
                'last_update'      => date('Y-m-d H:i:s'),
            ]);
        }

        $message = "Продажа ресурса *'{$resource['name']}'* в количестве *{$sellQuantity}* успешно выполнена.\n"
            . "Вы заработали *{$saleAmount}*💰.\n"
            . "(Цена за штуку была *{$resource['sell_price']}*)";

        return ['success' => true, 'message' => $message, 'qty' => $sellQuantity, 'amount' => $saleAmount];
    }

    /**
     * Покупка `qty` единиц ресурса по `buy_price`.
     *
     * @param array<string,mixed> $character
     * @return array{success:bool, message:string, qty?:int, cost?:int}
     */
    public function buyResource(array $character, int $resourceId, int $qty): array
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            return ['success' => false, 'message' => 'Ресурс не найден в базе.'];
        }
        if ($qty <= 0) {
            return ['success' => false, 'message' => 'Некорректное количество для покупки.'];
        }

        $totalCost = (int) round($qty * (float) $resource['buy_price']);
        if ((int) $character['gold'] < $totalCost) {
            return ['success' => false, 'message' => "У вас недостаточно золота для покупки {$qty} ед. (нужно {$totalCost}💰)."];
        }

        $this->characterModel->decreaseGold((int) $character['id'], $totalCost);
        $this->characterResourceModel->addOrIncreaseResource((int) $character['id'], $resourceId, $qty);
        $this->resourcesBankModel->updatePurchasedQuantity($resourceId, $qty);

        $message = "Вы успешно купили *{$qty}* ед. ресурса *{$resource['name']}* "
            . "по цене *{$resource['buy_price']}*💰 за штуку.\n\n"
            . "Итого потрачено: *{$totalCost}* 💰";

        return ['success' => true, 'message' => $message, 'qty' => $qty, 'cost' => $totalCost];
    }
}
