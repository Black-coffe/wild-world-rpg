<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Caravan;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CaravanModel;
use App\Models\ResourceModel;
use App\Services\Player\CaravanService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V25 (ADR-057) — экран странствующего NPC-каравана на клетке игрока.
 *
 * Callback: `caravanLook` — без аргументов; ищет ВСЕ активные караваны на
 * `characters.cell_number`. Если несколько — рисует список; если один — сразу
 * детальная карточка с кнопкой «🛍 Купить N».
 */
class CaravanLookAction extends BaseAction
{
    private CaravanModel $caravanModel;
    private ResourceModel $resModel;
    private CaravanService $service;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->caravanModel = new CaravanModel();
        $this->resModel     = new ResourceModel();
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

        $cellNumber = $this->extractInt($character, 'cell_number');
        if ($cellNumber <= 0) {
            return $this->errReply($chatId, 'Невозможно определить твою клетку.');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $caravans = $this->caravanModel->findActiveOnCell($cellNumber);
        if (empty($caravans)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚚 На этой клетке нет каравана. Возможно, он уже ушёл.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $caravan = $caravans[0];
        $rawResId = $caravan['resource_id'] ?? null;
        $resourceId = is_numeric($rawResId) ? (int) $rawResId : 0;
        $resName  = $this->resolveResourceName($resourceId);

        $rawQty   = $caravan['quantity']       ?? null;
        $rawPrice = $caravan['price_per_unit'] ?? null;
        $rawId    = $caravan['id']             ?? null;
        $qty       = is_numeric($rawQty)   ? (int) $rawQty   : 0;
        $price     = is_numeric($rawPrice) ? (int) $rawPrice : 0;
        $caravanId = is_numeric($rawId)    ? (int) $rawId    : 0;
        $total     = $qty * $price;
        $haveGold  = $this->extractInt($character, 'gold');

        $rawExpires = $caravan['expires_at'] ?? '';
        $expires    = is_string($rawExpires) ? $rawExpires : '';
        $expiresShort = $expires !== '' ? substr($expires, 11, 5) : '??:??';

        $text  = "🚚 *Странствующий караван*\n\n";
        $text .= "Перед тобой стоит крытая повозка. Торговец предлагает:\n\n";
        $text .= "📦 *{$resName}* — {$qty} шт.\n";
        $text .= "💰 Цена: *{$price}* 🪙 за единицу (скидка к рынку!)\n";
        $text .= "💼 Полная сделка: *{$total}* 🪙\n";
        $text .= "⏱ Караван уйдёт около *{$expiresShort}*.\n\n";
        $text .= "💰 У тебя: *{$haveGold}* 🪙";

        if ($qty <= 0) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text . "\n\n_Караван уже распродал всё._",
                'parse_mode' => 'Markdown',
            ]);
        }

        if ($haveGold < $price) {
            $text .= "\n\n❌ _Не хватает золота даже на 1 шт. ({$price} 🪙)._";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🗺 Карта', 'callback_data' => 'inlineMap']],
                    [['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']],
                ],
            ];
        } else {
            $affordableQty = (int) floor($haveGold / $price);
            $canBuy        = min($qty, $affordableQty);

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => "🛍 Купить всё (×{$canBuy} = " . ($canBuy * $price) . " 🪙)", 'callback_data' => "caravanBuyAll_{$caravanId}"]],
                    [['text' => '🗺 Карта', 'callback_data' => 'inlineMap'], ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']],
                ],
            ];
        }

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

    private function resolveResourceName(int $resourceId): string
    {
        if ($resourceId <= 0) {
            return '???';
        }
        $row = $this->resModel->find($resourceId);
        if ($row === null) {
            return '???';
        }
        // ResourceModel::find() возвращает ResourceEntity (F1.4). Читаем через ArrayAccess.
        $name = $row['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : '???';
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
