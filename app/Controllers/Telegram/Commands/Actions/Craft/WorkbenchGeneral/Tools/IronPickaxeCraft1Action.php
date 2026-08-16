<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class IronPickaxeCraft1Action extends BaseAction
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

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        $characterId = $character['id'];

        // Название предмета в базе (английское)
        $pickaxeNameEng = 'IronPickaxe';

        // Сколько уже есть кирок у игрока
        $pickaxeQuantity = $this->getCraftedItemQuantity($characterId, $pickaxeNameEng);

        // Заголовок
        $pickaxeTitle = '⛏️ Железная кирка!';
        if ($pickaxeQuantity > 0) {
            $pickaxeTitle .= " (в инв. – {$pickaxeQuantity} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Древесина'     => 50,
            'Железная руда' => 25,
        ];

        // Смотрим, сколько у игрока
        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Определяем, на сколько шт. максимум хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем описание
        $text = "*{$pickaxeTitle}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. (в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _200_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _16 минут_\n\n"
            . "*Описание:* Прочная металлическая кирка, даёт +40% к добыче руды.\n\n";

        // Проверка на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов, чтобы даже 1 шт. кирки скрафтить.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'tools'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить несколько
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавляем кнопки персонажа, инвентаря, торговли
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];
            $quantityRows[] = [
                ['text' => '⬅️ Назад', 'callback_data' => 'tools'],
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/robust-iron-pickaxe.jpg');
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
     * Узнаём, сколько кирок уже есть (по name_eng).
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $logEntry = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logEntry ? (int)$logEntry['quantity'] : 0;
    }

    /**
     * Проверяем, сколько ресурсов для 1 шт. есть у игрока.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $resName => $req) {
            $row = $this->resourceModel->getResourceByName($resName);
            $qty = 0;
            $rar = 0;

            if ($row) {
                $charRes = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);
                $qty     = $charRes ? $charRes['quantity'] : 0;
                $rar     = $row['rarity'];
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
     * Определяем, на сколько штук всего хватает (мин. значение по всем ресурсам).
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
     * Генерируем кнопки "Крафт N шт." (1,5,10,25,50,100), если N <= maxCraftableItems.
     * Пример callback_data: "craftIronPickaxe_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_IronPickaxe_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Старый метод проверки на 1 шт. — не обязателен с calculateMaxCraftableItems().
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
