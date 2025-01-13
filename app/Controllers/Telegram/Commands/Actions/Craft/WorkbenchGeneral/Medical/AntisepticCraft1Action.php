<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- Подключаем модель логов крафта
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class AntisepticCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- Добавляем свойство для лога крафта

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // <-- Инициализируем модель лога
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

        // Название предмета в базе (англ. поле), например "Antiseptic"
        $antisepticNameEng = 'Antiseptic';

        // Узнаём, сколько уже есть «Антисептиков» у персонажа
        $antisepticQuantity = $this->getCraftedItemQuantity($characterId, $antisepticNameEng);

        // Формируем заголовок с учётом количества
        $antisepticTitle = '🧴 Антисептик!';
        if ($antisepticQuantity > 0) {
            $antisepticTitle .= " (в инв. – {$antisepticQuantity} шт.)";
        }

        $requiredResources = [
            'Кактус' => 3,
            'Грибы'  => 1,
            'Вода'   => 10,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Используем $antisepticTitle, а не жёсткую строку "*🧴 Антисептик!*"
        $text = "*{$antisepticTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $text .= "📦 {$resource['name']} - {$requiredResources[$resource['name']]} ед. "
                . "(в наличии {$resource['quantity']} ед. редк - {$resource['rarity']})\n";
        }

        $text .= "\n*Стоимость на рынке:* _30_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта:* _7 минут_\n\n"
            . "*Описание:* Данный предмет из раздела лекарств, который помогает "
            . "предотвратить некоторые виды болезней и укрепить здоровье на +4 ед., и выносливость +2 ед.\n\n";

        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',   'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',     'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',     'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить',    'callback_data' => 'craftAntisepticCraft1'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж',   'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/antiseptic_craft.jpg');

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
     * Узнаём, сколько «Антисептиков» (англ. name_eng) у персонажа в логе скрафченных предметов.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Если в вашей CraftedItemsLogModel есть метод
        // getItemByNameEngAndCharacterId($itemNameEng, $characterId), используем его:
        // Если нет, реализуйте его по аналогии с другими предметами.
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
        foreach ($resourcesAvailable as $resource) {
            if ($resource['quantity'] < $requiredResources[$resource['name']]) {
                return false;
            }
        }
        return true;
    }
}
