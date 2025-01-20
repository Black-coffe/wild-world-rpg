<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StimulatorCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;

    /**
     * Массив вариантов количественного крафта.
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
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $characterId = $character['id'];

        // Предполагаем, что в базе (crafted_items) name_eng = 'Stimulator'
        $itemNameEng = 'Stimulator';
        // Узнаём, сколько уже есть «Стимуляторов»
        $stimulatorQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Формируем заголовок
        $itemTitle = '💉 Стимулятор!';
        if ($stimulatorQuantity > 0) {
            $itemTitle .= " (в инв. – {$stimulatorQuantity} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Грибы' => 3,
            'Мед'   => 2,
            'Алоэ'  => 3,
            'Вода'  => 12,
        ];

        // Смотрим, сколько у игрока ресурсов
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Определяем, на сколько шт. максимум хватит ресурсов
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Текст описания
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта *1 шт.* нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];
            $text .= "📦 {$res['name']} - {$need} ед. (в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _65_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта (1 шт.):* _12 мин._\n\n"
            . "*Описание:* Конская доза удовольствия. Повышает выносливость +15 и здоровье +25.\n\n";

        // Если ресурсов не хватает даже на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов для крафта хотя бы 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
                    ],
                ]
            ];
        } else {
            // Формируем кнопки «Крафт N шт.»
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем по 3 в ряд
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим стандартные кнопки
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/liquid_mixture_of_very_invigorating_acid-green_beverage.jpg');
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
     * Возвращает кол-во имеющихся "Стимуляторов" (Stimulator).
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $logEntry = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logEntry ? (int)$logEntry['quantity'] : 0;
    }

    /**
     * Определяем, сколько у игрока ресурсов (на 1 шт.).
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resRow = $this->resourceModel->getResourceByName($name);
            $qty    = 0;
            $rarity = 0;

            if ($resRow) {
                $charRes = $this->characterResourceModel
                    ->getResourceByNameAndCharacterId($name, $characterId);

                $qty    = $charRes ? $charRes['quantity'] : 0;
                $rarity = $resRow['rarity'];
            }

            $results[] = [
                'name'     => $name,
                'quantity' => $qty,
                'rarity'   => $rarity
            ];
        }
        return $results;
    }

    /**
     * Считаем, на сколько шт. максимум хватит ресурсов (берём минимум по всем).
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
     * Генерируем кнопки "Крафт N шт." только если N <= $maxCraftableItems.
     * Пример: "craftStimulator_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "craftStimulator_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Проверка на 1 шт. (старый метод) — не критичен, если используем calculateMaxCraftableItems().
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
