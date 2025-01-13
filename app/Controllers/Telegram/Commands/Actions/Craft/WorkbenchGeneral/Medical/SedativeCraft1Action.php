<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- Модель лога крафта
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class SedativeCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- Добавляем свойство

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // <-- Инициализируем
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

        // Название предмета в базе (англ. поле). Предположим, оно "Sedative"
        $itemNameEng = 'Sedative';

        // Узнаём, сколько уже есть у игрока «Успокоительных»
        $itemQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Формируем заголовок с учётом количества
        $itemTitle = '🫖 Успокоительное!';
        if ($itemQuantity > 0) {
            $itemTitle .= " (в инв. – {$itemQuantity} шт.)";
        }

        // Ниже ваша логика по требуемым ресурсам
        $requiredResources = [
            'Цветы орхидей' => 1,
            'Травы'         => 2,
            'Вода'          => 25,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Формируем описание, используя $itemTitle
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $req  = $requiredResources[$resource['name']];
            $have = $resource['quantity'];
            $rar  = $resource['rarity'];

            $text .= "📦 {$resource['name']} - {$req} ед. (в наличии {$have} ед. редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _40_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта:* _7 мин._\n\n"
            . "*Описание:* Отличный напиток, чтобы взбодриться и даже немного поправить здоровье. "
            . "Восстановит: здоровья +5, выносливости +30\n\n";

        // Проверяем, хватает ли ресурсов
        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',    'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',    'callback_data' => 'buy'],
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить',   'callback_data' => 'craftSedative'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/dry_herb_tea.jpg');

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
     * Возвращает количество предмета (англ. название) из лога крафта, если такой предмет есть у персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагается, что у вас есть метод:
        // $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId)
        // который возвращает запись о предмете или null, если предмета нет.
        $logEntry = (new \App\Models\CraftedItemsLogModel())
            ->getItemByNameEngAndCharacterId($itemNameEng, $characterId);

        return $logEntry ? (int) $logEntry['quantity'] : 0;
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
                    'name'     => $name,
                    'quantity' => $characterResource ? $characterResource['quantity'] : 0,
                    'rarity'   => $resource['rarity']
                ];
            }
        }
        return $results;
    }

    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources)
    {
        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            if ($res['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }
}
