<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\ResourcesBankModel;

class SellResourceAction extends BaseAction
{
    protected $resourceModel;
    protected $characterResourceModel;
    protected $characterModel;
    protected $resourcesBankModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->resourceModel = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->characterModel = new CharacterModel();
        $this->resourcesBankModel = new ResourcesBankModel();
    }

    public function handle(): ServerResponse
    {
        $this->updateResourcePrices(); // Обновляем цены перед обработкой запроса
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $callbackData = $this->callbackQuery->getData();
        $params = explode('_', $callbackData);

        // Проверяем, выбрал ли пользователь редкость ресурса
        if (count($params) == 3 && $params[0] === 'sellResource' && $params[1] === 'rarity') {
            $rarity = $params[2];
            return $this->showResourcesOfRarity($character, $rarity);
        }

        // Ожидаем callback_data в формате: sellResource_{resourceId}_quantity или sellResource_{resourceId}_{quantity}_sell
        if (count($params) >= 3) {
            $resourceId = $params[1];

            // Если пользователь только выбрал ресурс, не указав количество
            if ($params[2] === 'quantity' && count($params) == 3) {
                return $this->askForQuantity($character, $resourceId);
            }

            // Если пользователь выбрал конкретное количество для продажи
            if (count($params) == 4 && $params[3] === 'sell') {
                $quantity = $params[2]; // Количество может быть числом или словом 'all'
                return $this->finalizeSale($character, $resourceId, $quantity);
            }
        }

        return Request::emptyResponse();
    }

    protected function askForQuantity($character, $resourceId)
    {
        $resource = $this->resourceModel->find($resourceId);

        if (!$resource) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Ресурс не найден.',
            ]);
        }

        $text = "Выберите количество для продажи ресурса:\n 📦 *{$resource['name']}*:";
        $keyboardButtons = [
            [['text' => '1', 'callback_data' => "sellResource_{$resourceId}_1_sell"]],
            [['text' => '5', 'callback_data' => "sellResource_{$resourceId}_5_sell"]],
            [['text' => '10', 'callback_data' => "sellResource_{$resourceId}_10_sell"]],
            [['text' => '100', 'callback_data' => "sellResource_{$resourceId}_100_sell"]],
            [['text' => '1000', 'callback_data' => "sellResource_{$resourceId}_1000_sell"]],
//            [['text' => 'Все', 'callback_data' => "sellResource_{$resourceId}_all_sell"]],
        ];

        $keyboard = ['inline_keyboard' => $keyboardButtons];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function showResourcesOfRarity($character, $rarity)
    {
        $resources = $this->resourceModel->where('rarity', $rarity)->findAll();
        $characterResources = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->whereIn('id_resources', array_column($resources, 'id'))
            ->findAll();

        $text = "📦 *Ресурсы редкости {$rarity}:*\n\n";
        $keyboardButtons = [];

        foreach ($characterResources as $characterResource) {
            $resource = $this->resourceModel->find($characterResource['id_resources']);
            if ($resource && $characterResource['quantity'] > 0) {
                $quantity = $characterResource['quantity'];
                $totalValue = $quantity * $resource['sell_price'];
                $text .= "*{$resource['name']}* | Единиц: *" . number_format($quantity) . "* | На сумму: *" . number_format($totalValue) . "*💰\n\n";

                // Добавляем название ресурса и общую стоимость всех ресурсов на кнопку
                $buttonText = "{$resource['name']} | " . "📦 Единиц: " . number_format($quantity) . " |" . number_format($totalValue) . "💰";
                $keyboardButtons[] = [['text' => $buttonText, 'callback_data' => "sellResource_{$resource['id']}_quantity"]];
            }
        }

        if(empty($keyboardButtons)) {
            $text = "📦 *Ресурсы редкости {$rarity}* не найдены.\n\n";
        } else {
            $text .= "\nВыберите ресурс для продажи.";
        }

        $keyboard = ['inline_keyboard' => $keyboardButtons];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function finalizeSale($character, $resourceId, $quantityAction)
    {
        $characterResource = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->where('id_resources', $resourceId)
            ->first();

        if (!$characterResource) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => "У вас нет такого ресурса.",
            ]);
        }

        $resource = $this->resourceModel->find($resourceId);
        if ($quantityAction === 'all') {
            $sellQuantity = $characterResource['quantity'];
        } else {
            $sellQuantity = min((int)$quantityAction, $characterResource['quantity']);
        }

        if ($sellQuantity <= 0) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => "Некорректное количество для продажи.",
            ]);
        }

        // Продажа ресурса
        $saleAmount = $sellQuantity * $resource['sell_price'];
        $this->characterModel->update($character['id'], ['gold' => $character['gold'] + $saleAmount]);
        if ($sellQuantity < $characterResource['quantity']) {
            $this->characterResourceModel->update($characterResource['id'], ['quantity' => $characterResource['quantity'] - $sellQuantity]);
        } else {
            $this->characterResourceModel->delete($characterResource['id']);
        }

        // Теперь, после успешной продажи, обновляем данные в resources_bank
        $resourcesBankEntry = $this->resourcesBankModel->where('resource_id', $resourceId)->first();

        if ($resourcesBankEntry) {
            // Если запись найдена, обновляем количество проданных ресурсов
            $newSoldQuantity = $resourcesBankEntry['resources_sold'] + $sellQuantity;
            $this->resourcesBankModel->update($resourcesBankEntry['id'], [
                'resources_sold' => $newSoldQuantity,
                'last_update' => date('Y-m-d H:i:s'), // Также обновляем время последнего изменения
            ]);
        } else {
            // Если запись в банке ресурсов не найдена, создаем новую
            $this->resourcesBankModel->insert([
                'resource_id' => $resourceId,
                'current_quantity' => 0, // Поскольку это продажа, начальное количество устанавливаем в 0
                'resources_sold' => $sellQuantity,
                'last_update' => date('Y-m-d H:i:s'),
            ]);
        }

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
        $imagePath = base_url('uploads/telegram/vendor_kiosk_in_the_game_world.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => "Продажа ресурса *'{$resource['name']}'* в количестве *{$sellQuantity}* успешно выполнена.\nВы заработали {$saleAmount}💰.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

}
