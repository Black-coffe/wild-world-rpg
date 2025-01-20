<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class LumberjackAxeCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;

    /**
     * Варианты количественного крафта.
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

        $characterId = $character['id'];

        // Англ. название в базе
        $axeNameEng   = 'LumberjackAxe';
        $axeQuantity  = $this->getCraftedItemQuantity($characterId, $axeNameEng);

        // Заголовок
        $axeTitle = '🪓 Топор дровосека!';
        if ($axeQuantity > 0) {
            $axeTitle .= " (в инв. – {$axeQuantity} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Древесина' => 50,
            'Базальт'   => 1,
            'Камни'     => 10,
        ];

        // Собираем информацию о ресурсах
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Смотрим, на сколько шт. максимум хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем текст
        $text = "*{$axeTitle}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";
        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= "📦 {$res['name']} - {$need} ед. (в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _165_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _14 минут_\n\n"
            . "*Описание:* Старый каменно-бревенчатый топор. +30% к добыче древесины.\n\n";

        // Если не хватает даже на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов, чтобы скрафтить хотя бы 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем кнопки по 3 в строке
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим финальные кнопки (Персонаж, Инвентарь и т.д.)
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/old-stone-primitive-axe-of-stone-and-logs.jpg');
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
     * Сколько уже есть «Топоров дровосека» (name_eng).
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $row = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $row ? (int) $row['quantity'] : 0;
    }

    /**
     * Проверяем, сколько ресурсов для 1 шт. есть у игрока.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $resName => $need) {
            $resRow = $this->resourceModel->getResourceByName($resName);
            $qty    = 0;
            $rar    = 0;

            if ($resRow) {
                $charRes = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);
                $qty     = $charRes ? $charRes['quantity'] : 0;
                $rar     = $resRow['rarity'];
            }

            $results[] = [
                'name'     => $resName,
                'quantity' => $qty,
                'rarity'   => $rar
            ];
        }
        return $results;
    }

    /**
     * Считаем, на сколько шт. хватает (минимум по каждому компоненту).
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
     * Генерируем кнопки "Крафт N шт." (1,5,10,25,50,100) если N <= maxCraftableItems.
     * Пример callback_data: "craftLumberjackAxeCraft1_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    // Важно указать callback_data. Чтобы далее класс-стартер понял, сколько шт. крафтить.
                    // Обычно callback_data = "craftLumberjackAxeCraft1_{$q}"
                    // или как у вас условлено: "craftLumberjackAxe_X"
                    'callback_data' => "craftLumberjackAxeCraft1_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Старый метод "достаточно ли для 1 шт." — не обязателен, если уже используем calculateMaxCraftableItems.
     */
    private function areAllResourcesSufficient(array $resourcesAvailable, array $requiredResources): bool
    {
        foreach ($resourcesAvailable as $res) {
            if ($res['quantity'] < $requiredResources[$res['name']]) {
                return false;
            }
        }
        return true;
    }
}
