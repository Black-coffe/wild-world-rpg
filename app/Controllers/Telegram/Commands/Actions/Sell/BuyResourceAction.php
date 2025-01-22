<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\ResourcesBankModel;

class BuyResourceAction extends BaseAction
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
            return $this->respondWithMessage('Пользователь не найден в базе данных или персонаж не определён.');
        }

        if ($character['gold'] < 10) {
            return $this->respondWithMessage('К сожалению у вас недостаточно золотых монет для торговли!');
        }

        $callbackData = $this->callbackQuery->getData();
        $params = explode('_', $callbackData);

        // Проверяем, есть ли вообще параметры после 'buyResource'
        if (!isset($params[1])) {
            return $this->showStartScreen($character);
        }

        switch ($params[1]) {
            case 'rarity':
                $rarity = $params[2] ?? null;
                if (!is_null($rarity)) {
                    return $this->showResourcesOfRarity($character, $rarity);
                }
                break;
            case 'select':
                $resourceId = $params[2] ?? null;
                if (!is_null($resourceId)) {
                    return $this->askForQuantity($character, $resourceId);
                }
                break;
            case 'quantity':
                $resourceId = $params[2] ?? null;
                $quantity = $params[3] ?? null;
                if (!is_null($resourceId) && !is_null($quantity)) {
                    return $this->finalizePurchase($character, $resourceId, $quantity);
                }
                break;
        }

        return Request::emptyResponse();
    }


    protected function respondWithMessage(string $text)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                ],
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function showStartScreen($character)
    {
        $goldAmount = number_format($character['gold']);
        $text = "👉*У тебя есть* _{$goldAmount}_ *золотых монет*💰\n\n"
            . "📌ВАЖНО📌 Чтобы и тебе, и мне, как торговцу, было проще, пересмотри ресурсы и обрати внимание на их редкость. Ниже отметь цифрой, какой редкости ресурсы ты готов купить. Если их там будет несколько, на следующем шаге ты выберешь конкретный ресурс.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '1️⃣ редкость', 'callback_data' => 'buy_rarity_1'],
                    ['text' => '2️⃣ редкость', 'callback_data' => 'buy_rarity_2'],
                    ['text' => '3️⃣ редкость', 'callback_data' => 'buy_rarity_3'],
                ],
                [
                    ['text' => '4️⃣ редкость', 'callback_data' => 'buy_rarity_4'],
                    ['text' => '5️⃣ редкость', 'callback_data' => 'buy_rarity_5'],
                    ['text' => '6️⃣ редкость', 'callback_data' => 'buy_rarity_6'],
                ],
                [
                    ['text' => '7️⃣ редкость', 'callback_data' => 'buy_rarity_7'],
                    ['text' => '8️⃣ редкость', 'callback_data' => 'buy_rarity_8'],
                    ['text' => '9️⃣ редкость', 'callback_data' => 'buy_rarity_9'],
                ],
                [
                    ['text' => '🔟 редкость', 'callback_data' => 'buy_rarity_10'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ]
            ]
        ];
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
        if (empty($resources)) {
            return $this->respondWithMessage("*Ресурсы редкости {$rarity} не найдены.*");
        }

        $text = "📦 *Ресурсы редкости {$rarity}:*\n\n";
        $keyboardButtons = [];
        $row = []; // Текущий ряд для кнопок
        foreach ($resources as $index => $resource) {
            $text .= "🧺 *{$resource['name']}* | _Стоимость ресурса_: *{$resource['buy_price']}*💰\n\n";
            $row[] = ['text' => $resource['name'], 'callback_data' => "buy_select_{$resource['id']}"];
            // Каждый раз, когда в ряду две кнопки, добавляем его в keyboardButtons и очищаем для следующего ряда
            if (count($row) == 2 || $index == count($resources) - 1) {
                $keyboardButtons[] = $row;
                $row = [];
            }
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

    protected function askForQuantity($character, $resourceId)
    {
        $resource = $this->resourceModel->find($resourceId);
        $text = "🧺 *Выберите желаемое количество ресурса*\n 📦 _{$resource['name']}_ *для покупки:*";

        $keyboardButtons = [
            'inline_keyboard' => [
                [
                    ['text' => '1 ед.', 'callback_data' => "buy_quantity_{$resourceId}_1"],
                    ['text' => '5 ед.', 'callback_data' => "buy_quantity_{$resourceId}_5"],
                    ['text' => '10 ед.', 'callback_data' => "buy_quantity_{$resourceId}_10"],
                ],
                [
                    ['text' => '25 ед.', 'callback_data' => "buy_quantity_{$resourceId}_25"],
                    ['text' => '50 ед.', 'callback_data' => "buy_quantity_{$resourceId}_50"],
                    ['text' => '100 ед.', 'callback_data' => "buy_quantity_{$resourceId}_100"],
                ],
                [
                    ['text' => '250 ед.', 'callback_data' => "buy_quantity_{$resourceId}_250"],
                    ['text' => '500 ед.', 'callback_data' => "buy_quantity_{$resourceId}_500"],
                    ['text' => '1 000 ед.', 'callback_data' => "buy_quantity_{$resourceId}_1000"],
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboardButtons),
        ]);
    }

    protected function finalizePurchase($character, $resourceId, $quantity)
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            return $this->respondWithMessage("Ресурс не найден.");
        }

        // Получаем запись о ресурсе у персонажа
        $resourceRecord = $this->characterResourceModel
            ->where('id_resources', $resourceId)
            ->where('id_characters', $character['id'])
            ->first();

        // Инициализируем количество ресурса у персонажа нулём, если запись не найдена
        $resourceCountInCharacter = $resourceRecord ? $resourceRecord['quantity'] : 0;


        // Получение общего уровня персонажа (в твоем случае используется уровень текущего персонажа)
        $sumOfLevels = $character['level'];

        // Вычисление максимально возможного количества покупки
        $maxPurchaseQuantity = round(
            $resource['initial_quantity'] *
            (1 + $sumOfLevels / 1000) *
            (11 - $resource['rarity'])
        );

        $maxPurchaseQuantityFin = $maxPurchaseQuantity - $resourceCountInCharacter;

        // Проверяем, не превышает ли запрашиваемое количество максимально допустимое
        if ($quantity > $maxPurchaseQuantityFin and $maxPurchaseQuantityFin <= 0) {
            return $this->respondWithMessage("Вы не можете купить такое количество данного ресурса. Лимит для вас: {$maxPurchaseQuantity} уже превышен.");
        }elseif($quantity > $maxPurchaseQuantityFin and $maxPurchaseQuantityFin >= 1) {
            return $this->respondWithMessage("Вы не можете купить такое количество данного ресурса. Максимально доступно: {$maxPurchaseQuantityFin}.");
        }

        $totalCost = $quantity * $resource['buy_price'];
        if ($character['gold'] < $totalCost) {
            return $this->respondWithMessage("У вас недостаточно золота для покупки этого количества.");
        }

        // Списание золота
        $this->characterModel->decreaseGold($character['id'], $totalCost);

        // Добавление ресурсов к персонажу
        $this->characterResourceModel->addOrIncreaseResource($character['id'], $resourceId, $quantity);

        // Обновление количества купленных ресурсов в банке
        $this->resourcesBankModel->updatePurchasedQuantity($resourceId, $quantity);

        $message = "Вы успешно купили $quantity единиц(ы) '{$resource['name']}' за $totalCost золотых монет.";
        return $this->respondWithMessage($message);
    }

}

