<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class SellSelectRarity1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Выборка ресурсов редкости 1 для данного персонажа
        $resources = $this->resourceModel
            ->where('rarity', 1)
            ->findAll();

        $characterResources = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->whereIn('id_resources', array_column($resources, 'id'))
            ->findAll();

        $text = "📦 *Ресурсы редкости 1:*\n Это очень ценные и редкие ресурсы\n\n";
        $keyboardButtons = [];

        foreach ($characterResources as $characterResource) {
            $resource = $this->resourceModel->find($characterResource['id_resources']);
            if ($resource) {
                $quantity = number_format($characterResource['quantity']);
                $totalValue = $resource['price'] * $characterResource['quantity'];
                $text .= "📦 {$resource['name']} | редкость: {$resource['rarity']} | {$quantity} еднц.\n";
                // Кнопка для каждого ресурса
                $buttonText = "{$resource['name']} | {$quantity} шт | " . number_format($totalValue) . "💰";
                $keyboardButtons[] = ['text' => $buttonText, 'callback_data' => "sellResource_{$resource['id']}"];
            }
        }

        $keyboard = ['inline_keyboard' => array_chunk($keyboardButtons, 1)]; // Для лучшего отображения распределяем кнопки по строкам

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
