<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Tools;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс, выводящий инфо о «Каменной кирке» (StonePickaxe)
 * и формирующий кнопки для количественного крафта (1,5,10,25,50,100).
 */
class StonePickaxeCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;
    private CraftCardHelper $craftCardHelper;

    /**
     * Возможные варианты крафта.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftCardHelper        = new CraftCardHelper();
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

        // Название предмета (англ. поле)
        $stonePickaxeNameEng   = 'StonePickaxe';
        $stonePickaxeQuantity  = $this->getCraftedItemQuantity($characterId, $stonePickaxeNameEng);

        // Заголовок
        $pickaxeTitle = '⛏️ Каменная кирка!';
        if ($stonePickaxeQuantity > 0) {
            $pickaxeTitle .= " (в инв. – {$stonePickaxeQuantity} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Древесина' => 50,
            'Базальт'   => 1,
            'Камни'     => 10,
        ];

        // Смотрим, сколько ресурсов у игрока — тем же пулом (рюкзак + склад базы, ADR-171),
        // которым потом считает старт крафта GenericCraftActionStart::checkResources().
        $resourcesAvailable  = $this->craftCardHelper->available($characterId, $requiredResources);
        // Считаем, на сколько шт. максимум хватает
        $maxCraftableItems   = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Текст
        $text = "*{$pickaxeTitle}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. "
                . "(в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _165_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _14 минут_\n\n"
            . "*Описание:* Старая каменная, первобытная кирка из камня и бревна, "
            . "даёт +30% к добыче рудных ресурсов.\n\n";

        // Если ресурсов <1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов для крафта даже 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',   'callback_data' => 'buy']
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('StonePickaxe'),
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

            // Добавляем кнопки персонажа/инвентаря и т.д.
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
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

        $imagePath = base_url('uploads/telegram/craft/create-an-image-of-an-ancient-stone-pickaxe.jpg');
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
     * Узнаём, сколько кирок (англ. name_eng) уже есть у игрока.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $row = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $row ? (int)$row['quantity'] : 0;
    }

    /**
     * Смотрим, на сколько шт. хватает (берём минимум по каждому ресурсу).
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
     * Генерируем кнопки "Крафт N шт." (1,5,10,25,50,100) если N <= max.
     * Пример callback_data: "craftStonePickaxe_10"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_StonePickaxe_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Старый метод (на 1 шт.) — не обязателен, если используем calculateMaxCraftableItems().
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
