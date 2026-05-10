<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // (1) Подключаем модель логов
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс StoneBlocksCraft1Action:
 * Показывает информацию о крафте "Каменные блоки" (StoneBlocks) и
 * формирует кнопки для количественного крафта (1..100).
 */
class StoneBlocksCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // (2) Храним экземпляр модели логов

    /**
     * Набор "стандартных" количеств для крафта.
     * Можно варьировать, если нужно меньше/больше опций.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // (2) Инициализация модели логов
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или персонаж не определён.',
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

        // 1) Узнаём, сколько "Каменных блоков" (StoneBlocks) у игрока уже есть
        $stoneBlocksNameEng = 'StoneBlocks'; // значение name_eng в вашей таблице crafted_items
        $stoneBlocksQty     = $this->getCraftedItemQuantity($characterId, $stoneBlocksNameEng);

        // 2) Формируем заголовок
        $title = '🧱 Каменные блоки!';
        if ($stoneBlocksQty > 0) {
            $title .= " (в инв. – {$stoneBlocksQty} шт.)";
        }

        // Ресурсы на 1 шт. "Каменных блоков"
        $requiredPerOne = [
            'Камни' => 36,
            'Вода'  => 10,
        ];

        // Узнаём, сколько ресурсов у игрока
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredPerOne);

        // Считаем, на сколько штук всего хватит
        $maxCraftableItems = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredPerOne);

        // Формируем описание (с учётом $title)
        $text = "*{$title}*\n\n"
            . "Для крафта *1 шт.* нужны:\n\n";
        foreach ($resourcesAvailable as $res) {
            $need = $requiredPerOne[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. "
                . "(в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _230_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _~4–16 мин._\n\n"
            . "*Описание:* Компонент из камня, используемый для сооружений, где применяется камень.\n\n";

        // Формируем клавиатуру
        if ($maxCraftableItems < 1) {
            // Не хватает даже на 1 шт.
            $text .= "__Недостаточно ресурсов, чтобы создать даже 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',  'callback_data' => 'buy'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим "нижние" кнопки
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];

            $keyboard = [
                'inline_keyboard' => $quantityRows,
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/components/craftStoneBlocks.jpg');
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
     * Возвращает, сколько "StoneBlocks" (или другого itemNameEng) у игрока,
     * если есть запись в crafted_items_log.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагаем, что есть метод в CraftedItemsLogModel:
        // getItemByNameEngAndCharacterId($itemNameEng, $characterId)
        $itemRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $itemRow ? (int) $itemRow['quantity'] : 0;
    }

    /**
     * Собираем данные о ресурсах, которые нужны для 1 шт.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredPerOne): array
    {
        $results = [];
        foreach ($requiredPerOne as $resName => $amountOne) {
            $resource = $this->resourceModel->getResourceByName($resName);
            $qty      = 0;
            $rarity   = 0;

            if ($resource) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($resName, $characterId);

                $qty    = $charRes ? $charRes['quantity'] : 0;
                $rarity = $resource['rarity'];
            }

            $results[] = [
                'name'     => $resName,
                'quantity' => $qty,
                'rarity'   => $rarity,
            ];
        }
        return $results;
    }

    /**
     * Определяем, на сколько штук (1..∞) у игрока хватает ресурсов.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredPerOne): int
    {
        $maxCraftable = PHP_INT_MAX;
        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredPerOne[$name] ?? 0;

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
     * Формируем кнопки "Крафт Xшт", только если X <= $maxCraftableItems.
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_StoneBlocks_{$q}",
                ];
            }
        }
        return $buttons;
    }
}
