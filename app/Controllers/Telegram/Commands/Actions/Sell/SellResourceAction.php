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

            // Если callback_data = sellResource_{resourceId}_{quantity}_sell
            if (count($params) == 4 && $params[3] === 'sell') {
                $quantity = $params[2]; // может быть 'all' или число
                return $this->finalizeSale($character, $resourceId, $quantity);
            }
        }

        return Request::emptyResponse();
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
            if (!$res) continue;

            $quantity   = $cr['quantity'];
            if ($quantity <= 0) continue;

            // Считаем «на сумму» исходя из sell_price
            $totalValue = $quantity * $res['sell_price'];
            $text .= "*{$res['name']}* | "
                . "Единиц: *" . number_format($quantity) . "* | "
                . "На сумму: *" . number_format($totalValue) . "*💰\n\n";

            $btnText = "{$res['name']} | "
                . "📦 " . number_format($quantity) . " | "
                . number_format($totalValue) . "💰";

            // Кнопка для выбора этого ресурса
            $keyboardButtons[] = [[
                'text'          => $btnText,
                'callback_data' => "sellResource_{$res['id']}_quantity"
            ]];
        }

        // Если не нашлось ничего
        if (empty($keyboardButtons)) {
            $text = "У вас нет ресурсов редкости {$rarity} для продажи.";
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

        $text = "Выберите количество для продажи ресурса:\n 📦 *{$resource['name']}*:\n"
            . "Текущая цена продажи (за 1 ед.) = *{$resource['sell_price']}* 💰";

        $keyboardButtons = [
            [
                ['text' => '1',    'callback_data' => "sellResource_{$resourceId}_1_sell"],
                ['text' => '5',    'callback_data' => "sellResource_{$resourceId}_5_sell"],
                ['text' => '10',   'callback_data' => "sellResource_{$resourceId}_10_sell"],
                ['text' => '15',   'callback_data' => "sellResource_{$resourceId}_15_sell"],
            ],
            [
                ['text' => '25',  'callback_data' => "sellResource_{$resourceId}_25_sell"],
                ['text' => '50', 'callback_data' => "sellResource_{$resourceId}_50_sell"],
                ['text' => '100', 'callback_data' => "sellResource_{$resourceId}_100_sell"],
                ['text' => '150', 'callback_data' => "sellResource_{$resourceId}_150_sell"],
            ],
            [
                ['text' => '250',  'callback_data' => "sellResource_{$resourceId}_250_sell"],
                ['text' => '500', 'callback_data' => "sellResource_{$resourceId}_500_sell"],
                ['text' => '1000', 'callback_data' => "sellResource_{$resourceId}_1000_sell"],
                ['text' => '5000', 'callback_data' => "sellResource_{$resourceId}_5000_sell"],
            ],
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
     * Собственно, продажа
     */
    protected function finalizeSale(array $character, int $resourceId, $quantityAction): ServerResponse
    {
        // Проверяем, есть ли ресурс у игрока
        $charRes = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->where('id_resources',  $resourceId)
            ->first();

        if (!$charRes) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "У вас нет такого ресурса.",
            ]);
        }

        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Ресурс не найден.",
            ]);
        }

        // Определяем кол-во к продаже
        $sellQuantity = 0;
        if ($quantityAction === 'all') {
            $sellQuantity = $charRes['quantity'];
        } else {
            $sellQuantity = min((int)$quantityAction, $charRes['quantity']);
        }

        if ($sellQuantity <= 0) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Некорректное количество для продажи.",
            ]);
        }

        // Считаем сумму исходя из sell_price
        $saleAmount = $sellQuantity * $resource['sell_price'];

        // Даём игроку золото
        $this->characterModel->update($character['id'], [
            'gold' => $character['gold'] + $saleAmount
        ]);

        // Уменьшаем или удаляем ресурс из инвентаря
        $newQuantity = $charRes['quantity'] - $sellQuantity;
        if ($newQuantity > 0) {
            $this->characterResourceModel->update($charRes['id'], ['quantity' => $newQuantity]);
        } else {
            $this->characterResourceModel->delete($charRes['id']);
        }

        // Обновляем счётчик sold в resources_bank
        $bank = $this->resourcesBankModel->where('resource_id', $resourceId)->first();
        if ($bank) {
            $newSold = $bank['resources_sold'] + $sellQuantity;
            $this->resourcesBankModel->update($bank['id'], [
                'resources_sold' => $newSold,
                'last_update'    => date('Y-m-d H:i:s'),
            ]);
        } else {
            // Если не было записи, создаём
            $this->resourcesBankModel->insert([
                'resource_id'       => $resourceId,
                'current_quantity'  => 0,
                'resources_sold'    => $sellQuantity,
                'last_update'       => date('Y-m-d H:i:s'),
            ]);
        }

        // (Необязательно) Пересчитать цены сразу
        // (new ResourceBankUpdateHandler())->process();

        // Формируем ответ
        $caption = "Продажа ресурса *'{$resource['name']}'* в количестве *{$sellQuantity}* "
            . "успешно выполнена.\n"
            . "Вы заработали *{$saleAmount}*💰.\n"
            . "(Цена за штуку была *{$resource['sell_price']}*)";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 Продать', 'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/vendor_kiosk_in_the_game_world.png'); // Подставьте реальный путь
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $caption,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
