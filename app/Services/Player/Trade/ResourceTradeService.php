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
    use \App\Services\GameSettings\GameSettingsReaderTrait;

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
     * Цена за единицу — ровно то поле, по которому считает сделка.
     *
     * 🔴 Тип `array|ResourceEntity`, а не голый `array`: `ResourceModel::find()` отдаёт
     * Entity, и под `strict_types` узкий typehint даёт TypeError. Ту же ловушку класс
     * уже обходит в `resourceIsTradeable()`. См. memory
     * `feedback_entity_strict_array_typehint_trap`.
     *
     * @param array<string,mixed>|\App\Entities\ResourceEntity $resource строка `resources`
     */
    public function unitPrice(array|\App\Entities\ResourceEntity $resource, bool $selling): float
    {
        $raw = $selling ? ($resource['sell_price'] ?? 0) : ($resource['buy_price'] ?? 0);

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /**
     * Итог сделки — ЕДИНСТВЕННАЯ формула на экраны и на списание.
     *
     * 🔴 Экраны раньше считали иначе: брали `(int) $resource['sell_price']` (обрезая
     * дробь у ЦЕНЫ) и умножали на количество, а сделка округляла ИТОГ. На проде
     * 2026-08-06 дробную цену продажи имеют 50 ресурсов из 80, покупки — 65 из 80,
     * и кнопки доходят до 5000 единиц: «Отработанные ТВЭЛы» (262.50) при 5000 ед.
     * обещали 1 310 000, а списывали 1 312 500 — 2 500 золота мимо обещания, против
     * игрока. См. memory `feedback_screen_price_must_come_from_transaction_service`.
     */
    public function totalFor(int $quantity, float $unitPrice): int
    {
        return (int) round($quantity * $unitPrice);
    }

    /**
     * Цена за единицу для показа: дробь не теряется, хвостовые нули убраны
     * (237.50 → «237.5», 4.00 → «4», 10.71 → «10.71»).
     *
     * Строка чисто ASCII, поэтому `rtrim` со списком символов безопасен
     * (ср. memory `feedback_bytes_vs_chars_utf8_traps` — на кириллице так нельзя).
     */
    public function formatUnitPrice(float $unitPrice): string
    {
        $text = rtrim(rtrim(number_format($unitPrice, 2, '.', ''), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
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

        // WB2 (ADR-137): одиночная продажа ОБЯЗАНА уважать is_tradeable, как оптовая
        // planBulkSale (стр.159). Иначе связанный/неторговый ресурс сливается поштучно
        // мимо опт-фильтра (блокер ADR-137 #5). Soulbound-трофеи живут на оружии/броне
        // (их вообще нельзя продать — нет sell-пути для экипировки), но любой bound-РЕСУРС
        // обязан резаться и здесь.
        if (! self::resourceIsTradeable($resource)) {
            return ['success' => false, 'message' => 'Этот ресурс связанный — его нельзя продать.'];
        }

        $sellQuantity = $qtyAction === 'all'
            ? (int) $charRes['quantity']
            : min((int) $qtyAction, (int) $charRes['quantity']);

        if ($sellQuantity <= 0) {
            return ['success' => false, 'message' => 'Некорректное количество для продажи.'];
        }

        $saleAmount = $this->totalFor($sellQuantity, $this->unitPrice($resource, true));

        // Fix 2026-07-13 (класс lost-update): атомарное относительное начисление
        // от СВЕЖЕГО золота (increaseGold → CharacterStatsService).
        // Зеркальный близнец (2026-07-27): результат начисления тоже проверяется ДО
        // списания ресурса. increaseGold возвращает false, когда персонажа не нашли —
        // раньше в этом случае ресурс всё равно исчезал из инвентаря, а золото не
        // приходило (потеря ценности в другую сторону).
        $sellerIdRaw = $character['id'] ?? null;
        $sellerId    = is_numeric($sellerIdRaw) ? (int) $sellerIdRaw : 0;

        if (! $this->characterModel->increaseGold($sellerId, $saleAmount)) {
            return [
                'success' => false,
                'message' => 'Не удалось начислить золото — продажа отменена, ресурс остался у вас.',
            ];
        }

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
     * Запись покупки в `action_log` (зеркало `SELL_RESOURCE`). Своё исключение глотаем:
     * форензика не должна ронять уже проведённую сделку.
     */
    private function logPurchase(int $characterId, int $chatId, int $resourceId, int $qty, int $totalCost): void
    {
        try {
            (new \App\Models\ActionLogModel())->save([
                'character_id'  => $characterId,
                'chat_id'       => $chatId,
                'action_name'   => 'BUY_RESOURCE',
                'action_status' => 'Completed',
                'description'   => mb_substr("res={$resourceId} qty={$qty} gold=-{$totalCost}", 0, 500),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[ResourceTradeService::logPurchase] insert failed: ' . $e->getMessage());
        }
    }

    /**
     * WB2 (ADR-137): единый предикат «ресурс можно продать». ResourceEntity кастует
     * is_tradeable в bool (casts['is_tradeable']='boolean'), сырой ряд даёт 0/1,
     * отсутствие колонки → считаем ходовым (true). Зеркалит normalizeResourceRow /
     * planBulkSale, чтобы одиночная и оптовая продажа судили ресурс одинаково.
     *
     * @param array<string,mixed>|\App\Entities\ResourceEntity $resource
     */
    public static function resourceIsTradeable($resource): bool
    {
        $raw = $resource['is_tradeable'] ?? true;
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? ((int) $raw === 1) : true;
    }

    /**
     * Покупка `qty` единиц ресурса по `buy_price`.
     *
     * @param array<string,mixed> $character
     * @return array{success:bool, message:string, qty?:int, cost?:int}
     */
    public function buyResource(array $character, int $resourceId, int $qty, int $chatId = 0): array
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            return ['success' => false, 'message' => 'Ресурс не найден в базе.'];
        }
        if ($qty <= 0) {
            return ['success' => false, 'message' => 'Некорректное количество для покупки.'];
        }

        // Покупка ОБЯЗАНА уважать is_tradeable ровно так же, как продажа (строка выше в
        // sellResource). Без этого семена (`is_tradeable=0`, `buy_price=0.00`) отдавались
        // магазином бесплатно и в любом количестве, хотя крафт берёт за них ресурсы.
        if (! self::resourceIsTradeable($resource)) {
            return ['success' => false, 'message' => 'Этот ресурс не продаётся в магазине — его добывают или выращивают.'];
        }

        // Гейт уровня: витрина показывает все редкости, и персонаж 1 уровня мог купить
        // вещь с `level_required=100`. Килсвитч — на случай, если гейт окажется резким.
        if ($this->gsBool('economy.shop.buy_level_gate_enabled', true)) {
            $needLevelRaw = $resource['level_required'] ?? 0;
            $needLevel    = is_numeric($needLevelRaw) ? (int) $needLevelRaw : 0;
            $charLevelRaw = $character['level'] ?? 0;
            $charLevel    = is_numeric($charLevelRaw) ? (int) $charLevelRaw : 0;
            if ($needLevel > 0 && $charLevel < $needLevel) {
                $nameRaw  = $resource['name'] ?? '';
                $nameSafe = is_string($nameRaw) ? $nameRaw : '';

                return [
                    'success' => false,
                    'message' => "Торговец не отдаёт *{$nameSafe}* новичку: нужен *{$needLevel}* уровень (у тебя *{$charLevel}*).",
                ];
            }
        }

        $totalCost = $this->totalFor($qty, $this->unitPrice($resource, false));
        if ((int) $character['gold'] < $totalCost) {
            return ['success' => false, 'message' => "У вас недостаточно золота для покупки {$qty} ед. (нужно {$totalCost}💰)."];
        }

        // Fix 2026-07-27 (последний незакрытый близнец класса lost-update): результат
        // списания ОБЯЗАН проверяться. Предчек выше судит по снапшоту $character,
        // прочитанному в начале запроса; decreaseGold перепроверяет достаточность от
        // СВЕЖЕГО золота под row-lock'ом (CharacterStatsService) и возвращает false,
        // когда денег уже нет. Без этой ветки параллельная трата (быстрые тапы,
        // webhook-retry Телеграма) роняла списание, а ресурс начислялся всё равно —
        // покупка становилась бесплатной и печатала ценность из воздуха. Зеркалит
        // остальные call-site'ы decreaseGold (Караван, Ремонт, Страховка, Оракул,
        // Телепорт, Подать, Смерть, Магазин поселения).
        $buyerIdRaw = $character['id'] ?? null;
        $buyerId    = is_numeric($buyerIdRaw) ? (int) $buyerIdRaw : 0;

        if (! $this->characterModel->decreaseGold($buyerId, (float) $totalCost)) {
            return [
                'success' => false,
                'message' => "Не удалось списать *{$totalCost}*💰 — золото уже ушло на другое действие. "
                    . 'Проверьте баланс и попробуйте снова.',
            ];
        }

        $this->characterResourceModel->addOrIncreaseResource($buyerId, $resourceId, $qty);
        $this->resourcesBankModel->updatePurchasedQuantity($resourceId, $qty);

        // Форензика спроса. Продажа писала `SELL_RESOURCE` с 10.06, покупка не писала
        // НИЧЕГО — единственным следом был счётчик `resources_bank.resources_purchased`,
        // а он с ADR-175 затухает, то есть историю покупок восстановить было нечем.
        // Пишем в сервисе, а не в экране: у покупки два входа (кнопка и «своё число»
        // через ForceReply), и логирование в одном из них уже разошлось у продажи.
        $this->logPurchase($buyerId, $chatId, $resourceId, $qty, $totalCost);

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
