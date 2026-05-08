<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class FishingRodCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;

    /**
     * Возможные варианты количественного крафта.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
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

        // Английское название в БД, например "FishingRod"
        $fishingRodNameEng  = 'FishingRod';
        $fishingRodQuantity = $this->getCraftedItemQuantity($characterId, $fishingRodNameEng);

        // Заголовок
        $fishingRodTitle = '🎣 Удочка!';
        if ($fishingRodQuantity > 0) {
            $fishingRodTitle .= " (в инв. – {$fishingRodQuantity} шт.)";
        }

        // Ресурсы на 1 шт.
        $requiredResources = [
            'Древесина'          => 10,
            'Кожа животных'      => 1,
            'Шёлк пауков-пустынников' => 5,
            'Улитки и моллюски'  => 15,
            'Шерсть животных'    => 3,
            'Лианы'             => 5,
        ];

        // Считаем, сколько есть у игрока
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // На сколько шт. максимум хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем описание
        $text = "*{$fishingRodTitle}*\n\n"
            . "Для крафта *1 шт.* нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];
            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. (в наличии {$have} ед., редк: {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _160_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _14 мин._\n\n"
            . "*Описание:* Надёжная удочка для рыбной ловли, даёт +30% к базовой добыче рыбы.\n\n";

        // Если ресурсов не хватает даже на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов для крафта хотя бы 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
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
            // Разбиваем кнопки по 3 в строке
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавляем финальные кнопки
            $quantityRows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/high-quality-fishing-rod.jpg');
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
     * Сколько у персонажа уже есть "FishingRod" (из crafted_items_log).
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $logEntry = $this->craftedItemsLogModel
            ->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logEntry ? (int)$logEntry['quantity'] : 0;
    }

    /**
     * Собираем информацию о том, сколько у игрока ресурсов из $requiredResources.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $resName => $reqAmount) {
            $resRow = $this->resourceModel->getResourceByName($resName);
            $qty    = 0;
            $rar    = 0;
            if ($resRow) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($resName, $characterId);

                $qty = $charRes ? $charRes['quantity'] : 0;
                $rar = $resRow['rarity'];
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
     * Считаем, на сколько шт. хватает ресурсов (берём минимум по каждому).
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

        return $maxCraftable === PHP_INT_MAX ? 0 : $maxCraftable;
    }

    /**
     * Генерируем кнопки "Крафт N шт." (1,5,10,25,50,100), если N <= $maxCraftableItems.
     * Пример callback: "craftFishingRod_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q} шт",
                    'callback_data' => "genericCraft_FishingRod_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Старый метод проверки только на 1 шт. (уже не обязателен, если используем calculateMaxCraftableItems).
     */
    private function areAllResourcesSufficient(array $resourcesAvailable, array $requiredResources): bool
    {
        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            if ($res['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }
}
