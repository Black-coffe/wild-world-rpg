<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BasicMedKitCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
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

        // Здесь логические имена (Bandage) остаются для поиска,
        // но конечное название в сообщении будем брать из 'display_name'.
        $requiredResources = [
            'resources' => [
                'Грибы' => 4,
                'Мед'   => 2,
                'Алоэ'  => 4,
                'Вода'  => 11,
            ],
            'crafted_items' => [
                'Bandage' => 5,
            ],
        ];

        // Проверяем, что у нас есть нужное количество (и получаем русские названия)
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Формируем сообщение
        $text = "*🚑 Аптечка базовая!*\n\nДля крафта предмета тебе нужны:\n\n";

        // Перебираем и ресурсы, и крафтовые предметы
        foreach ($requiredResources as $category => $items) {
            foreach ($items as $itemName => $amountRequired) {
                // Смотрим, что вернул checkResourcesAvailability
                if (isset($resourcesAvailable[$itemName])) {
                    $resource = $resourcesAvailable[$itemName];

                    // Здесь подставим название для вывода
                    $displayName = $resource['display_name'] ?? $itemName;

                    $quantityAvailable = $resource['quantity'];
                    // Либо у нас "редкость", либо "тип"
                    $infoDetail = isset($resource['rarity'])
                        ? "редкость - {$resource['rarity']}"
                        : (isset($resource['type']) ? "тип - {$resource['type']}" : "неизвестно");
                } else {
                    // Не найдено — fallback
                    $displayName       = $itemName;
                    $quantityAvailable = 0;
                    $infoDetail        = "неизвестно";
                }

                $text .= "📦 {$displayName} - {$amountRequired} ед. (в наличии {$quantityAvailable} ед., {$infoDetail})\n";
            }
        }

        $text .= "\n*Стоимость на рынке:* _100_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта:* _15 мин._\n\n"
            . "*Описание:* Базовая аптечка 1го уровня, восстановит +40 здоровья, +20 выносливости\n\n";

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
            // Ресурсов достаточно → предлагаем кнопку "Крафтить"
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'craftBasicMedKit'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        // Отправляем итоговое сообщение с фото
        $imagePath = base_url('uploads/telegram/craft/simple_craft_kit.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Проверяем наличие требуемых ресурсов/предметов и дополняем 'display_name',
     * чтобы вывести русское имя в Telegram, если это крафтовый предмет (crafted_items).
     */
    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];

        // 1) Обычные ресурсы (таблица `resources`, поиск по русскому name).
        foreach ($requiredResources['resources'] as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            $charRes  = $resource
                ? $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId)
                : null;

            $results[$name] = [
                'name'         => $name,
                'quantity'     => $charRes ? $charRes['quantity'] : 0,
                'rarity'       => $charRes['rarity'] ?? 'неизвестно',
                // Ресурс уже на русском, значит display_name = $name
                'display_name' => $name,
            ];
        }

        // 2) Крафтовые предметы (таблица `crafted_items`, ищем по name_eng).
        foreach ($requiredResources['crafted_items'] as $nameEng => $amount) {
            // Находим предмет
            $item = $this->craftedItemsModel->getItemByNameEngAndCharacterId($nameEng, $characterId);

            // Сохраняем в results
            $results[$nameEng] = [
                'name'     => $nameEng,
                'quantity' => $item ? $item['quantity'] : 0,
                'type'     => $item['type'] ?? 'неизвестно',
            ];

            // Если предмет найден и есть name_rus, используем его
            if ($item && isset($item['name_rus'])) {
                $results[$nameEng]['display_name'] = $item['name_rus'];
            } else {
                // Иначе fallback на nameEng
                $results[$nameEng]['display_name'] = $nameEng;
            }
        }

        return $results;
    }

    /**
     * Проверяем, хватает ли ресурсов/предметов
     */
    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources)
    {
        // Обычные ресурсы
        foreach ($requiredResources['resources'] as $name => $requiredAmount) {
            if (
                !isset($resourcesAvailable[$name]) ||
                $resourcesAvailable[$name]['quantity'] < $requiredAmount
            ) {
                return false;
            }
        }
        // Крафтовые предметы
        foreach ($requiredResources['crafted_items'] as $nameEng => $requiredAmount) {
            if (
                !isset($resourcesAvailable[$nameEng]) ||
                $resourcesAvailable[$nameEng]['quantity'] < $requiredAmount
            ) {
                return false;
            }
        }

        return true;
    }
}
