<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // 1) Подключаем модель логов
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс, показывающий информацию о крафте "Ткани" (Fabric)
 * и формирующий кнопки для количественного крафта (1,5,10,25,50,100).
 */
class FabricCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // 2) Поле для модели логов

    /**
     * Возможные "пакеты" крафта.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // 2) Инициализация модели логов
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или персонаж не создан.',
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

        // 1) Узнаём, сколько «Ткани» (Fabric) уже есть у игрока
        $fabricNameEng = 'Fabric'; // name_eng из таблицы crafted_items
        $fabricQty     = $this->getCraftedItemQuantity($characterId, $fabricNameEng);

        // 2) Формируем заголовок "🧵 Ткань!"
        // если у игрока уже есть ткань, добавляем "(в инв. – N шт.)"
        $title = '🧵 Ткань!';
        if ($fabricQty > 0) {
            $title .= " (в инв. – {$fabricQty} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Шерсть животных'         => 10,
            'Шёлк пауков-пустынников' => 1,
            'Текстильные культуры'    => 10,
        ];

        // Информация о текущих ресурсах (для 1 шт.)
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Считаем, на сколько штук всего хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // 3) Формируем текст, используя итоговый заголовок
        $text = "*{$title}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. "
                . "(в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _70_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _~5–12 мин._\n\n"
            . "*Описание:* Компонент, предназначенный для создания изделий из ткани: "
            . "одежды, накидок и проч.\n\n";

        // 4) Проверяем, хватает ли хотя бы на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов даже на 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим кнопки персонажа/инвентаря
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            // Добавим кнопки "Продать/Купить"
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        // 5) Отправляем результат
        $imagePath = base_url('uploads/telegram/craft/components/craftFabric.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Возвращает количество «Ткани» (или любого itemNameEng) у игрока,
     * если такое есть в crafted_items_log, иначе 0.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагаем, что в модели есть метод:
        // getItemByNameEngAndCharacterId($itemNameEng, $charId)
        // возвращающий ['quantity' => ...] или null
        // Если у вас метод называется иначе - подстройте под свой код.
        // Или используйте where() вручную.
        $item = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $item ? (int) $item['quantity'] : 0;
    }

    /**
     * Смотрим, сколько ресурсов (для 1 шт.) есть у игрока.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $resName => $need) {
            $resourceRow = $this->resourceModel->getResourceByName($resName);
            $qty         = 0;
            $rar         = 0;

            if ($resourceRow) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($resName, $characterId);
                $qty = $charRes ? $charRes['quantity'] : 0;
                $rar = $resourceRow['rarity'];
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
     * Определяем, на сколько шт. (учитывая все ресурсы) хватает у игрока.
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
     * Генерируем кнопки "Крафт N шт." (1,5,10,25,50,100) если N <= maxCraftable.
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_Fabric_{$q}"
                ];
            }
        }
        return $buttons;
    }
}
