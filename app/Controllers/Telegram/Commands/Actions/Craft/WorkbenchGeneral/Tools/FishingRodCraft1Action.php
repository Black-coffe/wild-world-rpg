<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- чтобы узнавать, сколько предметов у игрока
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class FishingRodCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- свойство для лога крафта

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel(); // <-- инициализация
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $characterId = $character['id'];

        // Название предмета в базе (англ. поле), например "FishingRod"
        $fishingRodNameEng = 'FishingRod';

        // Получаем, сколько уже есть «Удочек» у персонажа
        $fishingRodQuantity = $this->getCraftedItemQuantity($characterId, $fishingRodNameEng);

        // Формируем заголовок с учётом количества
        $fishingRodTitle = '🎣 Удочка!';
        if ($fishingRodQuantity > 0) {
            $fishingRodTitle .= " (в инв. – {$fishingRodQuantity} шт.)";
        }

        // Остальная логика та же, только подставляем заголовок:
        $requiredResources = [
            'Древесина' => 10,
            'Кожа животных' => 1,
            'Шёлк пауков-пустынников' => 5,
            'Улитки и моллюски' => 15,
            'Шерсть животных' => 3,
            'Лианы' => 5,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Используем $fishingRodTitle вместо жёсткой строки "🎣 Удочка!"
        $text = "*{$fishingRodTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $text .= "📦 {$resource['name']} - {$requiredResources[$resource['name']]} ед. "
                . "(в наличии {$resource['quantity']} ед. редк - {$resource['rarity']})\n";
        }

        $text .= "\n*Стоимость на рынке:* _160_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта:* _14 минут_\n\n"
            . "*Описание:* Хорошая удочка для ловли рыбешки, дает +30% к обычной добычи рыбы.\n\n";

        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'craftFishingRod'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/high-quality-fishing-rod.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Возвращает количество конкретного предмета (англ. название) у персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Ищем запись в crafted_items_log по name_eng и ID персонажа
        $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $item ? (int) $item['quantity'] : 0;
    }

    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            if ($resource) {
                $characterResource = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($name, $characterId);
                $results[] = [
                    'name' => $name,
                    'quantity' => $characterResource ? $characterResource['quantity'] : 0,
                    'rarity' => $resource['rarity']
                ];
            }
        }
        return $results;
    }

    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources)
    {
        foreach ($resourcesAvailable as $resource) {
            if ($resource['quantity'] < $requiredResources[$resource['name']]) {
                return false;
            }
        }
        return true;
    }
}
