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
 * Класс, выводящий информацию о «Монтировке» (TireIron)
 * и формирующий кнопки количественного крафта.
 */
class TireIronCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;
    private CraftCardHelper $craftCardHelper;

    /**
     * Варианты «пакетов» крафта: 1, 5, 10, 25, 50, 100.
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

        // Название (англ.) "TireIron"
        $tireIronNameEng   = 'TireIron';
        $tireIronQuantity  = $this->getCraftedItemQuantity($characterId, $tireIronNameEng);

        // Формируем заголовок
        $tireIronTitle = '🪛 Монтировки!';
        if ($tireIronQuantity > 0) {
            $tireIronTitle .= " (в инв. – {$tireIronQuantity} шт.)";
        }

        // Ресурсы на 1 шт.
        $requiredResources = [
            'Железная руда' => 54,
        ];

        // Проверка ресурсов — тем же пулом (рюкзак + склад базы, ADR-171), которым потом
        // считает старт крафта GenericCraftActionStart::checkResources().
        $resourcesAvailable = $this->craftCardHelper->available($characterId, $requiredResources);
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Текст
        $text = "*{$tireIronTitle}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. "
                . "(в наличии {$have} ед. редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _216_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _16 минут_\n\n"
            . "*Описание:* Толстый металлический заостренный стержень (лом), "
            . "используется для взлома и разбивания твёрдых объектов.\n\n";

        // Если не хватает даже на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов для крафта даже 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '◀️ Я',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить', 'callback_data' => 'buy']
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('TireIron'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'tools'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим финальные кнопки
            $quantityRows[] = [
                ['text' => '◀️ Я', 'callback_data' => 'character'],
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

        $imagePath = base_url('uploads/telegram/craft/craftTireIron.jpg');
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
     * Сколько монтировок уже есть у игрока (по name_eng).
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $row = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $row ? (int)$row['quantity'] : 0;
    }

    /**
     * Считаем, на сколько шт. хватает (берём минимум по каждому компоненту).
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
     * Создаём кнопки "Крафт 1шт", "Крафт 5шт", ... если N <= maxCraftableItems.
     * Пример callback_data: "craftTireIron_5"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_TireIron_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * Если нужно только проверять на 1 шт. — не используем, ибо у нас уже calculateMaxCraftableItems().
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
