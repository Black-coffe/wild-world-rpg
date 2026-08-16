<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Caravan;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CaravanModel;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\CaravanService;
use App\Services\Player\DroneService;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W5 (ADR-064) — покупка готового дрона у странствующего NPC-каравана.
 *
 * Callback prefix `caravanBuyDrone_<caravan_id>` (через CallbackPrefixDispatcher).
 *
 * Шаги (DB transaction):
 *   1. Re-validate: caravan активен, на клетке игрока, offer_type drone-семейство,
 *      gold_price>0, gold>=price.
 *   2. DECREMENT characters.gold на gold_price.
 *   3. INSERT crafted_items_log (character_id, crafted_item_id = drone item id,
 *      type='drones', durability_count = battery_max per-type, quantity=1,
 *      status='completed', name_rus = «Боевой дрон» и т.п.).
 *   4. UPDATE caravan SET status='depleted', quantity=0.
 *
 * All-or-nothing. RNG-fence safe: ноль mt_rand.
 */
final class CaravanBuyDroneAction extends BaseAction
{
    private CaravanModel $caravanModel;
    private CraftedItemsLogModel $logModel;
    private CraftedItemsModel $itemModel;
    private CaravanService $service;
    private DroneService $droneService;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->caravanModel = new CaravanModel();
        $this->logModel     = new CraftedItemsLogModel();
        $this->itemModel    = new CraftedItemsModel();
        $this->service      = new CaravanService();
        $this->droneService = new DroneService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }
        if (! $this->service->enabled()) {
            return $this->errReply($chatId, 'Караванов сейчас не видно.');
        }

        $caravanId = $this->parseIdSuffix($this->callbackQuery->getData(), 'caravanBuyDrone_');
        if ($caravanId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор каравана.');
        }

        $caravan = $this->caravanModel->find($caravanId);
        if (! is_array($caravan)) {
            return $this->errReply($chatId, 'Караван не найден.');
        }
        $status = is_string($caravan['status'] ?? null) ? $caravan['status'] : '';
        if ($status !== 'active') {
            return $this->errReply($chatId, 'Караван уже ушёл.');
        }
        $expires = is_string($caravan['expires_at'] ?? null) ? $caravan['expires_at'] : '';
        if ($expires !== '' && strtotime($expires) < time()) {
            return $this->errReply($chatId, 'Караван ушёл (срок истёк).');
        }

        $offerType = is_string($caravan['offer_type'] ?? null) ? $caravan['offer_type'] : '';
        if (! $this->service->isDroneOfferType($offerType)) {
            return $this->errReply($chatId, 'Этот караван не торгует дронами.');
        }

        $charCell    = $this->extractInt($character, 'cell_number');
        $caravanCell = is_numeric($caravan['cell_number'] ?? null) ? (int) $caravan['cell_number'] : 0;
        if ($charCell !== $caravanCell || $charCell <= 0) {
            return $this->errReply($chatId, 'Караван не на твоей клетке.');
        }

        $goldPrice = is_numeric($caravan['gold_price'] ?? null) ? (int) $caravan['gold_price'] : 0;
        if ($goldPrice <= 0) {
            return $this->errReply($chatId, 'Лот не выставлен на продажу.');
        }
        $haveGold = $this->extractInt($character, 'gold');
        if ($haveGold < $goldPrice) {
            $need = $goldPrice - $haveGold;
            return $this->errReply($chatId, "Не хватает {$need} 🪙.");
        }

        $droneNameEng = is_string($caravan['drone_type'] ?? null) ? $caravan['drone_type'] : '';
        if ($droneNameEng === '') {
            return $this->errReply($chatId, 'Лот повреждён: тип дрона неизвестен.');
        }
        $droneItem = $this->itemModel->where('name_eng', $droneNameEng)->first();
        if (! is_array($droneItem)) {
            return $this->errReply($chatId, "Тип дрона {$droneNameEng} не найден в справочнике.");
        }
        $droneItemId = is_numeric($droneItem['id'] ?? null) ? (int) $droneItem['id'] : 0;
        $droneNameRus = is_string($droneItem['name_rus'] ?? null) ? $droneItem['name_rus'] : '';
        if ($droneItemId <= 0) {
            return $this->errReply($chatId, 'Тип дрона повреждён.');
        }

        // Resolve battery_max per-type из DroneService (источник правды).
        $droneType  = $this->service->droneTypeFromOffer($offerType);
        $batteryMax = match ($droneType) {
            'scout'  => $this->droneService->batteryMax(),
            'cargo'  => $this->droneService->cargoBatteryMax(),
            'repair' => $this->droneService->repairBatteryMax(),
            'combat' => $this->droneService->combatBatteryMax(),
            default  => 0,
        };
        if ($batteryMax <= 0) {
            return $this->errReply($chatId, 'Не удалось определить заряд дрона.');
        }

        $charId   = $this->extractInt($character, 'id');
        $charModel = $this->characterModel instanceof CharacterModel
            ? $this->characterModel
            : new CharacterModel();

        $db = Database::connect();
        $db->transStart();

        if (! $charModel->decreaseGold($charId, (float) $goldPrice)) {
            $db->transRollback();
            return $this->errReply($chatId, 'Не удалось списать золото.');
        }

        $this->logModel->insert([
            'character_id'     => $charId,
            'crafted_item_id'  => $droneItemId,
            'type'             => 'drones',
            'direction_craft'  => 'drones',
            'crafting_location'=> 'On base',
            'durability_count' => $batteryMax,
            'quantity'         => 1,
        ]);

        $this->caravanModel->update($caravanId, [
            'status'   => 'depleted',
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            log_message('error', "[CaravanBuyDrone] tx fail char={$charId} caravan={$caravanId}");
            return $this->errReply($chatId, 'Ошибка покупки. Попробуй ещё раз.');
        }

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Дрон куплен!',
        ]);

        $catalog = $this->service->droneOfferCatalog();
        $emoji   = $catalog[$droneType]['emoji'] ?? '🛠';

        $text  = "🚚 *Сделка с караваном*\n\n"
            . "Куплено: {$emoji} *{$droneNameRus}* (×1, full battery)\n"
            . "Списано: *{$goldPrice}* 🪙\n"
            . "_Караван распродал лот и уезжает._\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🗺 Карта',     'callback_data' => 'move'],
                ],
            ],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function extractInt(mixed $row, string $key): int
    {
        if (is_array($row)) {
            $v = $row[$key] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($row)) {
            $v = $row->{$key} ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }

    private function parseIdSuffix(string $callbackData, string $prefix): int
    {
        if (! str_starts_with($callbackData, $prefix)) {
            return 0;
        }
        $rest = substr($callbackData, strlen($prefix));
        return is_numeric($rest) ? (int) $rest : 0;
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);
        return Request::emptyResponse();
    }
}
