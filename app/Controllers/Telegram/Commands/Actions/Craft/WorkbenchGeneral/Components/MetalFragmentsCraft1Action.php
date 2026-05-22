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
 * Класс, показывающий информацию о крафте "Металлические фрагменты" (MetalFragments)
 * и формирующий кнопки для количественного крафта (1,5,10,25,50,100).
 */
class MetalFragmentsCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // (2) Поле для хранения модели логов

    /**
     * Набор "стандартных" количеств крафта
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // (2) Инициализируем модель
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

        // (3) Узнаём, сколько "Металлических фрагментов" уже есть у игрока
        // Предположим, что в таблице crafted_items name_eng = "metalFragments".
        $metalFragmentsQty = $this->getCraftedItemQuantity($characterId, 'metalFragments');

        // Формируем заголовок: если qty>0, добавляем "(в инв. - X шт.)"
        $title = '🔩 Металл фрагменты!';
        if ($metalFragmentsQty > 0) {
            $title .= " (в инв. – {$metalFragmentsQty} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Железная руда' => 100,
            'Древесина'     => 10,
            'Песок'         => 1,
        ];

        // Проверяем ресурсы (для 1 шт.)
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Считаем, на сколько штук всего хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Описание
        $text = "*{$title}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";
        foreach ($resourcesAvailable as $res) {
            $req  = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$req} ед. "
                . "(в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _420_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _~5–12 мин._\n\n"
            . "*Описание:* Компонент для создания металлических изделий, сооружений и станков.\n\n";

        // Генерируем клавиатуру
        if ($maxCraftableItems < 1) {
            // Недостаточно на 1 шт.
            $text .= "__У вас недостаточно ресурсов даже на 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',  'callback_data' => 'buy'],
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавляем служебные кнопки
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить',  'callback_data' => 'buy'],
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/components/craftMetalFragments.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем ответ
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Возвращает количество уже скрафченных "MetalFragments" (или любого itemNameEng) у игрока.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагаем, что в CraftedItemsLogModel есть метод
        // getItemByNameEngAndCharacterId($itemNameEng, $charId) => ['quantity' => ...] или null
        $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $item ? (int)$item['quantity'] : 0;
    }

    /**
     * Проверяем, сколько ресурсов (для 1 шт.) есть у игрока
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
     * Определяем, на сколько шт. всего хватает
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
     * Генерируем кнопки "Крафт X шт." (1..100) если X <= maxCraftable
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_MetalFragments_{$q}"
                ];
            }
        }
        return $buttons;
    }
}
