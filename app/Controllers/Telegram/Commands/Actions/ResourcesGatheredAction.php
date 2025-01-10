<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;

class ResourcesGatheredAction extends BaseAction
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

        $characterResources = $this->characterResourceModel
            ->select('character_resources.quantity, resources.name, resources.rarity, resources.price')
            ->join('resources', 'resources.id = character_resources.id_resources')
            ->where('character_resources.id_characters', $character['id'])
            ->findAll();

        if (empty($characterResources)) {
            $text = "🤷‍♂️ *Не переживай, друг!* Всё ещё впереди.\n\n"
                . "Тебе всего лишь нужно раз выйти за лутом, и твой складской сундук наполнится сокровищами! 🗝️💎\n\n"
                . "Собери свои снаряжение, наберись смелости и вперёд к приключениям! 🏹🧭\n\n"
                . "И помни, каждый великий начинал с малого! 🌟";
        } else {
            // Группировка по редкости и сортировка
            $resourcesByRarity = [];
            foreach ($characterResources as $resource) {
                $resourcesByRarity[$resource['rarity']][] = $resource;
            }

            ksort($resourcesByRarity); // Сортировка массива по ключам (редкости)

            $totalValue = 0;
            $text = "*Твой склад наполнен такими ресурсами:*\n\n";

            // Вывод отсортированных данных
            foreach ($resourcesByRarity as $rarity => $resources) {
                $text .= "*Редкость $rarity:*\n";
                foreach ($resources as $resource) {
                    $text .= "📦 " . $resource['name'] . " | " . number_format($resource['quantity']) . " еднц.\n";
                    $totalValue += $resource['price'] * $resource['quantity'];
                }
                $text .= "\n";
            }

            $text .= "\n*Общая стоимость ресурсов ~ " . number_format($totalValue) . " 💰*\n";
        }


        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '‍👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ],
                [
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment']
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
}