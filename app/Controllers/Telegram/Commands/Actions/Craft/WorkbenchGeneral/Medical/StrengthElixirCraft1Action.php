<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // 1) Подключаем модель лога крафта
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StrengthElixirCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // 2) Добавляем свойство

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // 3) Инициализируем
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

        // Предположим, что в базе (crafted_items / crafted_items_log) itemNameEng = 'StrengtheningElixir'
        $itemNameEng = 'TonicElixir';

        // Получаем, сколько уже есть «Укрепляющих эликсиров»
        $elixirQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Формируем заголовок с учётом количества
        $itemTitle = '🧪 Укрепляющий эликсир!';
        if ($elixirQuantity > 0) {
            $itemTitle .= " (в инв. – {$elixirQuantity} шт.)";
        }

        // Далее идёт неизменённая логика проверки ресурсов
        $requiredResources = [
            'Мед'  => 2,
            'Ягоды' => 3,
            'Вода'  => 20,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Вместо жёсткой строки "🧪 Укрепляющий эликсир!" используем $itemTitle:
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $text .= "📦 {$resource['name']} - {$requiredResources[$resource['name']]} ед."
                . " (в наличии {$resource['quantity']} ед. редк - {$resource['rarity']})\n";
        }

        $text .= "\n*Стоимость на рынке:* _52_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта:* _7 минут_\n\n"
            . "*Описание:* Данный предмет из раздела лекарств, который помогает восстановить здоровье +18 ед., и выносливость +16 ед.\n\n";

        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',   'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'craftStrengtheningElixir'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/tonic_elixir.jpg');
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
     * Получаем кол-во предмета из crafted_items_log (name_eng) для персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагается, что в вашей модели CraftedItemsLogModel
        // есть метод getItemByNameEngAndCharacterId($itemNameEng, $characterId).
        // Либо вы можете написать свой query самостоятельно.
        if (!method_exists($this->craftedItemsLogModel, 'getItemByNameEngAndCharacterId')) {
            return 0; // временно или по умолчанию
        }

        $logEntry = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logEntry ? (int) $logEntry['quantity'] : 0;
    }

    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            if ($resource) {
                $charResource = $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId);
                $results[] = [
                    'name'     => $name,
                    'quantity' => $charResource ? $charResource['quantity'] : 0,
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
