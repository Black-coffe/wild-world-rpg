<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // <-- (1) Подключаем модель логов
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс, показывающий информацию о крафте "Стеклопакеты" (GlassBags)
 * и формирующий кнопки для количественного крафта (1,5,10,25,50,100).
 */
class GlassBagsCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // <-- (2) Поле для модели логов
    private CraftCardHelper $craftCardHelper;

    /**
     * Набор "стандартных" количеств крафта
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // <-- (2) Инициализируем модель
        $this->craftCardHelper        = new CraftCardHelper();
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

        // 1) Узнаём, сколько "Стеклопакетов" (GlassBags) уже есть у игрока
        $glassBagsNameEng = 'GlassBags'; // предполагаем, что в таблице crafted_items name_eng="GlassBags"
        $glassBagsQty     = $this->getCraftedItemQuantity($characterId, $glassBagsNameEng);

        // 2) Формируем заголовок. Если qty>0, добавляем "(в инв. – X шт.)"
        $title = '🪟 Стекло пакеты!';
        if ($glassBagsQty > 0) {
            $title .= " (в инв. – {$glassBagsQty} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Древесина'      => 10,
            'Песок'          => 50,
            'Базальт'        => 10,
            'Лавовый камень' => 8,
        ];

        // Проверяем ресурсы для 1 шт. — тем же пулом (рюкзак + склад базы, ADR-171),
        // которым потом считает старт крафта GenericCraftActionStart::checkResources().
        $resourcesAvailable = $this->craftCardHelper->available($characterId, $requiredResources);
        // Считаем, на сколько штук всего хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // 3) Формируем текст-описание, используя итоговый $title
        $text = "*{$title}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";
        foreach ($resourcesAvailable as $res) {
            $req  = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$req} ед. "
                . "(в наличии {$have} ед., редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _170_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _~4–8 мин._\n\n"
            . "*Описание:* Компонент для создания изделий из стекла, применяется во многих сооружениях.\n\n";

        // 4) Формируем клавиатуру
        if ($maxCraftableItems < 1) {
            // Не хватает даже на 1 шт.
            $text .= "__Вы не можете крафтить, так как у вас недостаточно ресурсов даже на 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',  'callback_data' => 'buy'],
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('GlassBags'),
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

            // Добавим дополнительные кнопки
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        // 5) Отправляем сообщение
        $imagePath = base_url('uploads/telegram/craft/components/craftGlassBags.jpg');
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
     * Смотрим, сколько уже есть "Стеклопакетов" (GlassBags) у игрока в логе
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Предполагаем, что в CraftedItemsLogModel есть метод:
        // getItemByNameEngAndCharacterId($itemNameEng, $charId)
        // Возвращающий ['quantity' => ...] или null
        $itemRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $itemRow ? (int)$itemRow['quantity'] : 0;
    }

    /**
     * Определяем, на сколько шт. всего хватает игроку, исходя из ресурсов
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
                    'text'          => "🛠️ Крафт {$q} шт",
                    'callback_data' => "genericCraft_GlassBags_{$q}"
                ];
            }
        }
        return $buttons;
    }
}
