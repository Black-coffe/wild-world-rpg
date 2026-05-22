<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс, который выводит информацию о "Мотыге" (Hoe) и формирует кнопки
 * для крафта нескольких штук сразу (количественный крафт).
 */
class HoeCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;

    /**
     * Возможные варианты крафта: 1, 5, 10, 25, 50, 100.
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

        // Название в базе (англ.): "Hoe"
        $hoeNameEng = 'Hoe';

        // Сколько "мотыг" уже есть
        $hoeQuantity = $this->getCraftedItemQuantity($characterId, $hoeNameEng);

        // Заголовок
        $hoeTitle = '🌾 Мотыга!';
        if ($hoeQuantity > 0) {
            $hoeTitle .= " (в инв. – {$hoeQuantity} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Древесина'     => 50,
            'Железная руда' => 16,
        ];

        // Смотрим, сколько у игрока ресурсов
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Определяем, на сколько шт. максимум хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Собираем текст
        $text = "*{$hoeTitle}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. (в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _164_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _14 минут_\n\n"
            . "*Описание:* Мотыка (сапа) — инструмент для земледелия. Даёт +30 к сбору сельскохозяйственных ресурсов.\n\n";

        // Если ресурсов <1, не показываем «Крафтить»
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов, чтобы скрафтить даже 1 мотыгу.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',    'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',    'callback_data' => 'buy']
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'tools'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем по 3 в строке
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим финальные кнопки
            $quantityRows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy']
            ];
            $quantityRows[] = [
                ['text' => '⬅️ Назад', 'callback_data' => 'tools'],
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/traditional-hoe.jpg');
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
     * Сколько "мотыг" (англ. name_eng) уже есть у игрока.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $logEntry = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logEntry ? (int) $logEntry['quantity'] : 0;
    }

    /**
     * Проверка ресурсов на 1 шт.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $name => $amount) {
            $resRow = $this->resourceModel->getResourceByName($name);
            $qty    = 0;
            $rar    = 0;

            if ($resRow) {
                $charRes = $this->characterResourceModel->getResourceByNameAndCharacterId($name, $characterId);
                $qty     = $charRes ? $charRes['quantity'] : 0;
                $rar     = $resRow['rarity'];
            }
            $results[] = [
                'name'     => $name,
                'quantity' => $qty,
                'rarity'   => $rar
            ];
        }
        return $results;
    }

    /**
     * Смотрим, на сколько штук максимум хватает (берём минимум по каждому ресурсу).
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
     * Формируем кнопки "Крафт N шт.", если N <= $maxCraftableItems.
     * Пример: "craftHoe_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q} шт",
                    'callback_data' => "genericCraft_Hoe_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Старая проверка на 1 шт. не требуется, если используем calculateMaxCraftableItems().
     */
    private function areAllResourcesSufficient($resourcesAvailable, $requiredResources): bool
    {
        foreach ($resourcesAvailable as $res) {
            if ($res['quantity'] < $requiredResources[$res['name']]) {
                return false;
            }
        }
        return true;
    }
}
