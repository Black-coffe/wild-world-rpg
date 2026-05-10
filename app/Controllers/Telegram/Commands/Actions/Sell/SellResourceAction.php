<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\ResourcesBankModel;
// Если хотим сразу пересчитывать цены после сделки
use App\TaskHandlers\ResourceBankUpdateHandler;

class SellResourceAction extends BaseAction
{
    protected $resourceModel;
    protected $characterResourceModel;
    protected $characterModel;
    protected $resourcesBankModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->characterModel         = new CharacterModel();
        $this->resourcesBankModel     = new ResourcesBankModel();
    }

    public function handle(): ServerResponse
    {
        // (Опционально) обновляем цены
        // $this->updateResourcePrices();

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $callbackData = $this->callbackQuery->getData();
        $params       = explode('_', $callbackData);

        // sellResource
        //  └── rarity_{число}
        //  └── {resourceId}_quantity
        //  └── {resourceId}_{число}_sell

        // Выбор редкости?
        if (count($params) == 3 && $params[0] === 'sellResource' && $params[1] === 'rarity') {
            $rarity = (int)$params[2];
            return $this->showResourcesOfRarity($character['id'], $rarity);
        }

        // Если callback_data = sellResource_{resourceId}_quantity
        if (count($params) >= 3) {
            $resourceId = (int)$params[1];

            // пользователь выбрал ресурс, не указав кол-во
            if ($params[2] === 'quantity' && count($params) == 3) {
                return $this->askForQuantity($character['id'], $resourceId);
            }

            // Идея #6 (Arseny, 21.01.2025): свободный ввод qty через ForceReply.
            if ($params[2] === 'custom' && count($params) == 3) {
                return $this->promptCustomQuantity($resourceId);
            }

            // Если callback_data = sellResource_{resourceId}_{quantity}_sell
            if (count($params) == 4 && $params[3] === 'sell') {
                $quantity = $params[2]; // может быть 'all' или число
                return $this->finalizeSale($character, $resourceId, $quantity);
            }
        }

        return Request::emptyResponse();
    }

    /**
     * Идея #6: ForceReply prompt для произвольного qty.
     * Маркер `[SELL:{id}]` в тексте позволяет GenericmessageCommand роутить
     * ответ обратно в SellResourceAction::finalizeSale.
     */
    protected function promptCustomQuantity(int $resourceId): ServerResponse
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ресурс не найден.',
            ]);
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => "📝 Введите число для продажи *{$resource['name']}* (1 ед. = {$resource['sell_price']}💰).\n\nОтветьте на это сообщение числом. [SELL:{$resourceId}]",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['force_reply' => true, 'selective' => true]),
        ]);
    }

    /**
     * Показать ресурсы нужной редкости, учитывая их sell_price
     */
    protected function showResourcesOfRarity(int $characterId, int $rarity): ServerResponse
    {
        // Находим все ресурсы такой редкости
        $resources = $this->resourceModel->where('rarity', $rarity)->findAll();
        if (empty($resources)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ресурсы редкости {$rarity} не найдены!",
            ]);
        }

        // Получаем, какие из них и в каком кол-ве есть у персонажа
        $characterResources = $this->characterResourceModel
            ->where('id_characters', $characterId)
            ->whereIn('id_resources', array_column($resources, 'id'))
            ->findAll();

        $text            = "📦 *Ресурсы редкости {$rarity}:*\n\n";
        $keyboardButtons = [];

        foreach ($characterResources as $cr) {
            // Смотрим ресурс из $resources, у которого id = $cr['id_resources']
            $res = $this->resourceModel->find($cr['id_resources']);
            if (!$res) {
                continue;
            }

            $quantity = $cr['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            // Считаем «на сумму» исходя из sell_price, добавляем "~" перед значением
            $totalValue = $quantity * $res['sell_price'];
            $text .= "*{$res['name']}* | "
                . "Единиц: *" . number_format($quantity) . "* | "
                . "На сумму: ~" . number_format($totalValue) . "💰\n";

            // Формируем текст кнопки
            $btnText = "{$res['name']} | "
                . "📦 " . number_format($quantity) . " | "
                . "~" . number_format($totalValue) . "💰";

            // Кнопка для выбора этого ресурса
            $keyboardButtons[] = [[
                'text'          => $btnText,
                'callback_data' => "sellResource_{$res['id']}_quantity"
            ]];
        }

        // Если не нашлось ничего
        if (empty($keyboardButtons)) {
            $text = "У вас нет ресурсов редкости {$rarity} для продажи.";
        } else {
            // Добавляем пояснение о том, что цена может отличаться
            $text .= "\n*❗️Реальная цена может быть другой исходя из спроса ресурса❗️*";
        }

        $keyboard = ['inline_keyboard' => $keyboardButtons];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Предложить пользователю ввести/выбрать кол-во
     */
    protected function askForQuantity(int $characterId, int $resourceId): ServerResponse
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ресурс не найден.',
            ]);
        }

        // Идея #15 (Arseny, 16.04.2025): прозрачная торговля — показываем
        // итоговую сумму прямо в кнопках, а не только цену за 1 ед.
        $unitPrice = (int) $resource['sell_price'];

        $text = "Выберите количество для продажи ресурса:\n 📦 *{$resource['name']}*:\n"
            . "Текущая цена продажи (за 1 ед.) = *{$unitPrice}* 💰";

        $btn = static function (int $qty) use ($resourceId, $unitPrice): array {
            $total = $qty * $unitPrice;
            return [
                'text'          => "{$qty} → " . number_format($total) . "💰",
                'callback_data' => "sellResource_{$resourceId}_{$qty}_sell",
            ];
        };

        $keyboardButtons = [
            [$btn(1),   $btn(5),    $btn(10),   $btn(15)],
            [$btn(25),  $btn(50),   $btn(100),  $btn(150)],
            [$btn(250), $btn(500),  $btn(1000), $btn(5000)],
            [['text' => '📝 Своё число', 'callback_data' => "sellResource_{$resourceId}_custom"]],
        ];

        $keyboard = ['inline_keyboard' => $keyboardButtons];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Собственно, продажа — делегирует в ResourceTradeService.
     */
    protected function finalizeSale(array|\App\Entities\CharacterEntity $character, int $resourceId, $quantityAction): ServerResponse
    {
        $svc    = new \App\Services\Player\Trade\ResourceTradeService();
        $result = $svc->sellResource(is_array($character) ? $character : (array) $character, $resourceId, $quantityAction);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!$result['success']) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => $result['message']]);
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ],
        ];
        $imagePath = base_url('uploads/telegram/vendor_kiosk_in_the_game_world.png');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $result['message'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
