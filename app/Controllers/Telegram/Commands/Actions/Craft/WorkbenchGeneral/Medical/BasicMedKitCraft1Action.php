<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
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

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        $text = "*🚑 Аптечка базовая!*\n\nДля крафта предмета тебе нужны:\n\n";

        // Формируем список требуемых ресурсов
        foreach ($requiredResources as $category => $items) {
            foreach ($items as $itemName => $amountRequired) {
                if (isset($resourcesAvailable[$itemName])) {
                    $resource = $resourcesAvailable[$itemName];

                    // Русское имя (display_name)
                    $displayName = $resource['display_name'] ?? $itemName;

                    $quantityAvailable = $resource['quantity'];
                    $infoDetail = isset($resource['rarity'])
                        ? "редкость - {$resource['rarity']}"
                        : (isset($resource['type']) ? "тип - {$resource['type']}" : "неизвестно");
                } else {
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

        // Проверяем достаточно ли ресурсов
        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";

            // Кнопки: Персонаж, Инвентарь, Продать, Купить
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
            // Ресурсов хватает → даём кнопку крафта
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
     * Дополняем элементы полем 'display_name'.
     */
    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];

        // 1) Обычные ресурсы
        foreach ($requiredResources['resources'] as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            $charRes  = $resource
                ? $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId)
                : null;

            $results[$name] = [
                'name'         => $name,
                'quantity'     => $charRes ? $charRes['quantity'] : 0,
                'rarity'       => $charRes['rarity'] ?? 'неизвестно',
                // Уже русское имя
                'display_name' => $name,
            ];
        }

        // 2) Крафтовые предметы (ищем по name_eng)
        foreach ($requiredResources['crafted_items'] as $nameEng => $amount) {
            $item = $this->craftedItemsModel->getItemByNameEngAndCharacterId($nameEng, $characterId);

            $results[$nameEng] = [
                'name'     => $nameEng,
                'quantity' => $item ? $item['quantity'] : 0,
                'type'     => $item['type'] ?? 'неизвестно',
            ];

            // Если нашли русское имя
            if ($item && isset($item['name_rus'])) {
                $results[$nameEng]['display_name'] = $item['name_rus'];
            } else {
                $results[$nameEng]['display_name'] = $nameEng;
            }
        }

        return $results;
    }

    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources)
    {
        // Проверяем обычные ресурсы
        foreach ($requiredResources['resources'] as $name => $requiredAmount) {
            if (
                !isset($resourcesAvailable[$name]) ||
                $resourcesAvailable[$name]['quantity'] < $requiredAmount
            ) {
                return false;
            }
        }
        // Проверяем крафтовые предметы
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
