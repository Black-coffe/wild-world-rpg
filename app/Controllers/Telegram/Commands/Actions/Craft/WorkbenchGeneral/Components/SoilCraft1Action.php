<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // (1) Подключаем модель логов
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс SoilCraft1Action:
 * Показывает информацию о крафте "Грунта" (Soil) и формирует кнопки для количественного крафта.
 */
class SoilCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // (2) Храним модель логов

    /**
     * Набор "стандартных" количеств для крафта
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // (2) Инициализация
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или персонаж отсутствует.',
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        $characterId = $character['id'];

        // 1) Узнаём, сколько "Грунта" (Soil) у игрока уже есть
        $soilNameEng = 'Soil'; // Значение name_eng в вашей таблице crafted_items
        $soilQty     = $this->getCraftedItemQuantity($characterId, $soilNameEng);

        // 2) Формируем заголовок
        $title = '🌱 Грунт!';
        if ($soilQty > 0) {
            $title .= " (в инв. – {$soilQty} шт.)";
        }

        // Ресурсы на 1 шт. Грунта
        $requiredResources = [
            'Глина'     => 10,
            'Водоросли' => 5,
            'Песок'     => 26,
            'Ил'        => 15,
        ];

        // Проверяем, сколько ресурсов на 1 шт. есть у игрока
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Считаем, на сколько штук всего хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем основное описание, используя $title
        $text = "*{$title}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";
        foreach ($resourcesAvailable as $res) {
            $req  = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$req} ед. "
                . "(в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _120_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _~4–16 мин._\n\n"
            . "*Описание:* Компонент для фермерства. Является основой для постройки теплиц.\n\n";

        // Проверяем, хватает ли хотя бы на 1 шт.
        if ($maxCraftableItems < 1) {
            // Не хватает ресурсов даже на 1 шт.
            $text .= "__Недостаточно ресурсов, чтобы создать даже 1 шт.__";
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
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить, генерируем кнопки
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим кнопки в конце
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить',  'callback_data' => 'buy'],
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/components/craftSoil.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Возвращает, сколько уже есть "Soil" (или любого name_eng) у игрока,
     * если в crafted_items_log существует соответствующая запись.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагаем, что в модели CraftedItemsLogModel есть метод getItemByNameEngAndCharacterId()
        $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $item ? (int) $item['quantity'] : 0;
    }

    /**
     * Проверяем наличие ресурсов (для 1 шт.) у игрока
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $resName => $need) {
            $resource = $this->resourceModel->getResourceByName($resName);
            $qty      = 0;
            $rar      = 0;

            if ($resource) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($resName, $characterId);
                $qty = $charRes ? $charRes['quantity'] : 0;
                $rar = $resource['rarity'];
            }

            $results[] = [
                'name'     => $resName,
                'quantity' => $qty,
                'rarity'   => $rar,
            ];
        }
        return $results;
    }

    /**
     * Считаем, на сколько шт. Грунта хватает у игрока
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredResources[$name] ?? 0;

            if ($need > 0) {
                $possible = (int) floor($have / $need);
                if ($possible < $maxCraftable) {
                    $maxCraftable = $possible;
                }
            }
        }
        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Генерируем кнопки "Крафт X шт." (1..100) если X ≤ maxCraftable
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_Soil_{$q}"
                ];
            }
        }
        return $buttons;
    }
}
