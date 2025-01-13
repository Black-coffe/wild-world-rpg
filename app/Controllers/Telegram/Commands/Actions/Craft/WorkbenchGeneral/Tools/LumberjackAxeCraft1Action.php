<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // 1) Подключаем модель лога крафта
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class LumberjackAxeCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // 2) Добавляем свойство

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel(); // 3) Инициализируем модель
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

        // Допустим, у нас в базе «Топор дровосека» хранится как LumberjackAxe (name_eng)
        // или как-то иначе — подставьте правильное значение!
        $axeNameEng = 'LumberjackAxe';

        // Получаем, сколько у игрока уже есть «Топоров дровосека»
        $axeQuantity = $this->getCraftedItemQuantity($characterId, $axeNameEng);

        // Формируем заголовок
        $axeTitle = '🪓 Топор дровосека!';
        if ($axeQuantity > 0) {
            $axeTitle .= " (в инв. – {$axeQuantity} шт.)";
        }

        // Ниже — как у вас было: проверка необходимых ресурсов и формирование текста
        $requiredResources = [
            'Древесина' => 50,
            'Базальт' => 1,
            'Камни' => 10,
        ];
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Собираем текст
        $text = "*{$axeTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $text .= "📦 {$resource['name']} - {$requiredResources[$resource['name']]} ед. (в наличии {$resource['quantity']} ед. редк - {$resource['rarity']})\n";
        }

        $text .= "\n*Стоимость на рынке:* _165_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта:* _14 минут_\n\n"
            . "*Описание:*  Старый каменный, первобытный топор из камня и бревна. Дает +30% к добыче ресурсов связанных с древесиной\n\n";

        // Проверяем, хватает ли ресурсов
        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'craftLumberjackAxeCraft1'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/old-stone-primitive-axe-of-stone-and-logs.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Возвращает количество конкретного предмета в инвентаре персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Ищем запись в crafted_items_log по name_eng и character_id
        $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $item ? (int) $item['quantity'] : 0;
    }

    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        // (Без изменений, как у вас)
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            if ($resource) {
                $characterResource = $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId);
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
        // (Без изменений, как у вас)
        foreach ($resourcesAvailable as $resource) {
            if ($resource['quantity'] < $requiredResources[$resource['name']]) {
                return false;
            }
        }
        return true;
    }
}
