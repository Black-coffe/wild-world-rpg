<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Caravan;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CaravanModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Services\Player\CaravanService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V25 (ADR-057) — покупка ВСЕГО offer'а каравана за gold.
 *
 * Callback `caravanBuyAll_<caravan_id>`: re-validate, рассчитать affordable qty
 * = min(caravan.quantity, character.gold / price), списать gold, добавить
 * ресурс через CharacterResourceModel::increaseResources, обновить
 * caravan.quantity = quantity - bought (status=depleted если 0).
 */
class CaravanBuyAction extends BaseAction
{
    private CaravanModel $caravanModel;
    private ResourceModel $resModel;
    private CharacterResourceModel $charResModel;
    private CaravanService $service;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->caravanModel = new CaravanModel();
        $this->resModel     = new ResourceModel();
        $this->charResModel = new CharacterResourceModel();
        $this->service      = new CaravanService();
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

        // W14b (ADR-068): bargained-покупка (caravanBuyBargain_<id>) применяет скидку торга,
        // пересчитанную от trading_karma (не доверяем callback — server recompute).
        $data      = (string) $this->callbackQuery->getData();
        $bargained = str_starts_with($data, 'caravanBuyBargain_');
        $caravanId = $this->parseIdSuffix($data, $bargained ? 'caravanBuyBargain_' : 'caravanBuyAll_');
        if ($caravanId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор каравана.');
        }

        $caravan = $this->caravanModel->find($caravanId);
        if (! is_array($caravan)) {
            return $this->errReply($chatId, 'Караван не найден.');
        }
        $rawStatus = $caravan['status'] ?? '';
        $status    = is_string($rawStatus) ? $rawStatus : '';
        if ($status !== 'active') {
            return $this->errReply($chatId, 'Караван уже ушёл.');
        }
        $rawExpires = $caravan['expires_at'] ?? '';
        $expires    = is_string($rawExpires) ? $rawExpires : '';
        if ($expires !== '' && strtotime($expires) < time()) {
            return $this->errReply($chatId, 'Караван ушёл (срок истёк).');
        }

        $charCell   = $this->extractInt($character, 'cell_number');
        $rawCell    = $caravan['cell_number'] ?? null;
        $caravanCell = is_numeric($rawCell) ? (int) $rawCell : 0;
        if ($charCell !== $caravanCell || $charCell <= 0) {
            return $this->errReply($chatId, 'Караван не на твоей клетке.');
        }

        $rawQty   = $caravan['quantity']       ?? null;
        $rawPrice = $caravan['price_per_unit'] ?? null;
        $qty   = is_numeric($rawQty)   ? (int) $rawQty   : 0;
        $price = is_numeric($rawPrice) ? (int) $rawPrice : 0;
        if ($qty <= 0 || $price <= 0) {
            return $this->errReply($chatId, 'Караван распродал всё.');
        }

        // W14b: применяем скидку торга (детерминированно от trading_karma) при bargained-покупке.
        if ($bargained && $this->service->bargainEnabled()) {
            $price = $this->service->applyBargain($price, $this->extractInt($character, 'trading_karma'));
        }

        $haveGold = $this->extractInt($character, 'gold');
        $affordableQty = (int) floor($haveGold / $price);
        $buyQty = min($qty, $affordableQty);
        if ($buyQty <= 0) {
            return $this->errReply($chatId, "Не хватает золота: нужно {$price} 🪙 хотя бы за 1 шт.");
        }

        $totalCost = $buyQty * $price;
        $charId = $this->extractInt($character, 'id');

        $charModel = $this->characterModel instanceof CharacterModel
            ? $this->characterModel
            : new CharacterModel();
        if (! $charModel->decreaseGold($charId, (float) $totalCost)) {
            return $this->errReply($chatId, 'Не удалось списать золото.');
        }

        $rawResId = $caravan['resource_id'] ?? null;
        $resourceId = is_numeric($rawResId) ? (int) $rawResId : 0;
        $this->charResModel->increaseResources($charId, $resourceId, $buyQty);

        $remaining = $qty - $buyQty;
        $this->caravanModel->update($caravanId, [
            'quantity' => $remaining,
            'status'   => $remaining <= 0 ? 'depleted' : 'active',
        ]);

        $resName = $this->resolveResourceName($resourceId);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Сделка завершена!',
        ]);

        // W14a (ADR-068): если это товар богатого каравана — у группы могут остаться
        // другие товары → корректное сообщение + кнопка вернуться к каравану.
        $rawGroupId    = $caravan['caravan_group_id'] ?? null;
        $groupId       = is_numeric($rawGroupId) ? (int) $rawGroupId : 0;
        $groupRemaining = $groupId > 0 ? $this->caravanModel->countActiveInGroup($groupId) : 0;

        $text  = "🚚 *Сделка с караваном*\n\n"
            . "Куплено: *{$resName}* × *{$buyQty}*\n"
            . "Списано: *{$totalCost}* 🪙 ({$price}/шт.)\n";
        if ($remaining > 0) {
            $text .= "У каравана осталось: {$remaining} шт.\n";
        } elseif ($groupRemaining > 0) {
            $text .= "_Этот товар распродан, но у каравана ещё есть товары._\n";
        } else {
            $text .= "_Караван распродал всё и уезжает._\n";
        }

        $rows = [];
        if ($remaining > 0 || $groupRemaining > 0) {
            $rows[] = [['text' => '🚚 Караван', 'callback_data' => 'caravanLook']];
        }
        $rows[] = [
            ['text' => '🗺 Карта',     'callback_data' => 'inlineMap'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];
        $keyboard = ['inline_keyboard' => $rows];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function resolveResourceName(int $resourceId): string
    {
        if ($resourceId <= 0) {
            return '???';
        }
        $row = $this->resModel->find($resourceId);
        if ($row === null) {
            return '???';
        }
        $name = $row['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : '???';
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
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }
}
