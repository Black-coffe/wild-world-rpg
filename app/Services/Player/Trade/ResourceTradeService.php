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

    // ─────────────────────────────────────────────────────────────────────────
    // Оптовая продажа (Asana «кнопки оптовой продажи», ADR-096).
    //
    // «Продать N% всех ресурсов» — на экране выбора редкости (scope=all) и внутри
    // конкретной редкости (scope=rarity). Доля берётся ОТ КАЖДОГО запаса (floor).
    // Продаются только ходовые ресурсы (is_tradeable=1 И sell_price>0) — чтобы оптом
    // не уничтожить связанные/бесценные предметы за 0💰 (игрок не выбирает каждый
    // ресурс вручную, как в поштучной продаже). Цена за единицу — та же sell_price
    // (warehouse-бонус касается ТОЛЬКО крафта, не сырья → консистентно с sellResource).
    // Золото начисляется ОДНИМ обновлением (increaseGold читает свежий баланс из БД):
    // per-resource update перетёр бы gold (sellResource пишет character.gold+amount от
    // переданного снапшота). Всё атомарно (transaction).
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Чистый планировщик оптовой продажи — без БД, полностью юнит-тестируемый.
     *
     * @param list<array{id:int,charResId:int,quantity:int,sell_price:float,is_tradeable:int,rarity:int,name:string,icon:string}> $rows
     * @return array{lines:list<array{charResId:int,id:int,qty:int,gold:int,name:string,icon:string}>,typesCount:int,totalQty:int,totalGold:int}
     */
    public static function planBulkSale(array $rows, int $percent): array
    {
        $percent   = max(1, min(100, $percent));
        $lines     = [];
        $totalQty  = 0;
        $totalGold = 0;

        foreach ($rows as $row) {
            if ($row['is_tradeable'] !== 1 || $row['sell_price'] <= 0.0) {
                continue; // связанные/бесценные ресурсы оптом не продаём
            }
            $qty = (int) floor($row['quantity'] * $percent / 100);
            if ($qty <= 0) {
                continue; // мелкие стопки при малом проценте дают 0
            }
            $gold = (int) round($qty * $row['sell_price']);
            if ($gold <= 0) {
                continue;
            }
            $lines[] = [
                'charResId' => $row['charResId'],
                'id'        => $row['id'],
                'qty'       => $qty,
                'gold'      => $gold,
                'name'      => $row['name'],
                'icon'      => $row['icon'],
            ];
            $totalQty  += $qty;
            $totalGold += $gold;
        }

        return [
            'lines'      => $lines,
            'typesCount' => count($lines),
            'totalQty'   => $totalQty,
            'totalGold'  => $totalGold,
        ];
    }

    /**
     * Предпросмотр оптовой продажи (без мутаций) — для экрана подтверждения.
     *
     * @param array<string,mixed> $character
     * @return array{typesCount:int,totalQty:int,totalGold:int}
     */
    public function bulkSellPreview(array $character, int $percent, ?int $rarity = null): array
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $plan   = self::planBulkSale($this->fetchSellableRows($charId, $rarity), $percent);

        return [
            'typesCount' => $plan['typesCount'],
            'totalQty'   => $plan['totalQty'],
            'totalGold'  => $plan['totalGold'],
        ];
    }

    /**
     * Выполнить оптовую продажу: списать долю каждого ходового ресурса, начислить
     * золото одним обновлением, учесть продажи в банке. Атомарно (transaction).
     *
     * @param array<string,mixed> $character
     * @return array{success:bool,message:string,typesSold:int,totalQty:int,totalGold:int}
     */
    public function bulkSellResources(array $character, int $percent, ?int $rarity = null): array
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($charId <= 0) {
            return ['success' => false, 'message' => 'Персонаж не определён.', 'typesSold' => 0, 'totalQty' => 0, 'totalGold' => 0];
        }

        // Пересчитываем план НА МОМЕНТ подтверждения (не доверяем превью — запас мог измениться).
        $plan = self::planBulkSale($this->fetchSellableRows($charId, $rarity), $percent);
        if ($plan['lines'] === [] || $plan['totalGold'] <= 0) {
            return ['success' => false, 'message' => 'Нечего продавать оптом — подходящих ресурсов не осталось.', 'typesSold' => 0, 'totalQty' => 0, 'totalGold' => 0];
        }

        $db = \Config\Database::connect();
        $db->transStart();

        foreach ($plan['lines'] as $line) {
            $this->characterResourceModel->decreaseQtyById($line['charResId'], $line['qty']);
            $this->bumpBankSold($line['id'], $line['qty']);
        }
        $this->characterModel->increaseGold($charId, (float) $plan['totalGold']);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['success' => false, 'message' => 'Не удалось выполнить оптовую продажу, попробуйте ещё раз.', 'typesSold' => 0, 'totalQty' => 0, 'totalGold' => 0];
        }

        return [
            'success'   => true,
            'message'   => 'Оптовая продажа выполнена.',
            'typesSold' => $plan['typesCount'],
            'totalQty'  => $plan['totalQty'],
            'totalGold' => $plan['totalGold'],
        ];
    }

    /**
     * Ресурсы персонажа в нормализованном (типизированном) виде, опц. фильтр по редкости.
     *
     * @return list<array{id:int,charResId:int,quantity:int,sell_price:float,is_tradeable:int,rarity:int,name:string,icon:string}>
     */
    private function fetchSellableRows(int $characterId, ?int $rarity): array
    {
        if ($characterId <= 0) {
            return [];
        }
        $rows = [];
        foreach ($this->resourceModel->getCharacterResources($characterId) as $row) {
            $normalized = $this->normalizeResourceRow($row);
            if ($rarity !== null && $normalized['rarity'] !== $rarity) {
                continue;
            }
            $rows[] = $normalized;
        }
        return $rows;
    }

    /**
     * Нормализация строки getCharacterResources (Entity/array) в типизированный массив.
     * Зеркало ShuffleResourcesAction::resInfo: нарроуим mixed через is_numeric/is_scalar
     * (PHPStan L9 запрещает (int)$mixed) ПЕРЕД приведением.
     *
     * @param array<string,mixed>|\App\Entities\ResourceEntity $row
     * @return array{id:int,charResId:int,quantity:int,sell_price:float,is_tradeable:int,rarity:int,name:string,icon:string}
     */
    private function normalizeResourceRow($row): array
    {
        // ResourceEntity кастует is_tradeable в bool (casts['is_tradeable']='boolean') →
        // is_numeric(false) === false съел бы фильтр и продавал bound-ресурсы. Обрабатываем
        // и bool, и числовой 0/1; неизвестное → 1 (по умолчанию ходовой).
        $tradeRaw  = $row['is_tradeable'] ?? true;
        $tradeable = is_bool($tradeRaw)
            ? ($tradeRaw ? 1 : 0)
            : (is_numeric($tradeRaw) ? (int) $tradeRaw : 1);

        return [
            'id'           => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'charResId'    => is_numeric($row['charResId'] ?? null) ? (int) $row['charResId'] : 0,
            'quantity'     => is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0,
            'sell_price'   => is_numeric($row['sell_price'] ?? null) ? (float) $row['sell_price'] : 0.0,
            'is_tradeable' => $tradeable,
            'rarity'       => is_numeric($row['rarity'] ?? null) ? (int) $row['rarity'] : 0,
            'name'         => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '?',
            'icon'         => is_scalar($row['icon_text'] ?? null) ? (string) $row['icon_text'] : '',
        ];
    }

    /**
     * Учёт продажи в банке ресурсов (для price-discovery). Свежий инстанс модели на
     * вызов — builder-state quirk where()->first() в loop'е (memory ci4_model_builder_state_quirk).
     */
    private function bumpBankSold(int $resourceId, int $qty): void
    {
        $bankModel = new ResourcesBankModel();
        $bank      = $bankModel->where('resource_id', $resourceId)->first();
        $bankId = is_array($bank) && is_numeric($bank['id'] ?? null) ? (int) $bank['id'] : 0;
        if (is_array($bank) && $bankId > 0) {
            $soldRaw = $bank['resources_sold'] ?? 0;
            $sold    = is_numeric($soldRaw) ? (int) $soldRaw : 0;
            $bankModel->update($bankId, [
                'resources_sold' => $sold + $qty,
                'last_update'    => date('Y-m-d H:i:s'),
            ]);
        } else {
            $bankModel->insert([
                'resource_id'      => $resourceId,
                'current_quantity' => 0,
                'resources_sold'   => $qty,
                'last_update'      => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
