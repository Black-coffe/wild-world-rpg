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
 * Класс, выводящий информацию о «Железной лопате» (IronShovel)
 * и формирующий кнопки для количественного крафта.
 */
class IronShovelCraft1Action extends BaseAction
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

        // Название предмета в базе (англ. столбец)
        $shovelNameEng = 'IronShovel';

        // Узнаём, сколько уже есть лопат
        $shovelQuantity = $this->getCraftedItemQuantity($characterId, $shovelNameEng);

        // Заголовок
        $shovelTitle = '🥄 Железная лопата!';
        if ($shovelQuantity > 0) {
            $shovelTitle .= " (в инв. – {$shovelQuantity} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Древесина'     => 50,
            'Железная руда' => 16,
        ];

        $resourcesAvailable = $this->checkResourcesAvailability($characterId, $requiredResources);
        // Считаем, на сколько шт. максимум хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Описание
        $text = "*{$shovelTitle}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. "
                . "(в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _164_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _14 минут_\n\n"
            . "*Описание:* Обычная металлическая лопата. Дает +30% к добыче ресурсов, связанных с землёй.\n\n";

        // Если ресурсов <1
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов, чтобы скрафтить даже 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',    'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',    'callback_data' => 'buy']
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Дополнительно: персонаж, инвентарь, купить/продать
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy']
            ];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/image-of-a-typical-metal-shovel.jpg');
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
     * Сколько у игрока этого предмета (name_eng).
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $logEntry = $this->craftedItemsLogModel
            ->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logEntry ? (int)$logEntry['quantity'] : 0;
    }

    /**
     * Проверяем ресурсы для 1 шт.
     */
    private function checkResourcesAvailability(int $characterId, array $requiredResources): array
    {
        $results = [];
        foreach ($requiredResources as $resName => $need) {
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
                'rarity'   => $rar
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

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Генерируем кнопки "Крафт N шт." (1, 5, 10, 25, 50, 100), если N <= max.
     * Пример: "craftIronShovel_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_IronShovel_{$q}"
                ];
            }
        }
        return $buttons;
    }

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
