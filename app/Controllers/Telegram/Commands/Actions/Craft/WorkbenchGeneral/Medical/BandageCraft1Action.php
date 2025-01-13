<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- Подключаем модель логов крафта
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BandageCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- Добавляем свойство для лога крафта

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // <-- Инициализируем модель логов
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

        // Предположим, что в базе название этого предмета (англ. столбец) — 'Bandage'
        $bandageNameEng = 'Bandage';

        // Узнаём, сколько уже есть «Повязок» (Bandage) у персонажа
        $bandageQuantity = $this->getCraftedItemQuantity($characterId, $bandageNameEng);

        // Формируем заголовок с учётом количества
        $bandageTitle = '🩹 Повязка!';
        if ($bandageQuantity > 0) {
            $bandageTitle .= " (в инв. – {$bandageQuantity} шт.)";
        }

        $requiredResources = [
            'Травы'         => 2,
            'Кора деревьев' => 2,
            'Водоросли'     => 3,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Используем динамический $bandageTitle вместо зашитой строки "*🩹 Повязка!*"
        $text = "*{$bandageTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $text .= "📦 {$resource['name']} - {$requiredResources[$resource['name']]} ед. "
                . "(в наличии {$resource['quantity']} ед. редк - {$resource['rarity']})\n";
        }

        $text .= "\n*Стоимость на рынке:* _20_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта:* _3 мин._\n\n"
            . "*Описание:* Минимальное восстановление здоровья и выносливости. "
            . "Используется для крафта более мощных средств\n\n";

        // Проверяем, достаточно ли ресурсов
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
                        ['text' => '🛍️ Купить',  'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'craftBandage'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg');

        // Отвечаем на нажатие кнопки (чтобы Telegram не «крутил часики» бесконечно)
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем фото с текстом и клавиатурой
        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Узнаём, сколько «Повязок» (англ. name_eng) уже скрафтил персонаж.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагаем, что в CraftedItemsLogModel есть метод getItemByNameEngAndCharacterId()
        // Если нет, реализуйте его по аналогии с другими вашими классами
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
