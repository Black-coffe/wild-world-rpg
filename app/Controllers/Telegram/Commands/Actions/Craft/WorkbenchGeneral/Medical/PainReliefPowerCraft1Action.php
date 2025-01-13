<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;  // <-- Модель для учёта скрафченных предметов
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class PainReliefPowerCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel(); // <-- Инициализируем лог крафта
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

        // Допустим, в таблице crafted_items (или где у вас хранятся предметы),
        // "Обезболивающий порошок" называется "PainReliefPowder" (name_eng).
        // Убедитесь, что это совпадает с реальными данными!
        $itemNameEng = 'AnalgesicPowder';

        // Получаем, сколько у игрока уже есть таких предметов
        $existingQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Формируем заголовок предмета
        $itemTitle = "🌡️ Обезболивающий порошок!";
        if ($existingQuantity > 0) {
            $itemTitle .= " (в инв. – {$existingQuantity} шт.)";
        }

        // Проверка необходимых ресурсов (как было раньше)
        $requiredResources = [
            'Ядовитые растения'    => 1,
            'Кора деревьев'        => 3,
            'Береговая растительность' => 2,
            'Цветы орхидей'        => 1,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);

        // Собираем текст
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта предмета тебе нужны:\n\n";

        foreach ($resourcesAvailable as $resource) {
            $reqAmount  = $requiredResources[$resource['name']];
            $haveAmount = $resource['quantity'];
            $rarity     = $resource['rarity'];
            $text      .= "📦 {$resource['name']} - {$reqAmount} ед. "
                . "(в наличии {$haveAmount} ед. редк - {$rarity})\n";
        }

        $text .= "\n*Стоимость на рынке:* _30_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта:* _5 мин._\n\n"
            . "*Описание:* Можно принять для улучшения самочувствия персонажа, "
            . "восстановит +12 здоровья, но снимет -6 выносливости\n\n";

        if (!$this->areAllResourcesSufficient($resourcesAvailable, $requiredResources)) {
            // Если ресурсов не хватает
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов "
                . "для крафта этого предмета.__";
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
            // Если ресурсов хватает
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠️ Крафтить',   'callback_data' => 'craftPainReliefPower'],
                    ],
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                ]
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/analgesic_powder.jpg');
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
     * Возвращает количество предметов (itemNameEng) у конкретного персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагается, что у CraftedItemsLogModel есть метод:
        //    getItemByNameEngAndCharacterId($nameEng, $characterId)
        // который возвращает запись из crafted_items_log, если есть.
        $row = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $row ? (int) $row['quantity'] : 0;
    }

    private function checkResourcesAvailability($characterId, $requiredResources)
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resource = $this->resourceModel->getResourceByName($name);
            if ($resource) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($name, $characterId);

                $results[] = [
                    'name'     => $name,
                    'quantity' => $charRes ? $charRes['quantity'] : 0,
                    'rarity'   => $resource['rarity'],
                ];
            }
        }
        return $results;
    }

    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources): bool
    {
        foreach ($resourcesAvailable as $resource) {
            $need = $requiredResources[$resource['name']] ?? 0;
            if ($resource['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }
}
