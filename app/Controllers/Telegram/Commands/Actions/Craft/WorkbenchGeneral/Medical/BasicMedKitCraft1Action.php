<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;   // <-- NEW: чтобы получить количество скрафченного
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BasicMedKitCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel; // <-- NEW: свойство для лога крафта

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // <-- NEW: инициализация
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

        // <-- NEW: Допустим, предмет в базе (англ. поле) называется 'BasicMedKit'
        $basicMedKitNameEng = 'FirstAidKit';
        // Узнаём, сколько уже есть "базовых аптечек" у игрока
        $basicMedKitQuantity = $this->getCraftedItemQuantity($characterId, $basicMedKitNameEng);

        // Формируем динамический заголовок
        $medKitTitle = '🚑 Аптечка базовая!';
        if ($basicMedKitQuantity > 0) {
            $medKitTitle .= " (в инв. – {$basicMedKitQuantity} шт.)";
        }

        // Требуемые ресурсы: обычные + крафтовые
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

        // <-- NEW: используем $medKitTitle вместо жёсткой строки "🚑 Аптечка базовая!"
        $text = "*{$medKitTitle}*\n\nДля крафта предмета тебе нужны:\n\n";

        // Формируем список требуемых ресурсов
        foreach ($requiredResources as $category => $items) {
            foreach ($items as $itemName => $amountRequired) {
                if (isset($resourcesAvailable[$itemName])) {
                    $resource = $resourcesAvailable[$itemName];

                    // Отображаемое имя (русское или fallback)
                    $displayName = $resource['display_name'] ?? $itemName;

                    $quantityAvailable = $resource['quantity'];
                    $infoDetail = isset($resource['rarity'])
                        ? "редкость - {$resource['rarity']}"
                        : (isset($resource['type']) ? "тип - {$resource['type']}" : "неизвестно");
                } else {
                    // Если вдруг чего-то не нашли
                    $displayName       = $itemName;
                    $quantityAvailable = 0;
                    $infoDetail        = "неизвестно";
                }

                $text .= "📦 {$displayName} - {$amountRequired} ед. "
                    . "(в наличии {$quantityAvailable} ед., {$infoDetail})\n";
            }
        }

        $text .= "\n*Стоимость на рынке:* _100_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта:* _15 мин._\n\n"
            . "*Описание:* Базовая аптечка 1го уровня, восстановит +40 здоровья, +20 выносливости\n\n";

        // Проверяем достаточно ли ресурсов/предметов
        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов для крафта этого предмета.__";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',  'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            // Доступна кнопка крафта
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить', 'callback_data' => 'craftBasicMedKit'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/simple_craft_kit.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    // ======== Ниже вспомогательные методы ========

    /**
     * Получаем количество скрафченных аптечек (или других предметов) по name_eng.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагаем, что в crafted_items_log существует
        // метод ->getItemByNameEngAndCharacterId($itemNameEng, $characterId)
        $itemLogRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $itemLogRow ? (int) $itemLogRow['quantity'] : 0;
    }

    /**
     * Собираем инфу о наличии обычных ресурсов и крафтовых предметов.
     */
    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];

        // 1) Обычные ресурсы
        foreach ($requiredResources['resources'] as $resName => $amount) {
            $resource = $this->resourceModel->getResourceByName($resName);
            $charRes  = $resource
                ? $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId)
                : null;

            $results[$resName] = [
                'name'         => $resName,
                'quantity'     => $charRes ? $charRes['quantity'] : 0,
                'rarity'       => $resource ? $resource['rarity'] : 'неизвестно',
                'display_name' => $resName,
            ];
        }

        // 2) Крафтовые предметы (по name_eng)
        foreach ($requiredResources['crafted_items'] as $itemNameEng => $amount) {
            $itemLogRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
            if ($itemLogRow) {
                $results[$itemNameEng] = [
                    'name'         => $itemNameEng,
                    'quantity'     => $itemLogRow['quantity'],
                    'type'         => $itemLogRow['type'] ?? 'неизвестно',
                    // Если в логе крафта есть связь на crafted_items и мы делаем JOIN,
                    // то вполне можем получить name_rus:
                    'display_name' => $itemLogRow['name_rus'] ?? $itemNameEng,
                ];
            } else {
                // Если в логе предмет отсутствует
                $results[$itemNameEng] = [
                    'name'         => $itemNameEng,
                    'quantity'     => 0,
                    'type'         => 'неизвестно',
                    'display_name' => $itemNameEng,
                ];
            }
        }

        return $results;
    }

    /**
     * Проверяем, хватает ли ресурсов и крафтовых предметов.
     */
    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources)
    {
        // 1) Проверяем обычные ресурсы
        foreach ($requiredResources['resources'] as $resName => $requiredAmount) {
            if (
                !isset($resourcesAvailable[$resName]) ||
                $resourcesAvailable[$resName]['quantity'] < $requiredAmount
            ) {
                return false;
            }
        }

        // 2) Проверяем крафтовые предметы
        foreach ($requiredResources['crafted_items'] as $itemNameEng => $requiredAmount) {
            if (
                !isset($resourcesAvailable[$itemNameEng]) ||
                $resourcesAvailable[$itemNameEng]['quantity'] < $requiredAmount
            ) {
                return false;
            }
        }
        return true;
    }
}
