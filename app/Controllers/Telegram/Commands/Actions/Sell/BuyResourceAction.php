<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterModel;
use App\Models\ResourcesBankModel;
// Если хотите после сделки тут же пересчитывать цены:
use App\TaskHandlers\ResourceBankUpdateHandler;

class BuyResourceAction extends BaseAction
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
        // При входе в покупку можно (опционально) обновить цены
        // $this->updateResourcePrices();

        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return $this->respondWithMessage('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // Минимальная проверка золота
        if ($character['gold'] < 10) {
            return $this->respondWithMessage('К сожалению, у вас недостаточно золотых монет для торговли!');
        }

        $callbackData = $this->callbackQuery->getData();
        $params       = explode('_', $callbackData);

        // buyResource
        //  └── rarity_{число}
        //  └── select_{resourceId}
        //  └── quantity_{resourceId}_{количество}
        if (!isset($params[1])) {
            // Показываем стартовое окно выбора редкости
            return $this->showStartScreen($character);
        }

        switch ($params[1]) {
            case 'rarity':
                $rarity = $params[2] ?? null;
                if ($rarity) {
                    return $this->showResourcesOfRarity($rarity);
                }
                break;

            case 'select':
                $resourceId = $params[2] ?? null;
                if ($resourceId) {
                    return $this->askForQuantity($resourceId);
                }
                break;

            case 'quantity':
                $resourceId = $params[2] ?? null;
                $quantity   = $params[3] ?? null;
                if ($resourceId && $quantity) {
                    return $this->finalizePurchase($character, $resourceId, $quantity);
                }
                break;
        }

        return Request::emptyResponse();
    }


    /**
     * Простой метод для отправки текстового сообщения с кнопками «Персонаж, Инвентарь, Магазин»
     */
    protected function respondWithMessage(string $text): ServerResponse
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',    'callback_data' => 'shop'],
                ],
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Стартовый экран, где игроку предлагают выбрать редкость для покупки
     */
    protected function showStartScreen(array $character): ServerResponse
    {
        $goldAmount = number_format($character['gold']);
        $text = "👉*У тебя есть* _{$goldAmount}_ *золотых монет*💰\n\n"
            . "📌Выбери редкость ресурсов, которые хочешь купить:";

        // Кнопки по редкостям
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
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Показать список ресурсов указанной редкости. Показываем buy_price.
     */
    protected function showResourcesOfRarity(int $rarity): ServerResponse
    {
        $resources = $this->resourceModel->where('rarity', $rarity)->findAll();
        if (empty($resources)) {
            return $this->respondWithMessage("*Ресурсы редкости {$rarity} не найдены.*");
        }

        $text            = "📦 *Ресурсы редкости {$rarity}:*\n\n";
        $keyboardButtons = [];
        $row             = [];

        foreach ($resources as $index => $resource) {
            // Показываем текущую цену:
            $text .= "🧺 *{$resource['name']}* | _Цена покупки_: *{$resource['buy_price']}*💰\n\n";

            // Добавляем кнопку для выбора конкретного ресурса
            $row[] = [
                'text'          => $resource['name'],
                'callback_data' => "buy_select_{$resource['id']}"
            ];

            // каждые 2 кнопки в строке
            if (count($row) == 2 || $index == count($resources) - 1) {
                $keyboardButtons[] = $row;
                $row = [];
            }
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
     * Спросить, сколько единиц купить
     */
    protected function askForQuantity(int $resourceId): ServerResponse
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            return $this->respondWithMessage("Ресурс не найден.");
        }

        $text = "🧺 *Выберите желаемое количество*\n📦 _{$resource['name']}_ *для покупки.*\n"
            . "Текущая цена за 1 ед: *{$resource['buy_price']}* 💰";

        // Предустановленные варианты
        $keyboardButtons = [
            'inline_keyboard' => [
                [
                    ['text' => '1 ед.',    'callback_data' => "buy_quantity_{$resourceId}_1"],
                    ['text' => '5 ед.',    'callback_data' => "buy_quantity_{$resourceId}_5"],
                    ['text' => '10 ед.',   'callback_data' => "buy_quantity_{$resourceId}_10"],
                    ['text' => '15 ед.',   'callback_data' => "buy_quantity_{$resourceId}_15"],
                ],
                [
                    ['text' => '25 ед.',   'callback_data' => "buy_quantity_{$resourceId}_25"],
                    ['text' => '50 ед.',   'callback_data' => "buy_quantity_{$resourceId}_50"],
                    ['text' => '100 ед.',  'callback_data' => "buy_quantity_{$resourceId}_100"],
                    ['text' => '150 ед.',  'callback_data' => "buy_quantity_{$resourceId}_150"],
                ],
                [
                    ['text' => '250 ед.',  'callback_data' => "buy_quantity_{$resourceId}_250"],
                    ['text' => '500 ед.',  'callback_data' => "buy_quantity_{$resourceId}_500"],
                    ['text' => '1000 ед.', 'callback_data' => "buy_quantity_{$resourceId}_1000"],
                    ['text' => '5000 ед.', 'callback_data' => "buy_quantity_{$resourceId}_5000"],
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboardButtons),
        ]);
    }

    /**
     * Финальный этап — купить указанный ресурс
     */
    protected function finalizePurchase(array $character, int $resourceId, int $quantity): ServerResponse
    {
        $resource = $this->resourceModel->find($resourceId);
        if (!$resource) {
            return $this->respondWithMessage("Ресурс не найден в базе.");
        }

        // Проверка денег
        $totalCost = $quantity * $resource['buy_price'];
        if ($character['gold'] < $totalCost) {
            return $this->respondWithMessage("У вас недостаточно золота для покупки {$quantity} ед.");
        }

        // Списываем золото
        $this->characterModel->decreaseGold($character['id'], $totalCost);

        // Добавляем ресурс игроку (в character_resources)
        $this->characterResourceModel->addOrIncreaseResource($character['id'], $resourceId, $quantity);

        // Увеличиваем счётчик purchases в resources_bank
        $this->resourcesBankModel->updatePurchasedQuantity($resourceId, $quantity);

        // (Необязательно) После сделки сразу пересчитываем buy_price/sell_price
        // (new ResourceBankUpdateHandler())->process();

        $message = "Вы успешно купили *{$quantity}* ед. ресурса *{$resource['name']}* "
            . "по цене *{$resource['buy_price']}*💰 за штуку.\n\n"
            . "Итого потрачено: *{$totalCost}* 💰";

        return $this->respondWithMessage($message);
    }
}
